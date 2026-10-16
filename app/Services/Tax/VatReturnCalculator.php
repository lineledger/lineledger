<?php

namespace App\Services\Tax;

use App\Models\Bill;
use App\Models\Cheque;
use App\Models\CreditMemo;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\SalesReceipt;
use App\Models\TaxAgency;
use App\Models\TaxCode;
use App\Models\TaxReturn;
use App\Models\VendorCredit;
use App\Support\Currency;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Works out the HMRC nine-box VAT return for a VAT agency and period.
 *
 * Boxes 1 and 4 are the same VAT the generic tax return counts (collected on
 * sales, reclaimed on purchases — {@see TaxReturnCalculator}), so a VAT return
 * and its tax return always agree, exclusions and adjustments included. An
 * "other" adjustment has no box of its own on the HMRC form, so it is folded in
 * the way HMRC asks for small error corrections: an increase in what is owed
 * goes in Box 1, a decrease in Box 4. Box 5 therefore always equals the tax
 * return's net owing.
 *
 * Boxes 6 and 7 are the ex-VAT values of the sales and purchases coded to one
 * of the agency's tax codes — standard, reduced, zero-rated or exempt — taken
 * from the posted source documents in the period, in the home currency. A line
 * with no tax code is outside the scope of VAT (wages, transfers, loan
 * repayments…), which is exactly what HMRC leaves out of those boxes.
 *
 * Boxes 2, 8 and 9 cover Northern Ireland's trade in goods with the EU, which
 * the ledger does not distinguish; they are reported as zero.
 *
 * Pure read service: nothing here writes.
 */
class VatReturnCalculator
{
    /**
     * Documents that can be opening balances — carried in from before the
     * books started, so never part of a period's sales or purchases.
     */
    private const HAS_OPENING_BALANCE_FLAG = [Invoice::class, CreditMemo::class, Bill::class, VendorCredit::class, Cheque::class];

    public function __construct(protected TaxReturnCalculator $returns) {}

    /**
     * The boxes for a tax return: frozen at filing when the return carries a
     * snapshot, otherwise worked out live from its period, exclusions and
     * adjustments.
     */
    public function forReturn(TaxReturn $return): VatReturnBoxes
    {
        // Read through the cast as mixed: a return filed before this column
        // existed — or outside the UK — has no snapshot.
        $snapshot = $return->getAttribute('vat_boxes');

        if (is_array($snapshot) && $snapshot !== []) {
            return VatReturnBoxes::fromArray($snapshot);
        }

        return $this->fromFigures(
            $this->returns->forReturn($return),
            $return->taxAgency,
            CarbonImmutable::parse($return->period_start),
            CarbonImmutable::parse($return->period_end),
        );
    }

    /**
     * The boxes for an agency and period with nothing excluded or adjusted —
     * what a VAT return for that period would show before any review.
     */
    public function forPeriod(TaxAgency $agency, CarbonInterface $start, CarbonInterface $end): VatReturnBoxes
    {
        return $this->fromFigures($this->returns->calculate($agency, $start, $end), $agency, $start, $end);
    }

    public function fromFigures(TaxReturnFigures $figures, TaxAgency $agency, CarbonInterface $start, CarbonInterface $end): VatReturnBoxes
    {
        $excluded = $this->excludedDocuments($figures);

        $other = $figures->otherAdjustmentsCents;

        return VatReturnBoxes::make(
            vatOnSales: $figures->collectedCents + max($other, 0),
            vatReclaimed: $figures->paidCents + max(-$other, 0),
            netSales: $this->netSalesCents($agency, $start, $end, $excluded),
            netPurchases: $this->netPurchasesCents($agency, $start, $end, $excluded),
        );
    }

    /**
     * Ex-VAT value of the sales coded to the agency's tax codes: invoices and
     * sales receipts, less credit memos. An invoice's document-level discount
     * is shared across its lines by value, so only the coded lines' share
     * comes off.
     *
     * @param  array<string, true>  $excluded  "Model\Class:id" keys left off the return
     */
    public function netSalesCents(TaxAgency $agency, CarbonInterface $start, CarbonInterface $end, array $excluded = []): int
    {
        $codeIds = $this->agencyTaxCodeIds($agency);

        if ($codeIds === []) {
            return 0;
        }

        return $this->sumDocuments(Invoice::class, 'invoice_date', $agency, $start, $end, $codeIds, $excluded, 1, 'line_subtotal_cents', discounted: true)
            + $this->sumDocuments(SalesReceipt::class, 'receipt_date', $agency, $start, $end, $codeIds, $excluded, 1, 'line_subtotal_cents')
            + $this->sumDocuments(CreditMemo::class, 'credit_memo_date', $agency, $start, $end, $codeIds, $excluded, -1, 'line_subtotal_cents');
    }

