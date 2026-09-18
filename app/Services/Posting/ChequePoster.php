<?php

namespace App\Services\Posting;

use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Enums\ChequeStatus;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\UnbalancedJournalException;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\ChequeLine;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Currency\ExchangeRateService;
use App\Services\Reconciliation\BankReconciliationLockGuard;
use App\Services\Tax\TaxPeriodLockGuard;
use App\Support\Banking\BankLineMemo;
use App\Support\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a direct-expense cheque (no bill linkage) to the GL.
 *   DR  Expense (per-line, grouped by account + contact, gross-up non-recoverable tax)
 *   DR  Tax Payable (per-agency, recoverable tax = input tax credit)
 *   CR    Bank account
 *
 * To pay an existing bill instead, create a BillPayment with a payment_method_id
 * referencing a cheque-flagged PaymentMethod and the cheque number as reference —
 * that flow is already supported by BillPaymentPoster.
 */
class ChequePoster
{
    use Concerns\JoinsLineDescriptions;
    use Concerns\PlugsForeignRounding;
    use Concerns\SplitsLineTax;

    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
        protected AccountingAuditRecorder $auditRecorder,
        protected TaxPeriodLockGuard $taxLockGuard,
        protected ExchangeRateService $exchangeRates,
        protected BankReconciliationLockGuard $reconciliationLockGuard,
    ) {}

    public function post(Cheque $cheque): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($cheque) {
            $cheque->loadMissing('lines.taxCode.agency', 'lines.secondaryTaxCode.agency', 'bankAccount', 'company');

            if ($cheque->journal_entry_id) {
                throw AlreadyPostedException::for((int) $cheque->journal_entry_id);
            }

            if ($cheque->company->isLockedFor(CarbonImmutable::parse($cheque->cheque_date))) {
                throw PeriodLockedException::for(
                    CarbonImmutable::parse($cheque->cheque_date),
                    CarbonImmutable::parse($cheque->company->lock_date),
                );
            }

            $this->taxLockGuard->ensureNotFiled(
                (int) $cheque->company_id,
                $cheque->lines->pluck('tax_code_id')->all(),
                CarbonImmutable::parse($cheque->cheque_date),
            );

            $cheque->recalculateAmount();

            if ($cheque->lines->isEmpty() || $cheque->amount_cents <= 0) {
                throw new RuntimeException('Cheque has no lines or zero amount; cannot post.');
            }

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($cheque->company),
                'entry_date' => $cheque->cheque_date,
                'memo' => 'Cheque '.$cheque->cheque_no.' — '.$cheque->payee_name,
                'source_type' => Cheque::class,
                'source_id' => $cheque->id,
            ]);

            $this->buildChequeLines($cheque, $entry);

            $entry->refresh();
            $this->journalPoster->post($entry);

            $cheque->forceFill([
                'status' => ChequeStatus::Posted,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $entry->id,
            ])->save();

            // Any leg on an AR/AP control account moves a contact's sub-ledger,
            // whose cached balance is derived from the posted GL. Subsumes the old
            // refund-only case: a credit-memo refund cheque's single line IS the AR
            // leg. Must run after JournalPoster::post() flipped is_posted.
            $this->recomputeSubledgerBalances($cheque, $entry->lines);

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $cheque->company_id,
                AuditAction::ChequePosted,
                $cheque,
                [
                    'cheque_no' => $cheque->cheque_no,
                    'cheque_date' => optional($cheque->cheque_date)->toDateString(),
                    'payee_name' => $cheque->payee_name,
                    'amount_cents' => (int) $cheque->amount_cents,
                    'bank_account_id' => (int) $cheque->bank_account_id,
                    'journal_entry_id' => (int) $entry->id,
                    'journal' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    /**
     * Edit a posted cheque in place: rebuild its GL entry's lines on the same
     * journal entry (keeping the source link and cheque.journal_entry_id
     * intact), then recompute every touched account balance.
     *
     * Mirrors {@see DepositPoster::repost()}: JournalPoster::post() is not
     * re-run, so the period lock, tax-period lock and reconciliation lock are
     * all enforced explicitly here on both the original and the (possibly new)
     * cheque date.
     */
    public function repost(Cheque $cheque): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($cheque) {
            $cheque->loadMissing('lines.taxCode.agency', 'lines.secondaryTaxCode.agency', 'bankAccount', 'company', 'journalEntry.lines');

            if (! $cheque->journal_entry_id) {
                throw new RuntimeException('Cheque has not been posted yet — call post() instead.');
            }

            if ($cheque->status === ChequeStatus::Void) {
                throw new RuntimeException('Cannot repost a voided cheque.');
            }

            $entry = $cheque->journalEntry;
            $journalBefore = AccountingAuditRecorder::snapshotJournalEntry($entry);
            $lockDate = $cheque->company->lock_date;

            $originalEntryDate = CarbonImmutable::parse($entry->entry_date);
            $newEntryDate = CarbonImmutable::parse($cheque->cheque_date);

            foreach ([$originalEntryDate, $newEntryDate] as $date) {
                if ($cheque->company->isLockedFor($date)) {
                    throw PeriodLockedException::for($date, CarbonImmutable::parse($lockDate));
                }

                $this->taxLockGuard->ensureNotFiled(
                    (int) $cheque->company_id,
                    $cheque->lines->pluck('tax_code_id')->all(),
                    $date,
                );
            }

            $cheque->recalculateAmount();

            if ($cheque->lines->isEmpty() || $cheque->amount_cents <= 0) {
                throw new RuntimeException('Cheque has no lines or zero amount; cannot post.');
            }

            // Capture old + new touched accounts and guard the reconciliation
            // lock before mutating: the original date for the impact we're
            // removing, the new date for the impact we're writing.
            $oldAccountIds = $entry->lines->pluck('account_id')->all();

            // Keep the old line models too: a contact this edit REMOVES still needs
            // its cache corrected, and $entry->refresh() below swaps the relation
            // for the rebuilt lines.
            $oldLines = $entry->lines->all();

            $newAccountIds = collect([$cheque->bank_account_id])
                ->merge($cheque->lines->pluck('account_id'))
                ->merge(array_keys($this->recoverableTaxByPayableAccount($cheque)));

            $this->reconciliationLockGuard->ensureNotReconciled((int) $cheque->company_id, $oldAccountIds, $originalEntryDate);
            $this->reconciliationLockGuard->ensureNotReconciled((int) $cheque->company_id, $newAccountIds->all(), $newEntryDate);

            $entry->forceFill([
                'entry_date' => $cheque->cheque_date,
                'memo' => 'Cheque '.$cheque->cheque_no.' — '.$cheque->payee_name,
            ])->save();

            $entry->lines()->delete();

            $this->buildChequeLines($cheque, $entry);

            $entry->refresh();

            if (! $entry->isBalanced()) {
                throw UnbalancedJournalException::from($entry->totalDebitsCents(), $entry->totalCreditsCents());
            }

            foreach (array_unique([...$oldAccountIds, ...$entry->lines->pluck('account_id')->all()]) as $id) {
                Account::withoutGlobalScopes()->find($id)?->recomputeBalance();
            }

            $this->recomputeSubledgerBalances($cheque, [...$oldLines, ...$entry->lines->all()]);

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $cheque->company_id,
                AuditAction::ChequeReposted,
                $cheque,
                [
                    'cheque_no' => $cheque->cheque_no,
                    'cheque_date' => optional($cheque->cheque_date)->toDateString(),
                    'payee_name' => $cheque->payee_name,
                    'amount_cents' => (int) $cheque->amount_cents,
                    'bank_account_id' => (int) $cheque->bank_account_id,
                    'journal_entry_id' => (int) $entry->id,
                    'journal_before' => $journalBefore,
                    'journal_after' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    public function void(Cheque $cheque, ?CarbonImmutable $voidDate = null): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($cheque, $voidDate) {
            $cheque->loadMissing('journalEntry.lines');

            if (! $cheque->journal_entry_id) {
                throw new RuntimeException('Cheque is not posted.');
            }

            if ($cheque->status === ChequeStatus::Void) {
                throw new RuntimeException('Cheque is already voided.');
            }

            $this->journalPoster->void($cheque->journalEntry, $voidDate, "Void of cheque {$cheque->cheque_no}");

            $cheque->forceFill([
                'status' => ChequeStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
            ])->save();

            // JournalPoster::void() copies contact_id onto each reversing line and
            // posts it, so the reversal's contacts are the original's — recompute
            // from those, now that the reversal is on the books.
            $this->recomputeSubledgerBalances($cheque, $cheque->journalEntry->lines);

            $this->auditRecorder->record(
                (int) $cheque->company_id,
                AuditAction::ChequeVoided,
                $cheque,
                [
                    'cheque_no' => $cheque->cheque_no,
                    'voided_at' => optional($cheque->voided_at)->format('Y-m-d H:i:s.u'),
                    'journal_entry_id' => (int) $cheque->journal_entry_id,
                ],
                $cheque->journalEntry,
            );
        }));
    }

    /**
     * Write the expense + recoverable-tax debits and the bank credit. The cheque's
     * currency is its bank account's currency: a foreign bank converts every leg to
     * home cents at the cheque-date rate and carries the foreign amount as a memo,
     * with any rounding plugged onto the largest debit leg.
     */
    protected function buildChequeLines(Cheque $cheque, JournalEntry $entry): void
    {
        $bankCurrency = $cheque->bankAccount?->currency_code;
        $isForeign = $bankCurrency !== null && ! $cheque->company->isHomeCurrency($bankCurrency);
        $currency = $isForeign ? mb_strtoupper((string) $bankCurrency) : null;
        $rate = $isForeign ? $this->lockRate($cheque) : '1';

        $totalForeign = (int) $cheque->amount_cents;
        $bankHome = Currency::toHomeCents($totalForeign, $rate);

        /** @var list<array{account_id: int, class_id: ?int, location_id: ?int, contact_id: ?int, foreign: int, home: int, memo: ?string}> $legs */
        $legs = [];

        foreach ($this->expenseByAccount($cheque) as $expense) {
            $legs[] = ['account_id' => $expense['account_id'], 'class_id' => $expense['class_id'], 'location_id' => $expense['location_id'], 'contact_id' => $expense['contact_id'], 'foreign' => $expense['cents'], 'home' => Currency::toHomeCents($expense['cents'], $rate), 'memo' => $this->descriptionMemo($expense['descriptions'])];
        }

        foreach ($this->recoverableTaxByPayableAccount($cheque) as $payableAccountId => $foreignCents) {
            if ($foreignCents === 0) {
                continue;
            }

            // Input tax credit is a system/aggregate leg — never dimension-tagged.
            // It is owed to the tax agency, not to a line's customer/vendor, so it
            // carries the cheque's payee like the bank leg does.
            $legs[] = ['account_id' => $payableAccountId, 'class_id' => null, 'location_id' => null, 'contact_id' => $cheque->payee_contact_id === null ? null : (int) $cheque->payee_contact_id, 'foreign' => $foreignCents, 'home' => Currency::toHomeCents($foreignCents, $rate), 'memo' => 'Input tax credit'];
        }

        $this->applyRoundingPlug($legs, $bankHome);

        $order = 0;

        foreach ($legs as $leg) {
            $entry->lines()->create([
                'account_id' => $leg['account_id'],
                'debit_cents' => $leg['home'],
                'credit_cents' => 0,
                'memo' => $leg['memo'],
                'contact_id' => $leg['contact_id'],
                'line_order' => $order++,
                'class_id' => $leg['class_id'],
                'location_id' => $leg['location_id'],
                ...Currency::lineMemo($currency, $rate, $leg['foreign'], 0),
            ]);
        }

        $entry->lines()->create([
            'account_id' => $cheque->bank_account_id,
            'debit_cents' => 0,
            'credit_cents' => $bankHome,
            'memo' => BankLineMemo::forSource($cheque),
            'contact_id' => $cheque->payee_contact_id,
            'line_order' => $order++,
            ...Currency::lineMemo($currency, $rate, 0, $totalForeign),
        ]);

        if ($isForeign) {
            $cheque->forceFill(['fx_rate' => $rate, 'home_amount_cents' => $bankHome])->save();
        }
    }

    /**
     * Recompute the cached AR/AP balance of every contact stamped on one of the
     * given lines' control-account legs.
     *
     * Those caches ({@see Contact::recomputeArBalance()}) are derived from the
     * posted GL, so they must be refreshed after any post / repost / void that
     * moves a contact's sub-ledger — including for a contact an edit REMOVED,
     * which is why repost() passes the old and rebuilt lines together.
     *
     * @param  iterable<JournalLine>  $lines
     */
    protected function recomputeSubledgerBalances(Cheque $cheque, iterable $lines): void
    {
        $stamped = collect($lines)->filter(fn (JournalLine $line): bool => $line->contact_id !== null);

        $sides = $this->subledgerAccountSides(
            $cheque,
            $stamped->pluck('account_id')->map(fn ($id): int => (int) $id)->unique()->values()->all(),
        );

        if ($sides === []) {
            return;
        }

        /** @var array<string, array{0: string, 1: int}> $work */
        $work = [];

        foreach ($stamped as $line) {
            $side = $sides[(int) $line->account_id] ?? null;

            if ($side !== null) {
                $work[$side.':'.(int) $line->contact_id] = [$side, (int) $line->contact_id];
            }
        }

        foreach ($work as [$side, $contactId]) {
            $contact = Contact::withoutGlobalScopes()
                ->where('company_id', $cheque->company_id)
                ->find($contactId);

            if ($contact === null) {
                continue;
            }

            $side === 'ar' ? $contact->recomputeArBalance() : $contact->recomputeApBalance();
        }
    }

    /**
     * Which of the given accounts are AR / AP control accounts. Matched by
     * subtype across every currency — the same definition
     * {@see Contact::recomputeArBalance()} sums over, so the cache and the
     * trigger to refresh it can never disagree.
     *
     * @param  list<int>  $accountIds
     * @return array<int, string> account id => 'ar'|'ap'
     */
    protected function subledgerAccountSides(Cheque $cheque, array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $sides = [];

        foreach (['ar' => AccountSubtype::AccountsReceivable, 'ap' => AccountSubtype::AccountsPayable] as $side => $subtype) {
            $ids = Account::withoutGlobalScopes()
                ->where('company_id', $cheque->company_id)
                ->where('subtype', $subtype->value)
                ->whereIn('id', $accountIds)
                ->pluck('id');

            foreach ($ids as $id) {
                $sides[(int) $id] = $side;
            }
        }

        return $sides;
    }

    protected function lockRate(Cheque $cheque): string
    {
        if ($cheque->fx_rate !== null) {
            return (string) $cheque->fx_rate;
        }

        $rate = $this->exchangeRates->rateFor(
            $cheque->company,
            (string) $cheque->bankAccount?->currency_code,
            CarbonImmutable::parse($cheque->cheque_date),
        );

        $cheque->forceFill(['fx_rate' => $rate])->save();

        return $rate;
    }

    /**
     * One leg per account + dimensions + contact, with the descriptions of the
     * lines folded into it for the leg's memo.
     *
     * @return list<array{account_id: int, class_id: ?int, location_id: ?int, contact_id: ?int, cents: int, descriptions: list<?string>}>
     */
    protected function expenseByAccount(Cheque $cheque): array
    {
        $grouped = [];

        foreach ($cheque->lines as $line) {
            $cents = (int) $line->amount_cents;

            // Non-recoverable tax: add to expense (gross-up). Each of the line's
            // taxes is grossed up only if it is itself non-recoverable.
            $cents += $this->nonRecoverableTax([
                [$line->taxCode, (int) $line->tax_cents],
                [$line->secondaryTaxCode, (int) $line->secondary_tax_cents],
            ]);

            // Resolve the contact BEFORE keying, never after: a line naming the payee
            // explicitly and a line falling back to it must land on the SAME leg, or
            // the entry grows two rows identical in every posted column.
            $contactId = $this->lineContactId($cheque, $line);

            $key = $line->account_id.':'.($line->class_id ?? '').':'.($line->location_id ?? '').':'.($contactId ?? '');
            $grouped[$key] ??= [
                'account_id' => (int) $line->account_id,
                'class_id' => $line->class_id,
                'location_id' => $line->location_id,
                'contact_id' => $contactId,
                'cents' => 0,
                'descriptions' => [],
            ];
            $grouped[$key]['cents'] += $cents;
            $grouped[$key]['descriptions'][] = $line->description;
        }

        return array_values($grouped);
    }

    /**
     * The counterparty for one cheque line: its own customer / vendor when the
     * line is coded to an AR/AP control account, else the cheque's payee. The
     * two differ whenever the cheque is made out to someone other than the
     * person whose balance it settles — refunding a beneficiary against a
     * customer's account, say.
     */
    protected function lineContactId(Cheque $cheque, ChequeLine $line): ?int
    {
        $contactId = $line->contact_id ?: $cheque->payee_contact_id;

        return $contactId === null ? null : (int) $contactId;
    }

    /**
     * @return array<int, int>
     */
    protected function recoverableTaxByPayableAccount(Cheque $cheque): array
    {
        $grouped = [];

        foreach ($cheque->lines as $line) {
            $this->addTaxesByPayable($grouped, [
                [$line->taxCode, (int) $line->tax_cents],
                [$line->secondaryTaxCode, (int) $line->secondary_tax_cents],
            ], recoverableOnly: true);
        }

        return $grouped;
    }
}
