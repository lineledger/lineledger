<?php

namespace App\Services\Posting;

use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\PostingValidationException;
use App\Exceptions\Posting\UnbalancedJournalException;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\JournalEntry;
use App\Models\StockMovement;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Currency\ExchangeRateService;
use App\Services\Inventory\InventoryCostingFactory;
use App\Services\Inventory\MovementContext;
use App\Services\Tax\TaxPeriodLockGuard;
use App\Support\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a bill (vendor bill OR employee reimbursement) to the GL.
 *   DR  Expense (per-account, grouped)               line_subtotal
 *   DR  Tax Payable (per-agency, recoverable)        line_tax     (input tax credit)
 *   CR    Accounts Payable / Reimbursements Payable  total
 *
 * The control account on the credit side is chosen by bill_type:
 *   - vendor       → Accounts Payable (system account)
 *   - reimbursement → Employee Reimbursements Payable (system account)
 *
 * Non-recoverable tax stays on the expense line (gross-up) — implemented by
 * treating non-recoverable tax as part of the subtotal at compute time.
 * Recoverable tax (the default for GST/HST) is split off and DR'd to the
 * tax payable account, reducing what the company will eventually remit.
 */
class BillPoster
{
    use Concerns\PlugsForeignRounding;
    use Concerns\SplitsLineTax;

    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
        protected AccountingAuditRecorder $auditRecorder,
        protected TaxPeriodLockGuard $taxLockGuard,
        protected InventoryCostingFactory $costingFactory,
        protected ControlAccountResolver $controlAccounts,
        protected ExchangeRateService $exchangeRates,
    ) {}

    public function post(Bill $bill): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($bill) {
            $bill->loadMissing('lines.taxCode.agency', 'lines.secondaryTaxCode.agency', 'lines.item', 'contact', 'company');

            if ($bill->journal_entry_id) {
                throw AlreadyPostedException::for((int) $bill->journal_entry_id);
            }

            if ($bill->company->isLockedFor(CarbonImmutable::parse($bill->bill_date))) {
                throw PeriodLockedException::for(
                    CarbonImmutable::parse($bill->bill_date),
                    CarbonImmutable::parse($bill->company->lock_date),
                );
            }

            $this->taxLockGuard->ensureNotFiled(
                (int) $bill->company_id,
                $bill->lines->pluck('tax_code_id')->all(),
                CarbonImmutable::parse($bill->bill_date),
            );

            $bill->recalculateTotals();

            if ($bill->lines->isEmpty() || $bill->total_cents <= 0) {
                throw new PostingValidationException('Bill has no lines or zero total; cannot post.');
            }

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($bill->company),
                'entry_date' => $bill->bill_date,
                'memo' => $bill->bill_type->label().' '.$bill->bill_no.' — '.$bill->contact->display_name,
                'source_type' => Bill::class,
                'source_id' => $bill->id,
            ]);

            $this->writeExpenseAndControlLines($bill, $entry, 0);

            $entry->refresh();
            $this->journalPoster->post($entry);

            $this->writeStockReceipts($bill, $entry);

            $bill->forceFill([
                'status' => BillStatus::Posted,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $entry->id,
            ])->save();

            $bill->contact->recomputeApBalance();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $bill->company_id,
                AuditAction::BillPosted,
                $bill,
                [
                    'bill_no' => $bill->bill_no,
                    'bill_date' => optional($bill->bill_date)->toDateString(),
                    'bill_type' => $bill->bill_type->value,
                    'total_cents' => (int) $bill->total_cents,
                    'contact_id' => (int) $bill->contact_id,
                    'journal_entry_id' => (int) $entry->id,
                    'journal' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    /**
     * Re-post a posted bill in place after the user edits it.
     * GL is updated atomically; bill payments are not disturbed and status
     * recomputes (paid/partial/posted) against the new total.
     */
    public function repost(Bill $bill): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($bill) {
            $bill->loadMissing('lines.taxCode.agency', 'lines.secondaryTaxCode.agency', 'lines.item', 'contact', 'company', 'journalEntry.lines');

            if (! $bill->journal_entry_id) {
                throw new RuntimeException('Bill has not been posted yet — call post() instead.');
            }

            if ($bill->status === BillStatus::Void) {
                throw new RuntimeException('Cannot repost a voided bill.');
            }

            $entry = $bill->journalEntry;
            $journalBefore = AccountingAuditRecorder::snapshotJournalEntry($entry);
            $lockDate = $bill->company->lock_date;

            $originalEntryDate = CarbonImmutable::parse($entry->entry_date);
            $newEntryDate = CarbonImmutable::parse($bill->bill_date);

            if ($bill->company->isLockedFor($originalEntryDate)) {
                throw PeriodLockedException::for($originalEntryDate, CarbonImmutable::parse($lockDate));
            }

            if ($bill->company->isLockedFor($newEntryDate)) {
                throw PeriodLockedException::for($newEntryDate, CarbonImmutable::parse($lockDate));
            }

            $taxCodeIds = $bill->lines->pluck('tax_code_id')->merge(
                $entry->lines->pluck('tax_code_id')
            )->all();

            $this->taxLockGuard->ensureNotFiled((int) $bill->company_id, $taxCodeIds, $originalEntryDate);
            $this->taxLockGuard->ensureNotFiled((int) $bill->company_id, $taxCodeIds, $newEntryDate);

            $bill->recalculateTotals();

            if ($bill->lines->isEmpty() || $bill->total_cents <= 0) {
                throw new RuntimeException('Bill has no lines or zero total; cannot repost.');
            }

            $oldAccountIds = $entry->lines->pluck('account_id')->all();

            // Reverse stock movements created by the original post before we rebuild.
            $this->reverseStockMovementsForBill($bill, $entry);

            $entry->forceFill([
                'entry_date' => $bill->bill_date,
                'memo' => $bill->bill_type->label().' '.$bill->bill_no.' — '.$bill->contact->display_name,
            ])->save();

            $entry->lines()->delete();

            $this->writeExpenseAndControlLines($bill, $entry, 0);

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

            $this->writeStockReceipts($bill, $entry);

            $this->recomputeStatus($bill);
            $bill->contact->recomputeApBalance();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $bill->company_id,
                AuditAction::BillReposted,
                $bill,
                [
                    'bill_no' => $bill->bill_no,
                    'total_cents' => (int) $bill->total_cents,
                    'journal_before' => $journalBefore,
                    'journal_after' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    protected function recomputeStatus(Bill $bill): void
    {
        $settled = $bill->settledCents();

        if ($bill->balanceCents() <= 0 && $settled > 0) {
            $bill->status = BillStatus::Paid;
        } elseif ($settled > 0) {
            $bill->status = BillStatus::Partial;
        } else {
            $bill->status = BillStatus::Posted;
        }

        $bill->save();
    }

    public function void(Bill $bill, ?CarbonImmutable $voidDate = null): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($bill, $voidDate) {
            $bill->loadMissing('journalEntry', 'contact', 'company');

            if (! $bill->journal_entry_id) {
                throw new RuntimeException('Bill is not posted.');
            }

            if ($bill->status === BillStatus::Void) {
                throw new RuntimeException('Bill is already voided.');
            }

            if ((int) $bill->amount_paid_cents > 0) {
                throw new RuntimeException('Cannot void a bill with applied payments. Void those first.');
            }

            $this->reverseStockMovementsForBill($bill, $bill->journalEntry);

            $this->journalPoster->void($bill->journalEntry, $voidDate, "Void of {$bill->bill_type->label()} {$bill->bill_no}");

            $bill->forceFill([
                'status' => BillStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
            ])->save();

            $bill->contact->recomputeApBalance();

            $this->auditRecorder->record(
                (int) $bill->company_id,
                AuditAction::BillVoided,
                $bill,
                [
                    'bill_no' => $bill->bill_no,
                    'voided_at' => optional($bill->voided_at)->format('Y-m-d H:i:s.u'),
                    'journal_entry_id' => (int) $bill->journal_entry_id,
                ],
                $bill->journalEntry,
            );
        }));
    }

    /**
     * Write the expense + recoverable-tax debits and the control credit (the
     * mirror of an invoice on the AP side), converting to home cents for a foreign
     * bill and carrying the foreign amount as a memo. Rounding between the
     * converted legs and the converted total is plugged onto the largest debit leg.
     */
    protected function writeExpenseAndControlLines(Bill $bill, JournalEntry $entry, int $order): int
    {
        $isForeign = $bill->isForeignCurrency();
        $currency = $isForeign ? mb_strtoupper((string) $bill->currency_code) : null;
        $rate = $isForeign ? $this->lockRate($bill) : '1';

        $control = $this->controlAccount($bill);

        $totalForeign = (int) $bill->total_cents;
        $controlHome = Currency::toHomeCents($totalForeign, $rate);

        /** @var list<array{account_id: int, class_id: ?int, location_id: ?int, foreign: int, home: int, memo: ?string}> $legs */
        $legs = [];

        foreach ($this->expenseByAccount($bill) as $expense) {
            $legs[] = ['account_id' => $expense['account_id'], 'class_id' => $expense['class_id'], 'location_id' => $expense['location_id'], 'foreign' => $expense['cents'], 'home' => Currency::toHomeCents($expense['cents'], $rate), 'memo' => null];
        }

        foreach ($this->recoverableTaxByPayableAccount($bill) as $payableAccountId => $foreignCents) {
            if ($foreignCents === 0) {
                continue;
            }

            // Input tax credit is a system/aggregate leg — never dimension-tagged.
            $legs[] = ['account_id' => $payableAccountId, 'class_id' => null, 'location_id' => null, 'foreign' => $foreignCents, 'home' => Currency::toHomeCents($foreignCents, $rate), 'memo' => 'Input tax credit'];
        }

        $this->applyRoundingPlug($legs, $controlHome);

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
            'account_id' => $control->id,
            'debit_cents' => 0,
            'credit_cents' => $controlHome,
            'memo' => $bill->bill_type->label().' — '.$bill->contact->display_name,
            'contact_id' => $bill->contact_id,
            'line_order' => $order++,
            ...Currency::lineMemo($currency, $rate, 0, $totalForeign),
        ]);

        if ($isForeign) {
            $bill->forceFill(['fx_rate' => $rate, 'home_total_cents' => $controlHome])->save();
        }

        return $order;
    }

    /**
     * Lock the bill's exchange rate (reused on repost), persisting it.
     */
    protected function lockRate(Bill $bill): string
    {
        if ($bill->fx_rate !== null) {
            return (string) $bill->fx_rate;
        }

        $rate = $this->exchangeRates->rateFor(
            $bill->company,
            (string) $bill->currency_code,
            CarbonImmutable::parse($bill->bill_date),
        );

        $bill->forceFill(['fx_rate' => $rate])->save();

        return $rate;
    }

    /**
     * Expense grouped by the composite (effective debit account, class, location)
     * so dimension-tagged lines post as separate GL legs. With no dimensions the
     * key collapses to the account, reproducing the pre-dimension grouping exactly.
     *
     * @return list<array{account_id: int, class_id: ?int, location_id: ?int, cents: int}>
     */
    protected function expenseByAccount(Bill $bill): array
    {
        $grouped = [];

        foreach ($bill->lines as $line) {
            $cents = (int) $line->line_subtotal_cents;

            // Non-recoverable tax is added to the expense (gross-up). Each of the
            // line's taxes is grossed up only if it is itself non-recoverable.
            $cents += $this->nonRecoverableTax([
                [$line->taxCode, (int) $line->line_tax_cents],
                [$line->secondaryTaxCode, (int) $line->secondary_tax_cents],
            ]);

            $accountId = $this->effectiveDebitAccountId($line);

            $key = $accountId.':'.($line->class_id ?? '').':'.($line->location_id ?? '');
            $grouped[$key] ??= [
                'account_id' => $accountId,
                'class_id' => $line->class_id,
                'location_id' => $line->location_id,
                'cents' => 0,
            ];
            $grouped[$key]['cents'] += $cents;
        }

        return array_values($grouped);
    }

    /**
     * For tracked-inventory items, the bill line debits the inventory asset
     * account instead of whatever expense account the user chose. The chosen
     * line.account_id is still stored for reference but bypassed at posting.
     */
    protected function effectiveDebitAccountId(BillLine $line): int
    {
        $item = $line->item;

        if ($item?->track_inventory) {
            $assetId = $item->inventory_asset_account_id
                ?? $line->bill->company->default_inventory_asset_account_id;

            if (! $assetId) {
                throw new RuntimeException(
                    "Item '{$item->name}' is tracked but has no inventory asset account configured."
                );
            }

            return (int) $assetId;
        }

        return (int) $line->account_id;
    }

    /**
     * Record stock receipts on the journal entry for each tracked-inventory line.
     * Receipt unit cost = (line_subtotal + non-recoverable tax) / qty, mirroring
     * the same gross-up rule applied to expense lines.
     */
    protected function writeStockReceipts(Bill $bill, JournalEntry $entry): void
    {
        $costing = $this->costingFactory->for($bill->company);

        // Inventory is carried in the home currency, so a foreign bill's line cost
        // is converted at the bill's locked rate before computing the unit cost.
        $isForeign = $bill->isForeignCurrency();
        $rate = $isForeign ? (string) $bill->fx_rate : '1';

        foreach ($bill->lines as $line) {
            $item = $line->item;
            if (! $item?->track_inventory) {
                continue;
            }

            $qty = (float) $line->quantity;
            if ($qty <= 0) {
                continue;
            }

            $base = (int) $line->line_subtotal_cents;
            $base += $this->nonRecoverableTax([
                [$line->taxCode, (int) $line->line_tax_cents],
                [$line->secondaryTaxCode, (int) $line->secondary_tax_cents],
            ]);

            if ($isForeign) {
                $base = Currency::toHomeCents($base, $rate);
            }

            $unitCostCents = (int) round($base / $qty);

            $ctx = MovementContext::for(
                $bill->bill_date,
                Bill::class,
                $bill->id,
                $line->id,
                $entry->id,
                "Bill {$bill->bill_no}",
            );

            $costing->recordReceipt($item, (string) $line->quantity, $unitCostCents, $ctx);
        }
    }

    protected function reverseStockMovementsForBill(Bill $bill, JournalEntry $entry): void
    {
        $costing = $this->costingFactory->for($bill->company);

        $movements = StockMovement::query()
            ->where('journal_entry_id', $entry->id)
            ->whereNull('reversal_of_movement_id')
            ->orderByDesc('id')
            ->get();

        foreach ($movements as $movement) {
            $costing->reverse($movement);
        }
    }

    /**
     * @return array<int, int>
     */
    protected function recoverableTaxByPayableAccount(Bill $bill): array
    {
        $grouped = [];

        foreach ($bill->lines as $line) {
            $this->addTaxesByPayable($grouped, [
                [$line->taxCode, (int) $line->line_tax_cents],
                [$line->secondaryTaxCode, (int) $line->secondary_tax_cents],
            ], recoverableOnly: true);
        }

        return $grouped;
    }

    protected function controlAccount(Bill $bill): Account
    {
        // Vendor bills credit Accounts Payable — the per-currency foreign AP
        // control for a foreign bill, the home AP otherwise. Reimbursements always
        // settle in the home currency against Employee Reimbursements Payable.
        if ($bill->bill_type === BillType::Vendor) {
            return $this->controlAccounts->resolve($bill->company, AccountSubtype::AccountsPayable, $bill->currency_code);
        }

        $account = Account::withoutGlobalScopes()
            ->where('company_id', $bill->company_id)
            ->where('is_system', true)
            ->where('subtype', AccountSubtype::CurrentLiability->value)
            ->where('name', 'Employee Reimbursements Payable')
            ->first();

        if (! $account) {
            throw new RuntimeException("Missing system control account for bill type [{$bill->bill_type->value}].");
        }

        return $account;
    }
}
