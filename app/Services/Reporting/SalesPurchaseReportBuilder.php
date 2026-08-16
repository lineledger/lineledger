<?php

namespace App\Services\Reporting;

use App\Enums\PurchaseOrderStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Support\Reporting\ComparisonRow;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pure read service for Sales and Purchases reports. Aggregates line-item
 * subtotals (pre-tax cents) over a period, grouped by a dimension, netting the
 * relevant credit document. Draft and void documents are excluded so the totals
 * match the accrual figures that hit the GL.
 *
 * Uses the query builder (not Eloquent) for the grouped sums, so the company is
 * scoped explicitly. Dates are compared as Y-m-d strings to stay correct on both
 * MySQL and SQLite (see the date-handling project memory).
 */
class SalesPurchaseReportBuilder
{
    /** Document statuses that count toward sales/purchases (everything live). */
    private const LIVE_STATUSES_EXCLUDED = ['draft', 'void'];

    /**
     * Sales grouped by customer, item, or sales rep. Invoice + pay-now sales-receipt
     * line subtotals less credit-memo line subtotals.
     *
     * @param  'contact'|'item'|'sales_rep'  $groupBy
     * @return Collection<int, array{key: int|null, label: string, qty: float, amount_cents: int}>
     */
    public function salesByDimension(Company $company, CarbonInterface $start, CarbonInterface $end, string $groupBy, ?int $classId = null, ?int $locationId = null, ?int $contactId = null): Collection
    {
        $expr = $this->groupExpression($groupBy);

        $sales = $this->aggregate('invoices', 'invoice_lines', 'invoice_id', 'invoice_date', $expr, $company, $start, $end, $classId, $locationId, $contactId);

        // Pay-now sales receipts are sales too. They carry no sales_rep, so they
        // only fold into the contact/item groupings.
        if ($groupBy !== 'sales_rep') {
            $salesReceipts = $this->aggregate('sales_receipts', 'sales_receipt_lines', 'sales_receipt_id', 'receipt_date', $expr, $company, $start, $end, $classId, $locationId, $contactId);
            $sales = $this->sumAggregates($sales, $salesReceipts);
        }

        $credits = $this->aggregate('credit_memos', 'credit_memo_lines', 'credit_memo_id', 'credit_memo_date', $expr, $company, $start, $end, $classId, $locationId, $contactId);

        return $this->mergeSigned($sales, $credits, $groupBy);
    }

    /**
     * Purchases grouped by vendor or item. Bill line subtotals less vendor-credit
     * line subtotals.
     *
     * @param  'contact'|'item'  $groupBy
     * @return Collection<int, array{key: int|null, label: string, qty: float, amount_cents: int}>
     */
    public function purchasesByDimension(Company $company, CarbonInterface $start, CarbonInterface $end, string $groupBy, ?int $classId = null, ?int $locationId = null, ?int $contactId = null): Collection
    {
        $expr = $this->groupExpression($groupBy);

        $bills = $this->aggregate('bills', 'bill_lines', 'bill_id', 'bill_date', $expr, $company, $start, $end, $classId, $locationId, $contactId);
        $credits = $this->aggregate('vendor_credits', 'vendor_credit_lines', 'vendor_credit_id', 'vendor_credit_date', $expr, $company, $start, $end, $classId, $locationId, $contactId);

        return $this->mergeSigned($bills, $credits, $groupBy);
    }

    /**
     * Purchase orders not yet fully received (Open or Partial), newest first.
     *
     * @return Collection<int, PurchaseOrder>
     */
    public function openPurchaseOrders(Company $company): Collection
    {
        return PurchaseOrder::query()
            ->where('company_id', $company->id)
            ->whereIn('status', [PurchaseOrderStatus::Open->value, PurchaseOrderStatus::Partial->value])
            ->with('contact')
            ->orderByDesc('po_date')
            ->get();
    }

    /**
     * Merge a current-period and prior-period result set (each already produced
     * by {@see salesByDimension()} / {@see purchasesByDimension()}) into
     * comparison rows, outer-joined by dimension key so a row present in only
     * one period still appears (with 0 on the missing side). Both inputs already
     * carry a label, so it is taken from whichever side has the row. Sorted by
     * current amount, descending.
     *
     * @param  Collection<int, array{key: int|null, label: string, qty: float, amount_cents: int}>  $current
     * @param  Collection<int, array{key: int|null, label: string, qty: float, amount_cents: int}>  $prior
     * @return Collection<int, ComparisonRow>
     */
    public function mergeComparison(Collection $current, Collection $prior): Collection
    {
        $cur = $current->keyBy(fn (array $row): string => $row['key'] === null ? '' : (string) $row['key']);
        $pri = $prior->keyBy(fn (array $row): string => $row['key'] === null ? '' : (string) $row['key']);

        return $cur->keys()->merge($pri->keys())->unique()
            ->map(fn (string $key): ComparisonRow => $this->comparisonRow($cur->get($key), $pri->get($key)))
            ->sortByDesc(fn (ComparisonRow $row): int => $row->amountCents)
            ->values();
    }