    /**
     * Ex-VAT value of the purchases coded to the agency's tax codes: bills,
     * expenses and cheques, less vendor credits.
     *
     * @param  array<string, true>  $excluded  "Model\Class:id" keys left off the return
     */
    public function netPurchasesCents(TaxAgency $agency, CarbonInterface $start, CarbonInterface $end, array $excluded = []): int
    {
        $codeIds = $this->agencyTaxCodeIds($agency);

        if ($codeIds === []) {
            return 0;
        }

        return $this->sumDocuments(Bill::class, 'bill_date', $agency, $start, $end, $codeIds, $excluded, 1, 'line_subtotal_cents')
            + $this->sumDocuments(Expense::class, 'expense_date', $agency, $start, $end, $codeIds, $excluded, 1, 'amount_cents')
            + $this->sumDocuments(Cheque::class, 'cheque_date', $agency, $start, $end, $codeIds, $excluded, 1, 'amount_cents')
            + $this->sumDocuments(VendorCredit::class, 'vendor_credit_date', $agency, $start, $end, $codeIds, $excluded, -1, 'line_subtotal_cents');
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<int>  $codeIds
     * @param  array<string, true>  $excluded
     */
    protected function sumDocuments(
        string $model,
        string $dateColumn,
        TaxAgency $agency,
        CarbonInterface $start,
        CarbonInterface $end,
        array $codeIds,
        array $excluded,
        int $sign,
        string $netColumn,
        bool $discounted = false,
    ): int {
        $instance = new $model;
        $table = $instance->getTable();

        $query = $model::query()
            ->withoutGlobalScopes()
            ->where($table.'.company_id', $agency->company_id)
            ->whereNotNull($table.'.journal_entry_id')
            ->where($table.'.status', '!=', 'void')
            ->whereDate($table.'.'.$dateColumn, '>=', CarbonImmutable::parse($start)->toDateString())
            ->whereDate($table.'.'.$dateColumn, '<=', CarbonImmutable::parse($end)->toDateString())
            ->whereHas('lines', fn ($lines) => $lines->where(
                fn ($q) => $q->whereIn('tax_code_id', $codeIds)->orWhereIn('secondary_tax_code_id', $codeIds),
            ))
            ->with('lines');

        if (in_array($model, self::HAS_OPENING_BALANCE_FLAG, true)) {
            $query->where(fn ($q) => $q->whereNull($table.'.is_opening_balance')->orWhere($table.'.is_opening_balance', false));
        }

        $total = 0;

        foreach ($query->get() as $document) {
            if (isset($excluded[$model.':'.$document->getKey()])) {
                continue;
            }

            $coded = 0;
            $all = 0;

            foreach ($document->getRelation('lines') as $line) {
                $net = (int) $line->{$netColumn};
                $all += $net;

                if (in_array((int) $line->tax_code_id, $codeIds, true) || in_array((int) $line->secondary_tax_code_id, $codeIds, true)) {
                    $coded += $net;
                }
            }

            $discount = $discounted ? (int) $document->getAttribute('document_discount_cents') : 0;

            if ($discount > 0 && $all > 0) {
                $coded -= intdiv($discount * $coded, $all);
            }

            $total += $sign * $this->toHome($document, $coded);
        }

        return $total;
    }

    protected function toHome(Model $document, int $cents): int
    {
        $rate = (string) ($document->fx_rate ?? '');

        return $rate !== '' && $rate !== '1' && bccomp($rate, '1', 8) !== 0
            ? Currency::toHomeCents($cents, $rate)
            : $cents;
    }

    /**
     * @return list<int>
     */
    protected function agencyTaxCodeIds(TaxAgency $agency): array
    {
        return TaxCode::query()
            ->withoutGlobalScopes()
            ->where('company_id', $agency->company_id)
            ->where('agency_id', $agency->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The source documents whose VAT lines were left off the return: their
     * values come off Boxes 6 and 7 too, so a document moved to the next
     * period moves whole.
     *
     * @return array<string, true>
     */
    protected function excludedDocuments(TaxReturnFigures $figures): array
    {
        $keys = [];

        foreach ($figures->returnLines() as $line) {
            if (($line['excluded'] ?? false) && $line['source_type'] !== null && $line['source_id'] !== null) {
                $keys[$line['source_type'].':'.$line['source_id']] = true;
            }
        }

        return $keys;
    }
}
