<?php

namespace App\Services\Posting;

use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Enums\CreditMemoStatus;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\UnbalancedJournalException;
use App\Models\Account;
use App\Models\CreditMemo;
use App\Models\JournalEntry;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Currency\ExchangeRateService;
use App\Services\Tax\TaxPeriodLockGuard;
use App\Support\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a customer credit memo to the GL — the mirror of an invoice.
 *   DR  Income (per-account, grouped)        line_subtotal
 *   DR  Tax Payable (per-agency, grouped)    line_tax
 *   CR    Accounts Receivable                total
 */
class CreditMemoPoster
{
    use Concerns\PlugsForeignRounding;
    use Concerns\SplitsLineTax;

    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
        protected AccountingAuditRecorder $auditRecorder,
        protected TaxPeriodLockGuard $taxLockGuard,
        protected ControlAccountResolver $controlAccounts,
        protected ExchangeRateService $exchangeRates,
        protected InvoiceReconciler $invoiceReconciler,
    ) {}

    public function post(CreditMemo $memo): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($memo) {
            $memo->loadMissing('lines.taxCode.agency', 'lines.secondaryTaxCode.agency', 'company');

            if ($memo->journal_entry_id) {
                throw AlreadyPostedException::for((int) $memo->journal_entry_id);
            }

            if ($memo->company->isLockedFor(CarbonImmutable::parse($memo->credit_memo_date))) {
                throw PeriodLockedException::for(
                    CarbonImmutable::parse($memo->credit_memo_date),
                    CarbonImmutable::parse($memo->company->lock_date),
                );
            }

            $this->taxLockGuard->ensureNotFiled(
                (int) $memo->company_id,
                $memo->lines->pluck('tax_code_id')->all(),
                CarbonImmutable::parse($memo->credit_memo_date),
            );

            $memo->recalculateTotals();

            if ($memo->lines->isEmpty() || $memo->total_cents <= 0) {
                throw new RuntimeException('Credit memo has no lines or zero total; cannot post.');
            }

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($memo->company),
                'entry_date' => $memo->credit_memo_date,
                'memo' => 'Credit memo '.$memo->credit_memo_no.' — '.$memo->contact->display_name,
                'source_type' => CreditMemo::class,
                'source_id' => $memo->id,
            ]);

            $this->writeRevenueAndArLines($memo, $entry, 0);

            $entry->refresh();
            $this->journalPoster->post($entry);

            $memo->forceFill([
                'status' => CreditMemoStatus::Posted,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $entry->id,
            ])->save();

            $memo->contact->recomputeArBalance();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $memo->company_id,
                AuditAction::CreditMemoPosted,
                $memo,
                [
                    'credit_memo_no' => $memo->credit_memo_no,
                    'credit_memo_date' => optional($memo->credit_memo_date)->toDateString(),
                    'total_cents' => (int) $memo->total_cents,
                    'subtotal_cents' => (int) $memo->subtotal_cents,
                    'tax_cents' => (int) $memo->tax_cents,
                    'contact_id' => (int) $memo->contact_id,
                    'journal_entry_id' => (int) $entry->id,
                    'journal' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    /**
     * Re-post a posted credit memo in place after the user edits it.
     */
    public function repost(CreditMemo $memo): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($memo) {
            $memo->loadMissing('lines.taxCode.agency', 'lines.secondaryTaxCode.agency', 'company', 'journalEntry.lines', 'contact');

            if (! $memo->journal_entry_id) {
                throw new RuntimeException('Credit memo has not been posted yet — call post() instead.');
            }

            if ($memo->status === CreditMemoStatus::Void) {
                throw new RuntimeException('Cannot repost a voided credit memo.');
            }

            $entry = $memo->journalEntry;
            $journalBefore = AccountingAuditRecorder::snapshotJournalEntry($entry);
            $lockDate = $memo->company->lock_date;

            $originalEntryDate = CarbonImmutable::parse($entry->entry_date);
            $newEntryDate = CarbonImmutable::parse($memo->credit_memo_date);

            if ($memo->company->isLockedFor($originalEntryDate)) {
                throw PeriodLockedException::for($originalEntryDate, CarbonImmutable::parse($lockDate));
            }

            if ($memo->company->isLockedFor($newEntryDate)) {
                throw PeriodLockedException::for($newEntryDate, CarbonImmutable::parse($lockDate));
            }

            $taxCodeIds = $memo->lines->pluck('tax_code_id')->merge(
                $entry->lines->pluck('tax_code_id')
            )->all();

            $this->taxLockGuard->ensureNotFiled((int) $memo->company_id, $taxCodeIds, $originalEntryDate);
            $this->taxLockGuard->ensureNotFiled((int) $memo->company_id, $taxCodeIds, $newEntryDate);

            $memo->recalculateTotals();

            if ($memo->lines->isEmpty() || $memo->total_cents <= 0) {
                throw new RuntimeException('Credit memo has no lines or zero total; cannot repost.');
            }

            $oldAccountIds = $entry->lines->pluck('account_id')->all();

            $entry->forceFill([
                'entry_date' => $memo->credit_memo_date,
                'memo' => 'Credit memo '.$memo->credit_memo_no.' — '.$memo->contact->display_name,
            ])->save();

            $entry->lines()->delete();

            $this->writeRevenueAndArLines($memo, $entry, 0);

            $entry->refresh();

            if (! $entry->isBalanced()) {
                throw UnbalancedJournalException::from(
                    $entry->totalDebitsCents(),
                    $entry->totalCreditsCents(),
                );
            }

            $newAccountIds = $entry->lines->pluck('account_id')->all();
            foreach (array_unique(array_merge($oldAccountIds, $newAccountIds)) as $id) {
                Account::withoutGlobalScopes()->find($id)?->recomputeBalance();
            }

            $memo->contact->recomputeArBalance();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $memo->company_id,
                AuditAction::CreditMemoReposted,
                $memo,
                [
                    'credit_memo_no' => $memo->credit_memo_no,
                    'total_cents' => (int) $memo->total_cents,
                    'journal_before' => $journalBefore,
                    'journal_after' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    /**
     * Void: write reversing JE, mark credit memo voided.
     */
    public function void(CreditMemo $memo, ?CarbonImmutable $voidDate = null): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($memo, $voidDate) {
            $memo->loadMissing('journalEntry');

            if (! $memo->journal_entry_id) {
                throw new RuntimeException('Credit memo is not posted.');
            }

            if ($memo->status === CreditMemoStatus::Void) {
                throw new RuntimeException('Credit memo is already voided.');
            }

            $this->journalPoster->void($memo->journalEntry, $voidDate, "Void of credit memo {$memo->credit_memo_no}");

            $memo->forceFill([
                'status' => CreditMemoStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
            ])->save();

            // Voiding restores the customer's GL AR. If this credit had been applied to
            // an invoice via "Close settled balance", release that reconciliation so the
            // invoice re-opens instead of showing a stale, lower balance.
            if ($memo->contact_id !== null) {
                $this->invoiceReconciler->releaseExcessReconciliation($memo->company, (int) $memo->contact_id);
            }

            $memo->contact->recomputeArBalance();

            $this->auditRecorder->record(
                (int) $memo->company_id,
                AuditAction::CreditMemoVoided,
                $memo,
                [
                    'credit_memo_no' => $memo->credit_memo_no,
                    'voided_at' => optional($memo->voided_at)->format('Y-m-d H:i:s.u'),
                    'journal_entry_id' => (int) $memo->journal_entry_id,
                ],
                $memo->journalEntry,
            );
        }));
    }

    /**
     * Write the revenue + tax debits and the AR credit (the mirror of an invoice),
     * converting to home cents for a foreign credit memo and carrying the foreign
     * amount as a memo. Rounding between the converted legs and the converted total
     * is plugged onto the largest debit leg so the entry balances in home cents.
     */
    protected function writeRevenueAndArLines(CreditMemo $memo, JournalEntry $entry, int $order): int
    {
        $isForeign = $memo->isForeignCurrency();
        $currency = $isForeign ? mb_strtoupper((string) $memo->currency_code) : null;
        $rate = $isForeign ? $this->lockRate($memo) : '1';

        $ar = $this->controlAccounts->resolve($memo->company, AccountSubtype::AccountsReceivable, $memo->currency_code);

        $totalForeign = (int) $memo->total_cents;
        $arHome = Currency::toHomeCents($totalForeign, $rate);

        /** @var list<array{account_id: int, class_id: ?int, location_id: ?int, foreign: int, home: int, memo: ?string}> $legs */
        $legs = [];

        foreach ($this->incomeByAccount($memo) as $income) {
            $legs[] = ['account_id' => $income['account_id'], 'class_id' => $income['class_id'], 'location_id' => $income['location_id'], 'foreign' => $income['cents'], 'home' => Currency::toHomeCents($income['cents'], $rate), 'memo' => null];
        }

        foreach ($this->taxByAgencyPayableAccount($memo) as $payableAccountId => $foreignCents) {
            if ($foreignCents === 0) {
                continue;
            }

            // Tax payable is a system/aggregate leg — never dimension-tagged.
            $legs[] = ['account_id' => $payableAccountId, 'class_id' => null, 'location_id' => null, 'foreign' => $foreignCents, 'home' => Currency::toHomeCents($foreignCents, $rate), 'memo' => 'Sales tax reversal'];
        }

        $this->applyRoundingPlug($legs, $arHome);

        foreach ($legs as $leg) {
            $entry->lines()->create([
                'account_id' => $leg['account_id'],
                'debit_cents' => $leg['home'],
                'credit_cents' => 0,
                'memo' => $leg['memo'],
                'line_order' => $order++,
                'class_id' => $leg['class_id'],
                'location_id' => $leg['location_id'],
                ...Currency::lineMemo($currency, $rate, $leg['foreign'], 0),
            ]);
        }

        $entry->lines()->create([
            'account_id' => $ar->id,
            'debit_cents' => 0,
            'credit_cents' => $arHome,
            'memo' => 'AR — '.$memo->contact->display_name,
            'contact_id' => $memo->contact_id,
            'line_order' => $order++,
            ...Currency::lineMemo($currency, $rate, 0, $totalForeign),
        ]);

        if ($isForeign) {
            $memo->forceFill(['fx_rate' => $rate, 'home_total_cents' => $arHome])->save();
        }

        return $order;
    }

    /**
     * Lock the credit memo's exchange rate (reused on repost), persisting it.
     */
    protected function lockRate(CreditMemo $memo): string
    {
        if ($memo->fx_rate !== null) {
            return (string) $memo->fx_rate;
        }

        $rate = $this->exchangeRates->rateFor(
            $memo->company,
            (string) $memo->currency_code,
            CarbonImmutable::parse($memo->credit_memo_date),
        );

        $memo->forceFill(['fx_rate' => $rate])->save();

        return $rate;
    }

    /**
     * Revenue grouped by the composite (account, class, location); collapses to the
     * account when no dimensions are set, preserving pre-dimension grouping.
     *
     * @return list<array{account_id: int, class_id: ?int, location_id: ?int, cents: int}>
     */
    protected function incomeByAccount(CreditMemo $memo): array
    {
        $grouped = [];

        foreach ($memo->lines as $line) {
            $key = $line->account_id.':'.($line->class_id ?? '').':'.($line->location_id ?? '');
            $grouped[$key] ??= [
                'account_id' => (int) $line->account_id,
                'class_id' => $line->class_id,
                'location_id' => $line->location_id,
                'cents' => 0,
            ];
            $grouped[$key]['cents'] += (int) $line->line_subtotal_cents;
        }

        return array_values($grouped);
    }

    /**
     * @return array<int, int>
     */
    protected function taxByAgencyPayableAccount(CreditMemo $memo): array
    {
        $grouped = [];

        foreach ($memo->lines as $line) {
            $this->addTaxesByPayable($grouped, [
                [$line->taxCode, (int) $line->line_tax_cents],
                [$line->secondaryTaxCode, (int) $line->secondary_tax_cents],
            ]);
        }

        return $grouped;
    }
}