    /**
     * Build one comparison row from the current- and prior-period entries for a
     * single dimension key (either may be null when that period has no activity).
     *
     * @param  array{key: int|null, label: string, qty: float, amount_cents: int}|null  $c
     * @param  array{key: int|null, label: string, qty: float, amount_cents: int}|null  $p
     */
    private function comparisonRow(?array $c, ?array $p): ComparisonRow
    {
        return new ComparisonRow(
            key: $c['key'] ?? $p['key'] ?? null,
            label: $c['label'] ?? $p['label'] ?? '',
            qty: $c['qty'] ?? 0.0,
            amountCents: $c['amount_cents'] ?? 0,
            priorQty: $p['qty'] ?? 0.0,
            priorAmountCents: $p['amount_cents'] ?? 0,
        );
    }

    private function groupExpression(string $groupBy): string
    {
        return match ($groupBy) {
            'contact' => 'doc.contact_id',
            'sales_rep' => 'doc.sales_rep_id',
            'item' => 'line.item_id',
        };
    }

    /**
     * @return Collection<int|string, object{group_key: int|null, amount: int, qty: float}>
     */
    private function aggregate(string $docTable, string $lineTable, string $docFk, string $dateCol, string $groupExpr, Company $company, CarbonInterface $start, CarbonInterface $end, ?int $classId, ?int $locationId, ?int $contactId): Collection
    {
        return DB::table("{$lineTable} as line")
            ->join("{$docTable} as doc", "line.{$docFk}", '=', 'doc.id')
            ->where('doc.company_id', $company->id)
            ->whereNotIn('doc.status', self::LIVE_STATUSES_EXCLUDED)
            ->whereBetween("doc.{$dateCol}", [$start->toDateString(), $end->toDateString()])
            ->when($classId !== null, fn ($q) => $q->where('line.class_id', $classId))
            ->when($locationId !== null, fn ($q) => $q->where('line.location_id', $locationId))
            ->when($contactId !== null, fn ($q) => $q->where('doc.contact_id', $contactId))
            ->groupBy(DB::raw($groupExpr))
            ->selectRaw("{$groupExpr} as group_key, SUM(line.line_subtotal_cents) as amount, SUM(line.quantity) as qty")
            ->get()
            ->keyBy(fn (object $row): string => $row->group_key === null ? '' : (string) $row->group_key);
    }

    /**
     * Add two same-sign aggregate result sets (e.g. invoices + sales receipts),
     * keyed the same way {@see aggregate()} keys its output.
     *
     * @param  Collection<int|string, object{group_key: int|null, amount: int, qty: float}>  $a
     * @param  Collection<int|string, object{group_key: int|null, amount: int, qty: float}>  $b
     * @return Collection<int|string, object{group_key: int|null, amount: int, qty: float}>
     */
    private function sumAggregates(Collection $a, Collection $b): Collection
    {
        return $a->keys()->merge($b->keys())->unique()->mapWithKeys(fn (string|int $key): array => [
            $key => (object) [
                'group_key' => $key === '' ? null : (int) $key,
                'amount' => (int) round((float) ($a->get($key)?->amount ?? 0) + (float) ($b->get($key)?->amount ?? 0)),
                'qty' => (float) ($a->get($key)?->qty ?? 0) + (float) ($b->get($key)?->qty ?? 0),
            ],
        ]);
    }

    /**
     * @param  Collection<int|string, object>  $plus
     * @param  Collection<int|string, object>  $minus
     * @return Collection<int, array{key: int|null, label: string, qty: float, amount_cents: int}>
     */
    private function mergeSigned(Collection $plus, Collection $minus, string $groupBy): Collection
    {
        $keys = $plus->keys()->merge($minus->keys())->unique();

        $rows = $keys->map(function (string|int $key) use ($plus, $minus): array {
            $p = $plus->get($key);
            $m = $minus->get($key);

            return [
                'key' => $key === '' ? null : (int) $key,
                'qty' => (float) ($p->qty ?? 0) - (float) ($m->qty ?? 0),
                'amount_cents' => (int) round((float) ($p->amount ?? 0)) - (int) round((float) ($m->amount ?? 0)),
            ];
        });

        return $this->withLabels($rows, $groupBy)
            ->reject(fn (array $row): bool => $row['amount_cents'] === 0 && $row['qty'] === 0.0)
            ->sortByDesc('amount_cents')
            ->values();
    }

    /**
     * @param  Collection<int, array{key: int|null, qty: float, amount_cents: int}>  $rows
     * @return Collection<int, array{key: int|null, label: string, qty: float, amount_cents: int}>
     */
    private function withLabels(Collection $rows, string $groupBy): Collection
    {
        $ids = $rows->pluck('key')->filter()->all();

        $labels = match ($groupBy) {
            'item' => Item::query()->whereIn('id', $ids)->pluck('name', 'id'),
            default => Contact::query()->whereIn('id', $ids)->pluck('display_name', 'id'),
        };

        $fallback = match ($groupBy) {
            'item' => __('No item'),
            'sales_rep' => __('No sales rep'),
            default => __('No contact'),
        };

        return $rows->map(function (array $row) use ($labels, $fallback): array {
            $row['label'] = $row['key'] !== null ? ($labels[$row['key']] ?? $fallback) : $fallback;

            return $row;
        });
    }
}
