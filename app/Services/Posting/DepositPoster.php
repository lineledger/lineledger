<?php

namespace App\Services\Posting;

use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Enums\DepositStatus;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\UnbalancedJournalException;
use App\Models\Account;
use App\Models\Deposit;
use App\Models\JournalEntry;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Currency\ExchangeRateService;
use App\Services\Reconciliation\BankReconciliationLockGuard;
use App\Support\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a deposit slip to the GL.
 *   DR  Bank account (total deposit)
 *   CR    Undeposited Funds (for receipt-source lines — moves $ from Undep → Bank)
 *   CR    Other account (for "other" deposit lines — e.g. owner contribution, refund received)
 *
 * Receipt-source lines mark the underlying CustomerReceipt as deposited
 * by linking through deposit_lines.customer_receipt_id, so they won't
 * appear in subsequent deposit pickers.
 */
class DepositPoster
{
    use Concerns\PlugsForeignRounding;

    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
        protected AccountingAuditRecorder $auditRecorder,
        protected ExchangeRateService $exchangeRates,
        protected BankReconciliationLockGuard $reconciliationLockGuard,
    ) {}

    public function post(Deposit $deposit): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($deposit) {
            $deposit->loadMissing('lines.customerReceipt', 'lines.salesReceipt', 'bankAccount', 'company');

            if ($deposit->journal_entry_id) {
                throw AlreadyPostedException::for((int) $deposit->journal_entry_id);
            }

            if ($deposit->company->isLockedFor(CarbonImmutable::parse($deposit->deposit_date))) {
                throw PeriodLockedException::for(
                    CarbonImmutable::parse($deposit->deposit_date),
                    CarbonImmutable::parse($deposit->company->lock_date),
                );
            }

            $deposit->recalculateAmount();

            if ($deposit->lines->isEmpty() || $deposit->amount_cents <= 0) {
                throw new RuntimeException('Deposit has no lines or zero amount.');
            }

            $undeposited = $this->undepositedFundsAccount($deposit);

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($deposit->company),
                'entry_date' => $deposit->deposit_date,
                'memo' => 'Deposit '.$deposit->deposit_no,
                'source_type' => Deposit::class,
                'source_id' => $deposit->id,
            ]);

            $this->buildDepositLines($deposit, $entry, $undeposited);

            $entry->refresh();
            $this->journalPoster->post($entry);

            $deposit->forceFill([
                'status' => DepositStatus::Posted,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $entry->id,
            ])->save();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $deposit->company_id,
                AuditAction::DepositPosted,
                $deposit,
                [
                    'deposit_no' => $deposit->deposit_no,
                    'deposit_date' => optional($deposit->deposit_date)->toDateString(),
                    'amount_cents' => (int) $deposit->amount_cents,
                    'bank_account_id' => (int) $deposit->bank_account_id,
                    'journal_entry_id' => (int) $entry->id,
                    'journal' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    /**
     * Edit a posted deposit in place: rebuild its GL entry's lines on the same
     * journal entry (keeping the source link and deposit.journal_entry_id intact),
     * then recompute every touched account balance.
     *
     * Use this when the user edits a posted deposit without the void+recreate
     * ceremony. Because a deposit moves money in and out of the bank account, the
     * reconciliation lock is enforced explicitly here on both the original and the
     * (possibly new) deposit date — JournalPoster::post() is not re-run, so its
     * guard would otherwise be bypassed. The lock date is respected on both dates too.
     */
    public function repost(Deposit $deposit): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($deposit) {
            $deposit->loadMissing('lines.customerReceipt', 'lines.salesReceipt', 'bankAccount', 'company', 'journalEntry.lines');

            if (! $deposit->journal_entry_id) {
                throw new RuntimeException('Deposit has not been posted yet — call post() instead.');
            }

            if ($deposit->status === DepositStatus::Void) {
                throw new RuntimeException('Cannot repost a voided deposit.');
            }

            $entry = $deposit->journalEntry;
            $journalBefore = AccountingAuditRecorder::snapshotJournalEntry($entry);
            $lockDate = $deposit->company->lock_date;

            $originalEntryDate = CarbonImmutable::parse($entry->entry_date);
            $newEntryDate = CarbonImmutable::parse($deposit->deposit_date);

            foreach ([$originalEntryDate, $newEntryDate] as $date) {
                if ($deposit->company->isLockedFor($date)) {
                    throw PeriodLockedException::for($date, CarbonImmutable::parse($lockDate));
                }
            }

            $deposit->recalculateAmount();

            if ($deposit->lines->isEmpty() || $deposit->amount_cents <= 0) {
                throw new RuntimeException('Deposit has no lines or zero amount.');
            }

            $undeposited = $this->undepositedFundsAccount($deposit);

            // Capture old + new touched accounts and guard the reconciliation lock
            // before mutating: the original date for the impact we're removing, the
            // new date for the impact we're writing.
            $oldAccountIds = $entry->lines->pluck('account_id')->all();

            $newAccountIds = collect([$deposit->bank_account_id]);
            foreach ($deposit->lines as $line) {
                $newAccountIds->push($line->isReceiptSource() ? $undeposited->id : $line->account_id);
            }

            $this->reconciliationLockGuard->ensureNotReconciled((int) $deposit->company_id, $oldAccountIds, $originalEntryDate);
            $this->reconciliationLockGuard->ensureNotReconciled((int) $deposit->company_id, $newAccountIds->all(), $newEntryDate);

            $entry->forceFill([
                'entry_date' => $deposit->deposit_date,
                'memo' => 'Deposit '.$deposit->deposit_no,
            ])->save();

            $entry->lines()->delete();

            $this->buildDepositLines($deposit, $entry, $undeposited);

            $entry->refresh();

            if (! $entry->isBalanced()) {
                throw UnbalancedJournalException::from($entry->totalDebitsCents(), $entry->totalCreditsCents());
            }

            foreach (array_unique([...$oldAccountIds, ...$entry->lines->pluck('account_id')->all()]) as $id) {
                Account::withoutGlobalScopes()->find($id)?->recomputeBalance();
            }

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $deposit->company_id,
                AuditAction::DepositReposted,
                $deposit,
                [
                    'deposit_no' => $deposit->deposit_no,
                    'deposit_date' => optional($deposit->deposit_date)->toDateString(),
                    'amount_cents' => (int) $deposit->amount_cents,
                    'bank_account_id' => (int) $deposit->bank_account_id,
                    'journal_entry_id' => (int) $entry->id,
                    'journal_before' => $journalBefore,
                    'journal_after' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    public function void(Deposit $deposit, ?CarbonImmutable $voidDate = null): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($deposit, $voidDate) {
            $deposit->loadMissing('journalEntry');

            if (! $deposit->journal_entry_id) {
                throw new RuntimeException('Deposit is not posted.');
            }

            if ($deposit->status === DepositStatus::Void) {
                throw new RuntimeException('Deposit is already voided.');
            }

            $this->journalPoster->void($deposit->journalEntry, $voidDate, "Void of deposit {$deposit->deposit_no}");

            $deposit->forceFill([
                'status' => DepositStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
            ])->save();

            $this->auditRecorder->record(
                (int) $deposit->company_id,
                AuditAction::DepositVoided,
                $deposit,
                [
                    'deposit_no' => $deposit->deposit_no,
                    'voided_at' => optional($deposit->voided_at)->format('Y-m-d H:i:s.u'),
                    'journal_entry_id' => (int) $deposit->journal_entry_id,
                ],
                $deposit->journalEntry,
            );
        }));
    }

    /**
     * Debit the bank for the deposit total and credit each line's source. A
     * deposit into a foreign bank converts every leg to home cents at the
     * deposit-date rate, carrying the foreign amount as a memo. Receipt-source
     * lines clear Undeposited Funds, a home-currency clearing account, so they are
     * not allowed on a foreign deposit — foreign receipts deposit straight to the
     * bank instead.
     */
    protected function buildDepositLines(Deposit $deposit, JournalEntry $entry, Account $undeposited): void
    {
        $bankCurrency = $deposit->bankAccount?->currency_code;
        $isForeign = $bankCurrency !== null && ! $deposit->company->isHomeCurrency($bankCurrency);
        $currency = $isForeign ? mb_strtoupper((string) $bankCurrency) : null;
        $rate = $isForeign ? $this->lockRate($deposit) : '1';

        $totalForeign = (int) $deposit->amount_cents;
        $bankHome = Currency::toHomeCents($totalForeign, $rate);

        /** @var list<array{account_id: int, foreign: int, home: int, memo: ?string, contact_id: ?int}> $legs */
        $legs = [];

        foreach ($deposit->lines as $line) {
            if ($line->isReceiptSource() && $isForeign) {
                throw new RuntimeException('A foreign-currency deposit cannot batch Undeposited Funds receipts; deposit foreign receipts directly to the bank.');
            }

            $creditAccountId = $line->isReceiptSource()
                ? $undeposited->id
                : $line->account_id;

            if (! $creditAccountId) {
                throw new RuntimeException('Deposit line is missing an account.');
            }

            $foreign = (int) $line->amount_cents;
            $legs[] = [
                'account_id' => $creditAccountId,
                'foreign' => $foreign,
                'home' => Currency::toHomeCents($foreign, $rate),
                'memo' => $line->description,
                'contact_id' => $line->contact_id,
            ];
        }

        $this->applyRoundingPlug($legs, $bankHome);

        $order = 0;

        $entry->lines()->create([
            'account_id' => $deposit->bank_account_id,
            'debit_cents' => $bankHome,
            'credit_cents' => 0,
            'memo' => 'Deposit',
            'line_order' => $order++,
            ...Currency::lineMemo($currency, $rate, $totalForeign, 0),
        ]);

        foreach ($legs as $leg) {
            // A negative "other" line is a fee/deduction (e.g. a merchant charge):
            // it posts as a DEBIT to its account so only the net hits the bank.
            $home = (int) $leg['home'];
            $foreign = (int) $leg['foreign'];

            $entry->lines()->create([
                'account_id' => $leg['account_id'],
                'debit_cents' => max(-$home, 0),
                'credit_cents' => max($home, 0),
                'memo' => $leg['memo'],
                'contact_id' => $leg['contact_id'],
                'line_order' => $order++,
                ...Currency::lineMemo($currency, $rate, max(-$foreign, 0), max($foreign, 0)),
            ]);
        }

        if ($isForeign) {
            $deposit->forceFill(['fx_rate' => $rate, 'home_amount_cents' => $bankHome])->save();
        }
    }

    protected function lockRate(Deposit $deposit): string
    {
        if ($deposit->fx_rate !== null) {
            return (string) $deposit->fx_rate;
        }

        $rate = $this->exchangeRates->rateFor(
            $deposit->company,
            (string) $deposit->bankAccount?->currency_code,
            CarbonImmutable::parse($deposit->deposit_date),
        );

        $deposit->forceFill(['fx_rate' => $rate])->save();

        return $rate;
    }

    protected function undepositedFundsAccount(Deposit $deposit): Account
    {
        $account = Account::withoutGlobalScopes()
            ->where('company_id', $deposit->company_id)
            ->where('subtype', AccountSubtype::UndepositedFunds->value)
            ->where('is_system', true)
            ->first();

        if (! $account) {
            throw new RuntimeException('Missing system Undeposited Funds account.');
        }

        return $account;
    }
}
