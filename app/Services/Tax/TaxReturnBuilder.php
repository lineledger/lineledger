<?php

namespace App\Services\Tax;

use App\Models\TaxAgency;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Pure read service. Given an agency + period, returns the live list of journal
 * lines on its payable account, each classified into a SalesTaxBucket — the
 * collected / paid rows are the candidates filing would snapshot. A return's
 * full figures and reconciliation come from {@see TaxReturnCalculator}.
 */
class TaxReturnBuilder
{
    public function __construct(protected ReportCalculator $reports) {}

    /**
     * @return Collection<int, array{bucket: 'collected'|'paid'|'payment'|'adjustment'|'opening', amount_cents: int, balance_effect_cents: int, journal_line_id: int, entry_id: int, entry_no: string, entry_date: CarbonImmutable, source_type: ?string, source_id: int<0, max>|null, doc_label: string, is_reversal: bool}>
     */
    public function build(TaxAgency $agency, CarbonInterface $start, CarbonInterface $end): Collection
    {
        return $this->reports->salesTaxLines($agency, $start, $end);
    }

    /**
     * @return array{collected: int, paid: int, net: int}
     */
    public function totals(TaxAgency $agency, CarbonInterface $start, CarbonInterface $end): array
    {
        return $this->reports->salesTaxForAgency($agency, $start, $end);
    }
}
