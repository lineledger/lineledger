<?php

namespace App\Support\Reporting;

/**
 * Immutable inputs that shape a report's chart series without re-deriving them
 * from the report arrays: whether a comparison column is present, the human
 * period labels used for dataset names, the home currency (for the JS money
 * formatter), and the "top N" cap for composition doughnuts.
 *
 * Built by the host Livewire component from values it already computes
 * (showComparison, the subtitle pieces, the company currency) and handed to
 * {@see ReportChartBuilder}. The optional {@see StatementLabels} carries the
 * org-type-aware statement vocabulary (Equity vs Net Assets, Net Income vs the
 * excess of revenue over expenses) so chart titles and series match the report.
 */
final readonly class ChartContext
{
    public function __construct(
        public bool $comparison = false,
        public string $periodLabel = 'Current',
        public string $priorLabel = '',
        public string $currency = 'USD',
        public int $topN = 6,
        public ?StatementLabels $labels = null,
    ) {}
}
