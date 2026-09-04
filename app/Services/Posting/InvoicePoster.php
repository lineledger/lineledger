<?php

namespace App\Services\Posting;

use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Enums\InvoiceStatus;
use App\Enums\ReceiptStatus;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\UnbalancedJournalException;
use App\Models\Account;
use App\Models\Company;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\ReceiptApplication;
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
 * Posts an invoice to the GL.
 *   DR  Accounts Receivable                total
 *   CR    Income (per-account, grouped)    line_subtotal
 *   CR    Tax Payable (per-agency, grouped) line_tax
 */
class InvoicePoster
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

    public function post(Invoice $invoice): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($invoice) {
            $invoice->loadMissing('lines.taxCode.agency', 'lines.secondaryTaxCode.agency', 'lines.item', 'company');

            if ($invoice->journal_entry_id) {
                throw AlreadyPostedException::for((int) $invoice->journal_entry_id);
            }

            if ($invoice->company->isLockedFor(CarbonImmutable::parse($invoice->invoice_date))) {
                throw PeriodLockedException::for(
                    CarbonImmutable::parse($invoice->invoice_date),
                    CarbonImmutable::parse($invoice->company->lock_date),
                );
            }

            $this->taxLockGuard->ensureNotFiled(
                (int) $invoice->company_id,
                $invoice->lines->pluck('tax_code_id')->all(),
                CarbonImmutable::parse($invoice->invoice_date),
            );

            $invoice->recalculateTotals();

            if ($invoice->lines->isEmpty() || $invoice->total_cents <= 0) {
                throw new RuntimeException('Invoice has no lines or zero total; cannot post.');
            }

            $this->preflightStockCheck($invoice);

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($invoice->company),
                'entry_date' => $invoice->invoice_date,
                'memo' => 'Invoice '.$invoice->invoice_no.' — '.$invoice->contact->display_name,
                'source_type' => Invoice::class,
                'source_id' => $invoice->id,
            ]);

            $order = $this->writeArAndRevenueLines($invoice, $entry, 0);

            $order = $this->writeCogsAndIssues($invoice, $entry, $order);

            $entry->refresh();
            $this->journalPoster->post($entry);

            $invoice->forceFill([
                'status' => InvoiceStatus::Posted,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $entry->id,
            ])->save();

            if ($invoice->company->auto_apply_customer_credits && ! $invoice->is_opening_balance) {
                $this->autoApplyCustomerCredits($invoice);
            }

            $invoice->contact->recomputeArBalance();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $invoice->company_id,
                AuditAction::InvoicePosted,
                $invoice,
                [
                    'invoice_no' => $invoice->invoice_no,
                    'invoice_date' => optional($invoice->invoice_date)->toDateString(),
                    'total_cents' => (int) $invoice->total_cents,
                    'subtotal_cents' => (int) $invoice->subtotal_cents,
                    'tax_cents' => (int) $invoice->tax_cents,
                    'contact_id' => (int) $invoice->contact_id,
                    'journal_entry_id' => (int) $entry->id,
                    'journal' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    /**
     * Re-post a posted invoice in place after the user edits it.
     *
     * Mutates the existing journal entry: deletes its lines, rebuilds them
     * from the current invoice state, and recomputes balances on every
     * account that was touched (old + new). Payment applications are NOT
     * disturbed — the invoice keeps its amount_paid and the status is
     * recomputed (paid/partial/posted) against the new total.
     *
     * Use this when the user wants to edit a posted invoice without the
     * void+recreate ceremony. Caller is responsible for the trade-off of
     * mutating posted ledger data; lock-date is still respected on both
     * the original posting date and the (possibly new) invoice date.
     */
    public function repost(Invoice $invoice): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($invoice) {
            $invoice->loadMissing('lines.taxCode.agency', 'lines.secondaryTaxCode.agency', 'lines.item', 'company', 'journalEntry.lines', 'contact');

            if (! $invoice->journal_entry_id) {
                throw new RuntimeException('Invoice has not been posted yet — call post() instead.');
            }

            if ($invoice->status === InvoiceStatus::Void) {
                throw new RuntimeException('Cannot repost a voided invoice.');
            }

            $entry = $invoice->journalEntry;
            $journalBefore = AccountingAuditRecorder::snapshotJournalEntry($entry);
            $lockDate = $invoice->company->lock_date;

            $originalEntryDate = CarbonImmutable::parse($entry->entry_date);
            $newEntryDate = CarbonImmutable::parse($invoice->invoice_date);

            if ($invoice->company->isLockedFor($originalEntryDate)) {
                throw PeriodLockedException::for($originalEntryDate, CarbonImmutable::parse($lockDate));
            }

            if ($invoice->company->isLockedFor($newEntryDate)) {
                throw PeriodLockedException::for($newEntryDate, CarbonImmutable::parse($lockDate));
            }

            $taxCodeIds = $invoice->lines->pluck('tax_code_id')->merge(
                $entry->lines->pluck('tax_code_id')
            )->all();

            $this->taxLockGuard->ensureNotFiled((int) $invoice->company_id, $taxCodeIds, $originalEntryDate);
            $this->taxLockGuard->ensureNotFiled((int) $invoice->company_id, $taxCodeIds, $newEntryDate);

            $invoice->recalculateTotals();

            if ($invoice->lines->isEmpty() || $invoice->total_cents <= 0) {
                throw new RuntimeException('Invoice has no lines or zero total; cannot repost.');
            }

            // Capture every account id touched by the old lines so we can
            // recompute their balances after we wipe and rebuild.
            $oldAccountIds = $entry->lines->pluck('account_id')->all();

            // Reverse stock movements created by the original post so on-hand /
            // FIFO layers reflect the state right before this invoice posted.
            $this->reverseStockMovementsForInvoice($invoice, $entry);

            // Now run the pre-flight check against the restored state for the
            // edited line set. If it fails, the transaction rolls back and the
            // stock movement reversals are undone too.
            $this->preflightStockCheck($invoice);

            $entry->forceFill([
                'entry_date' => $invoice->invoice_date,
                'memo' => 'Invoice '.$invoice->invoice_no.' — '.$invoice->contact->display_name,
            ])->save();

            $entry->lines()->delete();

            $order = $this->writeArAndRevenueLines($invoice, $entry, 0);

            $order = $this->writeCogsAndIssues($invoice, $entry, $order);

            $entry->refresh();

            if (! $entry->isBalanced()) {
                throw UnbalancedJournalException::from(
                    $entry->totalDebitsCents(),
                    $entry->totalCreditsCents(),
                );
            }

            // Recompute balances for every touched account (old + new).
            $newAccountIds = $entry->lines->pluck('account_id')->all();
            foreach (array_unique(array_merge($oldAccountIds, $newAccountIds)) as $id) {
                Account::withoutGlobalScopes()->find($id)?->recomputeBalance();
            }

            // Recompute invoice status against the new total. If the new
            // total is lower than what was already paid, the invoice falls
            // to "paid" (over-payment is left to manual reconciliation).
            $this->recomputeStatus($invoice);
            $invoice->contact->recomputeArBalance();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $invoice->company_id,
                AuditAction::InvoiceReposted,
                $invoice,
                [
                    'invoice_no' => $invoice->invoice_no,
                    'total_cents' => (int) $invoice->total_cents,
                    'journal_before' => $journalBefore,
                    'journal_after' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    /**
     * Void: write reversing JE, mark invoice voided, drop applied receipts.
     */
    public function void(Invoice $invoice, ?CarbonImmutable $voidDate = null): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($invoice, $voidDate) {
            $invoice->loadMissing('journalEntry', 'company');

            if (! $invoice->journal_entry_id) {
                throw new RuntimeException('Invoice is not posted.');
            }

            if ($invoice->status === InvoiceStatus::Void) {
                throw new RuntimeException('Invoice is already voided.');
            }

            if ($invoice->amount_paid_cents > 0) {
                throw new RuntimeException('Cannot void an invoice with applied payments. Unapply receipts first.');
            }

            $this->reverseStockMovementsForInvoice($invoice, $invoice->journalEntry);

            $this->journalPoster->void($invoice->journalEntry, $voidDate, "Void of invoice {$invoice->invoice_no}");

            $invoice->forceFill([
                'status' => InvoiceStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
            ])->save();

            $invoice->contact->recomputeArBalance();

            $this->auditRecorder->record(
                (int) $invoice->company_id,
                AuditAction::InvoiceVoided,
                $invoice,
                [
                    'invoice_no' => $invoice->invoice_no,
                    'voided_at' => optional($invoice->voided_at)->format('Y-m-d H:i:s.u'),
                    'journal_entry_id' => (int) $invoice->journal_entry_id,
                ],
                $invoice->journalEntry,
            );
        }));
    }

    /**
     * Write the AR debit and the revenue + tax credits, converting to home cents
     * for foreign invoices and carrying the original foreign amount as a memo.
     *
     * For the home currency the rate is "1", so every conversion is a no-op and
     * this produces byte-identical lines to the pre-multicurrency poster. For a
     * foreign invoice the AR debit is the converted total and the credits are the
     * converted legs; any ±1¢ rounding between the two is plugged onto the
     * largest credit leg so the entry balances exactly in home cents.
     */
    protected function writeArAndRevenueLines(Invoice $invoice, JournalEntry $entry, int $order): int
    {
        $isForeign = $invoice->isForeignCurrency();
        $currency = $isForeign ? mb_strtoupper((string) $invoice->currency_code) : null;
        $rate = $isForeign ? $this->lockRate($invoice) : '1';

        $ar = $this->controlAccounts->resolve($invoice->company, AccountSubtype::AccountsReceivable, $invoice->currency_code);

        $totalForeign = (int) $invoice->total_cents;
        $arHome = Currency::toHomeCents($totalForeign, $rate);

        $entry->lines()->create([
            'account_id' => $ar->id,
            'debit_cents' => $arHome,
            'credit_cents' => 0,
            'memo' => 'AR — '.$invoice->contact->display_name,
            'contact_id' => $invoice->contact_id,
            'line_order' => $order++,
            ...Currency::lineMemo($currency, $rate, $totalForeign, 0),
        ]);

        // A document-level discount posts as its own debit to a "Sales Discounts"
        // contra-revenue account, so gross income stays visible on the P&L.
        $discountForeign = (int) $invoice->document_discount_cents;
        $discountHome = $discountForeign > 0 ? Currency::toHomeCents($discountForeign, $rate) : 0;

        if ($discountForeign > 0) {
            $entry->lines()->create([
                'account_id' => $this->salesDiscountsAccount($invoice->company)->id,
                'debit_cents' => $discountHome,
                'credit_cents' => 0,
                'memo' => 'Sales discount',
                'line_order' => $order++,
                ...Currency::lineMemo($currency, $rate, $discountForeign, 0),
            ]);
        }

        /** @var list<array{account_id: int, class_id: ?int, location_id: ?int, foreign: int, home: int, memo: ?string}> $legs */
        $legs = [];

        foreach ($this->incomeByAccount($invoice) as $income) {
            $legs[] = ['account_id' => $income['account_id'], 'class_id' => $income['class_id'], 'location_id' => $income['location_id'], 'foreign' => $income['cents'], 'home' => Currency::toHomeCents($income['cents'], $rate), 'memo' => null];
        }

        foreach ($this->taxByAgencyPayableAccount($invoice) as $payableAccountId => $foreignCents) {
            if ($foreignCents === 0) {
                continue;
            }

            // Tax payable is a system/aggregate leg — never dimension-tagged.
            $legs[] = ['account_id' => $payableAccountId, 'class_id' => null, 'location_id' => null, 'foreign' => $foreignCents, 'home' => Currency::toHomeCents($foreignCents, $rate), 'memo' => 'Sales tax'];
        }

        // Credits must balance the AR debit plus the discount debit.
        $this->applyRoundingPlug($legs, $arHome + $discountHome);

        foreach ($legs as $leg) {
            // A leg that nets negative (a discount or credit line on a
            // contra-revenue account) is a DEBIT to that account, never a
            // negative credit.
            $isDebit = $leg['home'] < 0;

            $entry->lines()->create([
                'account_id' => $leg['account_id'],
                'debit_cents' => $isDebit ? -$leg['home'] : 0,
                'credit_cents' => $isDebit ? 0 : $leg['home'],
                'memo' => $leg['memo'],
                'line_order' => $order++,
                'class_id' => $leg['class_id'],
                'location_id' => $leg['location_id'],
                ...($isDebit
                    ? Currency::lineMemo($currency, $rate, -$leg['foreign'], 0)
                    : Currency::lineMemo($currency, $rate, 0, $leg['foreign'])),
            ]);
        }

        if ($isForeign) {
            $invoice->forceFill(['fx_rate' => $rate, 'home_total_cents' => $arHome])->save();
        }

        return $order;
    }

    /**
     * Lock the invoice's exchange rate: reuse the stored rate if present (so a
     * repost keeps the original rate), else resolve it for the invoice date and
     * persist it on the invoice.
     */
    protected function lockRate(Invoice $invoice): string
    {
        if ($invoice->fx_rate !== null) {
            return (string) $invoice->fx_rate;
        }

        $rate = $this->exchangeRates->rateFor(
            $invoice->company,
            (string) $invoice->currency_code,
            CarbonImmutable::parse($invoice->invoice_date),
        );

        $invoice->forceFill(['fx_rate' => $rate])->save();

        return $rate;
    }

    /**
     * Revenue grouped by the composite (account, class, location) so two lines on
     * the same income account but different dimensions post as separate GL legs.
     * With no dimensions every key collapses to the account, reproducing the
     * pre-dimension grouping byte-for-byte.
     *
     * @return list<array{account_id: int, class_id: ?int, location_id: ?int, cents: int}>
     */
    protected function incomeByAccount(Invoice $invoice): array
    {
        $grouped = [];

        foreach ($invoice->lines as $line) {
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
    protected function taxByAgencyPayableAccount(Invoice $invoice): array
    {
        $grouped = [];

        // Each line's primary and secondary taxes (e.g. GST + PST) credit their
        // own agency's payable account, shown separately rather than merged.
        foreach ($invoice->lines as $line) {
            $this->addTaxesByPayable($grouped, [
                [$line->taxCode, (int) $line->line_tax_cents],
                [$line->secondaryTaxCode, (int) $line->secondary_tax_cents],
            ]);
        }

        return $grouped;
    }

    protected function recomputeStatus(Invoice $invoice): void
    {
        if ($invoice->balanceCents() <= 0 && $invoice->settledCents() > 0) {
            $invoice->status = InvoiceStatus::Paid;
        } elseif ($invoice->settledCents() > 0) {
            $invoice->status = InvoiceStatus::Partial;
        } else {
            $invoice->status = InvoiceStatus::Posted;
        }

        $invoice->save();
    }

    /**
     * Consume oldest unapplied customer receipts to pay down this invoice.
     *
     * Creates ReceiptApplication rows and updates the invoice's amount_paid /
     * status. The GL is already correct — receipts originally credited AR — so
     * this only retroactively attaches the credit to a specific invoice.
     */
    protected function autoApplyCustomerCredits(Invoice $invoice): void
    {
        $balance = (int) $invoice->total_cents - (int) $invoice->amount_paid_cents;
        if ($balance <= 0) {
            return;
        }

        $receipts = CustomerReceipt::query()
            ->where('contact_id', $invoice->contact_id)
            ->where('status', ReceiptStatus::Posted->value)
            ->where('receipt_date', '<=', $invoice->invoice_date)
            ->orderBy('receipt_date')
            ->orderBy('id')
            ->get(['id', 'amount_cents']);

        if ($receipts->isEmpty()) {
            return;
        }

        $appliedSumByReceipt = ReceiptApplication::query()
            ->whereIn('customer_receipt_id', $receipts->pluck('id'))
            ->groupBy('customer_receipt_id')
            ->selectRaw('customer_receipt_id, SUM(amount_cents) AS applied_cents')
            ->pluck('applied_cents', 'customer_receipt_id');

        $newlyApplied = 0;

        foreach ($receipts as $receipt) {
            if ($balance <= 0) {
                break;
            }

            $unapplied = (int) $receipt->amount_cents - (int) ($appliedSumByReceipt[$receipt->id] ?? 0);
            if ($unapplied <= 0) {
                continue;
            }

            $apply = min($unapplied, $balance);

            ReceiptApplication::create([
                'customer_receipt_id' => $receipt->id,
                'invoice_id' => $invoice->id,
                'amount_cents' => $apply,
            ]);

            $balance -= $apply;
            $newlyApplied += $apply;
        }

        if ($newlyApplied === 0) {
            return;
        }

        $invoice->forceFill([
            'amount_paid_cents' => min(
                (int) $invoice->amount_paid_cents + $newlyApplied,
                (int) $invoice->total_cents,
            ),
        ])->save();

        $this->recomputeStatus($invoice);
    }

    /**
     * Before writing any stock movements, verify each tracked-item line can be filled
     * from current on-hand. Throws InsufficientStockException so no JE is created.
     */
    protected function preflightStockCheck(Invoice $invoice): void
    {
        $byItem = [];

        foreach ($invoice->lines as $line) {
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
     * For each tracked-inventory line: record a stock issue (consuming layers / avg
     * cost) and accumulate the cost grouped by (cogs_account, inventory_account)
     * pair. Then write the COGS/Inventory journal lines. Returns the next line_order.
     */
    protected function writeCogsAndIssues(Invoice $invoice, JournalEntry $entry, int $order): int
    {
        $costing = $this->costingFactory->for($invoice->company);
        $byPair = [];

        foreach ($invoice->lines as $line) {
            $item = $line->item;
            if (! $item?->track_inventory) {
                continue;
            }
            if ((float) $line->quantity <= 0) {
                continue;
            }

            $cogsAccountId = (int) ($item->cogs_account_id ?? $invoice->company->default_cogs_account_id ?? 0);
            $invAccountId = (int) ($item->inventory_asset_account_id ?? $invoice->company->default_inventory_asset_account_id ?? 0);

            if (! $cogsAccountId || ! $invAccountId) {
                throw new RuntimeException("Item '{$item->name}' is tracked but missing COGS or Inventory account configuration.");
            }

            $ctx = MovementContext::for(
                $invoice->invoice_date,
                Invoice::class,
                $invoice->id,
                $line->id,
                $entry->id,
                "Invoice {$invoice->invoice_no}",
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

    protected function reverseStockMovementsForInvoice(Invoice $invoice, JournalEntry $entry): void
    {
        $costing = $this->costingFactory->for($invoice->company);

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
     * The company's "Sales Discounts" contra-revenue account, created on first use
     * if it doesn't exist (an Income-type account that carries a debit balance).
     */
    protected function salesDiscountsAccount(Company $company): Account
    {
        $existing = Account::query()
            ->where('company_id', $company->id)
            ->where('subtype', AccountSubtype::Income->value)
            ->where('name', 'Sales Discounts')
            ->first();

        if ($existing) {
            return $existing;
        }

        return Account::create([
            'company_id' => $company->id,
            'code' => $this->freeAccountCode($company, '4990'),
            'name' => 'Sales Discounts',
            'subtype' => AccountSubtype::Income,
            'type' => AccountSubtype::Income->type(),
            'normal_balance' => AccountSubtype::Income->type()->normalBalance(),
            'is_active' => true,
        ]);
    }

    /**
     * First account code at or after $preferred that this company isn't already using.
     */
    protected function freeAccountCode(Company $company, string $preferred): string
    {
        $code = $preferred;
        $bump = 0;

        while (Account::query()->where('company_id', $company->id)->where('code', $code)->exists()) {
            $code = (string) ((int) $preferred + (++$bump));
        }

        return $code;
    }
}
