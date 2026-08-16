<?php

namespace App\Services\Posting;

use App\Enums\AuditAction;
use App\Enums\SalesReceiptStatus;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\UnbalancedJournalException;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\SalesReceipt;
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
 * Posts a pay-now sales receipt to the GL.
 *   DR  Deposit-to (Undeposited Funds / Bank)   total
 *   CR    Income (per-account, grouped)         line_subtotal
 *   CR    Tax Payable (per-agency, grouped)     line_tax
 *   DR  COGS / CR Inventory                     (tracked items)
 *
 * Unlike an invoice there is no Accounts Receivable leg and no payment lifecycle.
 * The sale and the cash settle at the same moment, so a foreign receipt converts
 * every leg at one locked rate — there is no realized exchange gain/loss.
 */
class SalesReceiptPoster
{
    use Concerns\PlugsForeignRounding;
    use Concerns\SplitsLineTax;

    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
        protected AccountingAuditRecorder $auditRecorder,
        protected TaxPeriodLockGuard $taxLockGuard,
        protected InventoryCostingFactory $costingFactory,
        protected ExchangeRateService $exchangeRates,
    ) {}

    public function post(SalesReceipt $receipt): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($receipt) {
            $receipt->loadMissing('lines.taxCode.agency', 'lines.secondaryTaxCode.agency', 'lines.item', 'company', 'contact');

            if ($receipt->journal_entry_id) {
                throw AlreadyPostedException::for((int) $receipt->journal_entry_id);
            }

            if ($receipt->company->isLockedFor(CarbonImmutable::parse($receipt->receipt_date))) {
                throw PeriodLockedException::for(
                    CarbonImmutable::parse($receipt->receipt_date),
                    CarbonImmutable::parse($receipt->company->lock_date),
                );
            }

            $this->taxLockGuard->ensureNotFiled(
                (int) $receipt->company_id,
                $receipt->lines->pluck('tax_code_id')->all(),
                CarbonImmutable::parse($receipt->receipt_date),
            );

            $receipt->recalculateTotals();

            if ($receipt->lines->isEmpty() || $receipt->total_cents <= 0) {
                throw new RuntimeException('Sales receipt has no lines or zero total; cannot post.');
            }

            $this->preflightStockCheck($receipt);

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($receipt->company),
                'entry_date' => $receipt->receipt_date,
                'memo' => $this->entryMemo($receipt),
                'source_type' => SalesReceipt::class,
                'source_id' => $receipt->id,
            ]);

            $order = $this->writeCashAndRevenueLines($receipt, $entry, 0);
            $this->writeCogsAndIssues($receipt, $entry, $order);

            $entry->refresh();
            $this->journalPoster->post($entry);

            $receipt->forceFill([
                'status' => SalesReceiptStatus::Posted,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $entry->id,
            ])->save();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $receipt->company_id,
                AuditAction::SalesReceiptPosted,
                $receipt,
                [
                    'sales_receipt_no' => $receipt->sales_receipt_no,
                    'receipt_date' => optional($receipt->receipt_date)->toDateString(),
                    'total_cents' => (int) $receipt->total_cents,
                    'subtotal_cents' => (int) $receipt->subtotal_cents,
                    'tax_cents' => (int) $receipt->tax_cents,
                    'contact_id' => $receipt->contact_id !== null ? (int) $receipt->contact_id : null,
                    'deposit_to_account_id' => (int) $receipt->deposit_to_account_id,
                    'journal_entry_id' => (int) $entry->id,
                    'journal' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    /**
     * Re-post a posted sales receipt in place after the user edits it. Mutates the
     * existing journal entry: reverses the original stock movements, deletes the
     * lines, rebuilds them, and recomputes balances on every touched account.
     * Lock-date is enforced on both the original and the (possibly new) date.
     */
    public function repost(SalesReceipt $receipt): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($receipt) {
            $receipt->loadMissing('lines.taxCode.agency', 'lines.secondaryTaxCode.agency', 'lines.item', 'company', 'journalEntry.lines', 'contact');

            if (! $receipt->journal_entry_id) {
                throw new RuntimeException('Sales receipt has not been posted yet — call post() instead.');
            }

            if ($receipt->status === SalesReceiptStatus::Void) {
                throw new RuntimeException('Cannot repost a voided sales receipt.');
            }

            $entry = $receipt->journalEntry;
            $journalBefore = AccountingAuditRecorder::snapshotJournalEntry($entry);
            $lockDate = $receipt->company->lock_date;

            $originalEntryDate = CarbonImmutable::parse($entry->entry_date);
            $newEntryDate = CarbonImmutable::parse($receipt->receipt_date);

            if ($receipt->company->isLockedFor($originalEntryDate)) {
                throw PeriodLockedException::for($originalEntryDate, CarbonImmutable::parse($lockDate));
            }

            if ($receipt->company->isLockedFor($newEntryDate)) {
                throw PeriodLockedException::for($newEntryDate, CarbonImmutable::parse($lockDate));
            }

            $taxCodeIds = $receipt->lines->pluck('tax_code_id')->merge(
                $entry->lines->pluck('tax_code_id')
            )->all();

            $this->taxLockGuard->ensureNotFiled((int) $receipt->company_id, $taxCodeIds, $originalEntryDate);
            $this->taxLockGuard->ensureNotFiled((int) $receipt->company_id, $taxCodeIds, $newEntryDate);

            $receipt->recalculateTotals();

            if ($receipt->lines->isEmpty() || $receipt->total_cents <= 0) {
                throw new RuntimeException('Sales receipt has no lines or zero total; cannot repost.');
            }

            $oldAccountIds = $entry->lines->pluck('account_id')->all();

            $this->reverseStockMovements($receipt, $entry);
            $this->preflightStockCheck($receipt);

            $entry->forceFill([
                'entry_date' => $receipt->receipt_date,
                'memo' => $this->entryMemo($receipt),
            ])->save();

            $entry->lines()->delete();

            $order = $this->writeCashAndRevenueLines($receipt, $entry, 0);
            $this->writeCogsAndIssues($receipt, $entry, $order);

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

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $receipt->company_id,
                AuditAction::SalesReceiptReposted,
                $receipt,
                [
                    'sales_receipt_no' => $receipt->sales_receipt_no,
                    'total_cents' => (int) $receipt->total_cents,
                    'journal_before' => $journalBefore,
                    'journal_after' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    /**
     * Void: write a reversing JE and mark the receipt voided. Releases any stock
     * issued back into inventory.
     */
    public function void(SalesReceipt $receipt, ?CarbonImmutable $voidDate = null): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($receipt, $voidDate) {
            $receipt->loadMissing('journalEntry', 'company');

            if (! $receipt->journal_entry_id) {
                throw new RuntimeException('Sales receipt is not posted.');
            }

            if ($receipt->status === SalesReceiptStatus::Void) {
                throw new RuntimeException('Sales receipt is already voided.');
            }

            $this->reverseStockMovements($receipt, $receipt->journalEntry);

            $this->journalPoster->void($receipt->journalEntry, $voidDate, "Void of sales receipt {$receipt->sales_receipt_no}");

            $receipt->forceFill([
                'status' => SalesReceiptStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
            ])->save();

            $this->auditRecorder->record(
                (int) $receipt->company_id,
                AuditAction::SalesReceiptVoided,
                $receipt,
                [
                    'sales_receipt_no' => $receipt->sales_receipt_no,
                    'voided_at' => optional($receipt->voided_at)->format('Y-m-d H:i:s.u'),
                    'journal_entry_id' => (int) $receipt->journal_entry_id,
                ],
                $receipt->journalEntry,
            );
        }));
    }

    protected function entryMemo(SalesReceipt $receipt): string
    {
        $memo = 'Sales receipt '.$receipt->sales_receipt_no;

        if ($receipt->contact) {
            $memo .= ' — '.$receipt->contact->display_name;
        }

        return $memo;
    }

    /**
     * Write the cash debit (to the deposit-to account) and the revenue + tax
     * credits, converting to home cents for foreign receipts at one locked rate.
     * Any ±1¢ rounding is plugged onto the largest credit leg so the entry
     * balances exactly in home cents.
     */
    protected function writeCashAndRevenueLines(SalesReceipt $receipt, JournalEntry $entry, int $order): int
    {
        $isForeign = $receipt->isForeignCurrency();
        $currency = $isForeign ? mb_strtoupper((string) $receipt->currency_code) : null;
        $rate = $isForeign ? $this->lockRate($receipt) : '1';

        $totalForeign = (int) $receipt->total_cents;
        $cashHome = Currency::toHomeCents($totalForeign, $rate);

        $entry->lines()->create([
            'account_id' => $receipt->deposit_to_account_id,
            'debit_cents' => $cashHome,
            'credit_cents' => 0,
            'memo' => 'Deposit',
            'line_order' => $order++,
            ...Currency::lineMemo($currency, $rate, $totalForeign, 0),
        ]);

        /** @var list<array{account_id: int, class_id: ?int, location_id: ?int, foreign: int, home: int, memo: ?string}> $legs */
        $legs = [];

        foreach ($this->incomeByAccount($receipt) as $income) {
            $legs[] = ['account_id' => $income['account_id'], 'class_id' => $income['class_id'], 'location_id' => $income['location_id'], 'foreign' => $income['cents'], 'home' => Currency::toHomeCents($income['cents'], $rate), 'memo' => null];
        }

        foreach ($this->taxByAgencyPayableAccount($receipt) as $payableAccountId => $foreignCents) {
            if ($foreignCents === 0) {
                continue;
            }

            $legs[] = ['account_id' => $payableAccountId, 'class_id' => null, 'location_id' => null, 'foreign' => $foreignCents, 'home' => Currency::toHomeCents($foreignCents, $rate), 'memo' => 'Sales tax'];
        }

        $this->applyRoundingPlug($legs, $cashHome);

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
            $receipt->forceFill(['fx_rate' => $rate, 'home_total_cents' => $cashHome])->save();
        }

        return $order;
    }

    /**
     * Lock the receipt's exchange rate: reuse the stored rate if present (so a
     * repost keeps the original rate), else resolve it for the receipt date.
     */
    protected function lockRate(SalesReceipt $receipt): string
    {
        if ($receipt->fx_rate !== null) {
            return (string) $receipt->fx_rate;
        }

        $rate = $this->exchangeRates->rateFor(
            $receipt->company,
            (string) $receipt->currency_code,
            CarbonImmutable::parse($receipt->receipt_date),
        );

        $receipt->forceFill(['fx_rate' => $rate])->save();

        return $rate;
    }

    /**
     * Revenue grouped by the composite (account, class, location).
     *
     * @return list<array{account_id: int, class_id: ?int, location_id: ?int, cents: int}>
     */
    protected function incomeByAccount(SalesReceipt $receipt): array
    {
        $grouped = [];

        foreach ($receipt->lines as $line) {
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
    protected function taxByAgencyPayableAccount(SalesReceipt $receipt): array
    {
        $grouped = [];

        foreach ($receipt->lines as $line) {
            $this->addTaxesByPayable($grouped, [
                [$line->taxCode, (int) $line->line_tax_cents],
                [$line->secondaryTaxCode, (int) $line->secondary_tax_cents],
            ]);
        }

        return $grouped;
    }

    /**
     * Before writing any stock movements, verify each tracked-item line can be
     * filled from current on-hand. Throws so no JE is created.
     */
    protected function preflightStockCheck(SalesReceipt $receipt): void
    {
        $byItem = [];

        foreach ($receipt->lines as $line) {
            $item = $line->item;
            if (! $item?->track_inventory) {
                continue;
            }
            $byItem[$item->id] ??= ['item' => $item, 'qty' => 0.0];
            $byItem[$item->id]['qty'] += (float) $line->quantity;
        }

        foreach ($byItem as $row) {
            $available = (float) $row['item']->fresh()->qty_on_hand_cached;
            if ($row['qty'] - $available > 0.00001) {
                throw InsufficientStockException::for(
                    $row['item'],
                    (string) $row['qty'],
                    (string) $row['item']->qty_on_hand_cached,
                );
            }
        }
    }

    /**
     * For each tracked-inventory line record a stock issue and accumulate cost
     * grouped by (cogs_account, inventory_account, class, location), then write
     * the COGS/Inventory journal lines. Returns the next line_order.
     */
    protected function writeCogsAndIssues(SalesReceipt $receipt, JournalEntry $entry, int $order): int
    {
        $costing = $this->costingFactory->for($receipt->company);
        $byPair = [];

        foreach ($receipt->lines as $line) {
            $item = $line->item;
            if (! $item?->track_inventory) {
                continue;
            }
            if ((float) $line->quantity <= 0) {
                continue;
            }

            $cogsAccountId = (int) ($item->cogs_account_id ?? $receipt->company->default_cogs_account_id ?? 0);
            $invAccountId = (int) ($item->inventory_asset_account_id ?? $receipt->company->default_inventory_asset_account_id ?? 0);

            if (! $cogsAccountId || ! $invAccountId) {
                throw new RuntimeException("Item '{$item->name}' is tracked but missing COGS or Inventory account configuration.");
            }

            $ctx = MovementContext::for(
                $receipt->receipt_date,
                SalesReceipt::class,
                $receipt->id,
                $line->id,
                $entry->id,
                "Sales receipt {$receipt->sales_receipt_no}",
            );

            $result = $costing->recordIssue($item, (string) $line->quantity, $ctx);

            $key = $cogsAccountId.':'.$invAccountId.':'.($line->class_id ?? '').':'.($line->location_id ?? '');
            $byPair[$key] ??= ['cogs' => $cogsAccountId, 'inv' => $invAccountId, 'class_id' => $line->class_id, 'location_id' => $line->location_id, 'cost' => 0];
            $byPair[$key]['cost'] += (int) $result['cost_cents'];
        }

        foreach ($byPair as $pair) {
            if ($pair['cost'] === 0) {
                continue;
            }

            $entry->lines()->create([
                'account_id' => $pair['cogs'],
                'debit_cents' => $pair['cost'],
                'credit_cents' => 0,
                'memo' => 'COGS',
                'line_order' => $order++,
                'class_id' => $pair['class_id'],
                'location_id' => $pair['location_id'],
            ]);
            $entry->lines()->create([
                'account_id' => $pair['inv'],
                'debit_cents' => 0,
                'credit_cents' => $pair['cost'],
                'memo' => 'Inventory consumed',
                'line_order' => $order++,
                'class_id' => $pair['class_id'],
                'location_id' => $pair['location_id'],
            ]);
        }

        return $order;
    }

    protected function reverseStockMovements(SalesReceipt $receipt, JournalEntry $entry): void
    {
        $costing = $this->costingFactory->for($receipt->company);

        $movements = StockMovement::query()
            ->where('journal_entry_id', $entry->id)
            ->whereNull('reversal_of_movement_id')
            ->orderByDesc('id')
            ->get();

        foreach ($movements as $movement) {
            $costing->reverse($movement);
        }
    }
}
