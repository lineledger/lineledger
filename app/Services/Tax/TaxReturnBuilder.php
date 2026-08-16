<?php

namespace App\Services\Tax;

use App\Models\TaxAgency;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Pure read service. Given an agency + period, returns the live list of journal
 * lines that contribute to its tax balance — the candidate rows that filing
 * would snapshot. Reused by both the create-form preview and the show page for
 * draft returns.
 */
class TaxReturnBuilder
{
    public function __construct(protected ReportCalculator $reports) {}

    /**
     * @return Collection<int, array{bucket: 'collected'|'paid', amount_cents: int, entry_id: int, entry_no: string, entry_date: CarbonImmutable, source_type: ?string, source_id: ?int, doc_label: string, is_reversal: bool, journal_line_id: ?int}>
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
