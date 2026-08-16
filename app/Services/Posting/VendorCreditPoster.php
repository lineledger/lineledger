<?php

namespace App\Services\Posting;

use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Enums\VendorCreditStatus;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\UnbalancedJournalException;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\VendorCredit;
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
 * Posts a vendor credit to the GL — the reverse of a bill (and the AP mirror of a
 * customer credit memo).
 *   DR  Accounts Payable                       total
 *   CR    Expense (per-account, grouped)        line_subtotal
 *   CR    Tax Payable (per-agency, recoverable) line_tax     (reverses input tax credit)
 *
 * GL-netted: no per-bill application is recorded. Because the credit debits the AP
 * control account tagged to the vendor, the GL-driven AP aging and the BillReconciler
 * both pick it up automatically, lowering what the company owes that vendor.
 */
class VendorCreditPoster
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
        protected BillReconciler $billReconciler,
    ) {}

    public function post(VendorCredit $credit): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($credit) {
            $credit->loadMissing('lines.taxCode.agency', 'lines.secondaryTaxCode.agency', 'contact', 'company');

            if ($credit->journal_entry_id) {
                throw AlreadyPostedException::for((int) $credit->journal_entry_id);
            }

            if ($credit->company->isLockedFor(CarbonImmutable::parse($credit->vendor_credit_date))) {
                throw PeriodLockedException::for(
                    CarbonImmutable::parse($credit->vendor_credit_date),
                    CarbonImmutable::parse($credit->company->lock_date),
                );
            }

            $this->taxLockGuard->ensureNotFiled(
                (int) $credit->company_id,
                $credit->lines->pluck('tax_code_id')->all(),
                CarbonImmutable::parse($credit->vendor_credit_date),
            );

            $credit->recalculateTotals();

            if ($credit->lines->isEmpty() || $credit->total_cents <= 0) {
                throw new RuntimeException('Vendor credit has no lines or zero total; cannot post.');
            }

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($credit->company),
                'entry_date' => $credit->vendor_credit_date,
                'memo' => 'Vendor credit '.$credit->vendor_credit_no.' — '.$credit->contact->display_name,
                'source_type' => VendorCredit::class,
                'source_id' => $credit->id,
            ]);

            $this->writeExpenseAndApLines($credit, $entry, 0);

            $entry->refresh();
            $this->journalPoster->post($entry);

            $credit->forceFill([
                'status' => VendorCreditStatus::Posted,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $entry->id,
            ])->save();

            $credit->contact->recomputeApBalance();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $credit->company_id,
                AuditAction::VendorCreditPosted,
                $credit,
                [
                    'vendor_credit_no' => $credit->vendor_credit_no,
                    'vendor_credit_date' => optional($credit->vendor_credit_date)->toDateString(),
                    'total_cents' => (int) $credit->total_cents,
                    'subtotal_cents' => (int) $credit->subtotal_cents,
                    'tax_cents' => (int) $credit->tax_cents,
                    'contact_id' => (int) $credit->contact_id,
                    'journal_entry_id' => (int) $entry->id,
                    'journal' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    /**
     * Re-post a posted vendor credit in place after the user edits it.
     */
    public function repost(VendorCredit $credit): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($credit) {
            $credit->loadMissing('lines.taxCode.agency', 'lines.secondaryTaxCode.agency', 'company', 'journalEntry.lines', 'contact');

            if (! $credit->journal_entry_id) {
                throw new RuntimeException('Vendor credit has not been posted yet — call post() instead.');
            }

            if ($credit->status === VendorCreditStatus::Void) {
                throw new RuntimeException('Cannot repost a voided vendor credit.');
            }

            $entry = $credit->journalEntry;
            $journalBefore = AccountingAuditRecorder::snapshotJournalEntry($entry);
            $lockDate = $credit->company->lock_date;

            $originalEntryDate = CarbonImmutable::parse($entry->entry_date);
            $newEntryDate = CarbonImmutable::parse($credit->vendor_credit_date);

            if ($credit->company->isLockedFor($originalEntryDate)) {
                throw PeriodLockedException::for($originalEntryDate, CarbonImmutable::parse($lockDate));
            }

            if ($credit->company->isLockedFor($newEntryDate)) {
                throw PeriodLockedException::for($newEntryDate, CarbonImmutable::parse($lockDate));
            }

            $taxCodeIds = $credit->lines->pluck('tax_code_id')->merge(
                $entry->lines->pluck('tax_code_id')
            )->all();

            $this->taxLockGuard->ensureNotFiled((int) $credit->company_id, $taxCodeIds, $originalEntryDate);
            $this->taxLockGuard->ensureNotFiled((int) $credit->company_id, $taxCodeIds, $newEntryDate);

            $credit->recalculateTotals();

            if ($credit->lines->isEmpty() || $credit->total_cents <= 0) {
                throw new RuntimeException('Vendor credit has no lines or zero total; cannot repost.');
            }

            $oldAccountIds = $entry->lines->pluck('account_id')->all();

            $entry->forceFill([
                'entry_date' => $credit->vendor_credit_date,
                'memo' => 'Vendor credit '.$credit->vendor_credit_no.' — '.$credit->contact->display_name,
            ])->save();

            $entry->lines()->delete();

            $this->writeExpenseAndApLines($credit, $entry, 0);

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

            $credit->contact->recomputeApBalance();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $credit->company_id,
                AuditAction::VendorCreditReposted,
                $credit,
                [
                    'vendor_credit_no' => $credit->vendor_credit_no,
                    'total_cents' => (int) $credit->total_cents,
                    'journal_before' => $journalBefore,
                    'journal_after' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    /**
     * Void: write reversing JE, mark vendor credit voided.
     */
    public function void(VendorCredit $credit, ?CarbonImmutable $voidDate = null): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($credit, $voidDate) {
            $credit->loadMissing('journalEntry', 'contact');

            if (! $credit->journal_entry_id) {
                throw new RuntimeException('Vendor credit is not posted.');
            }

            if ($credit->status === VendorCreditStatus::Void) {
                throw new RuntimeException('Vendor credit is already voided.');
            }

            $this->journalPoster->void($credit->journalEntry, $voidDate, "Void of vendor credit {$credit->vendor_credit_no}");

            $credit->forceFill([
                'status' => VendorCreditStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
            ])->save();

            // Voiding restores the vendor's GL AP. If this credit had been applied to a
            // bill via "Close settled balance", release that reconciliation so the bill
            // re-opens instead of showing a stale, lower balance.
            if ($credit->contact_id !== null) {
                $this->billReconciler->releaseExcessReconciliation($credit->company, (int) $credit->contact_id);
            }

            $credit->contact->recomputeApBalance();

            $this->auditRecorder->record(
                (int) $credit->company_id,
                AuditAction::VendorCreditVoided,
                $credit,
                [
                    'vendor_credit_no' => $credit->vendor_credit_no,
                    'voided_at' => optional($credit->voided_at)->format('Y-m-d H:i:s.u'),
                    'journal_entry_id' => (int) $credit->journal_entry_id,
                ],
                $credit->journalEntry,
            );
        }));
    }

    /**
     * Write the expense + recoverable-tax credits and the AP debit (the reverse of a
     * bill), converting to home cents for a foreign credit and carrying the foreign
     * amount as a memo. Rounding between the converted legs and the converted total is
     * plugged onto the largest credit leg so the entry balances in home cents.
     */
    protected function writeExpenseAndApLines(VendorCredit $credit, JournalEntry $entry, int $order): int
    {
        $isForeign = $credit->isForeignCurrency();
        $currency = $isForeign ? mb_strtoupper((string) $credit->currency_code) : null;
        $rate = $isForeign ? $this->lockRate($credit) : '1';

        $ap = $this->controlAccounts->resolve($credit->company, AccountSubtype::AccountsPayable, $credit->currency_code);

        $totalForeign = (int) $credit->total_cents;
        $apHome = Currency::toHomeCents($totalForeign, $rate);

        /** @var list<array{account_id: int, class_id: ?int, location_id: ?int, foreign: int, home: int, memo: ?string}> $legs */
        $legs = [];

        foreach ($this->expenseByAccount($credit) as $expense) {
            $legs[] = ['account_id' => $expense['account_id'], 'class_id' => $expense['class_id'], 'location_id' => $expense['location_id'], 'foreign' => $expense['cents'], 'home' => Currency::toHomeCents($expense['cents'], $rate), 'memo' => null];
        }

        foreach ($this->taxByAgencyPayableAccount($credit) as $payableAccountId => $foreignCents) {
            if ($foreignCents === 0) {
                continue;
            }

            // Tax payable is a system/aggregate leg — never dimension-tagged.
            $legs[] = ['account_id' => $payableAccountId, 'class_id' => null, 'location_id' => null, 'foreign' => $foreignCents, 'home' => Currency::toHomeCents($foreignCents, $rate), 'memo' => 'Input tax credit reversal'];
        }

        $this->applyRoundingPlug($legs, $apHome);

        // AP debit (what we no longer owe), tagged to the vendor.
        $entry->lines()->create([
            'account_id' => $ap->id,
            'debit_cents' => $apHome,
            'credit_cents' => 0,
            'memo' => 'AP — '.$credit->contact->display_name,
            'contact_id' => $credit->contact_id,
            'line_order' => $order++,
            ...Currency::lineMemo($currency, $rate, $totalForeign, 0),
        ]);

        foreach ($legs as $leg) {
            $entry->lines()->create([
                'account_id' => $leg['account_id'],
                'debit_cents' => 0,
                'credit_cents' => $leg['home'],
                'memo' => $leg['memo'],
                'line_order' => $order++,
                'class_id' => $leg['class_id'],
                'location_id' => $leg['location_id'],
                ...Currency::lineMemo($currency, $rate, 0, $leg['foreign']),
            ]);
        }

        if ($isForeign) {
            $credit->forceFill(['fx_rate' => $rate, 'home_total_cents' => $apHome])->save();
        }

        return $order;
    }

    /**
     * Lock the vendor credit's exchange rate (reused on repost), persisting it.
     */
    protected function lockRate(VendorCredit $credit): string
    {
        if ($credit->fx_rate !== null) {
            return (string) $credit->fx_rate;
        }

        $rate = $this->exchangeRates->rateFor(
            $credit->company,
            (string) $credit->currency_code,
            CarbonImmutable::parse($credit->vendor_credit_date),
        );

        $credit->forceFill(['fx_rate' => $rate])->save();

        return $rate;
    }

    /**
     * Expense grouped by the composite (account, class, location); collapses to the
     * account when no dimensions are set.
     *
     * @return list<array{account_id: int, class_id: ?int, location_id: ?int, cents: int}>
     */
    protected function expenseByAccount(VendorCredit $credit): array
    {
        $grouped = [];

        foreach ($credit->lines as $line) {
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
    protected function taxByAgencyPayableAccount(VendorCredit $credit): array
    {
        $grouped = [];

        foreach ($credit->lines as $line) {
            $this->addTaxesByPayable($grouped, [
                [$line->taxCode, (int) $line->line_tax_cents],
                [$line->secondaryTaxCode, (int) $line->secondary_tax_cents],
            ]);
        }

        return $grouped;
    }
}
