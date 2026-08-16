<?php

namespace App\Concerns;

use App\Support\Reporting\ComparisonPeriod;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * Period comparison ("compare prior") for date-range reports, driving the
 * control bar's <flux:select wire:model.live="comparisonBasis"> dropdown
 * (enabled via :comparison="true"). Mirrors the Income Statement's inline
 * implementation so the date-range summary reports get the same behaviour.
 *
 * Requires App\Concerns\HasReportDateRange (reads $startDate/$endDate/$preset).
 * The comparison columns themselves are produced by
 * App\Services\Reporting\SalesPurchaseReportBuilder::mergeComparison().
 */
trait HasReportComparison
{
    #[Url(as: 'compare')]
    public string $comparisonBasis = 'off';

    #[Computed]
    public function showComparison(): bool
    {
        return ComparisonPeriod::isOn($this->comparisonBasis);
    }

    /**
     * The prior period [start, end], or null when comparison is off.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function comparisonRange(): ?array
    {
        return ComparisonPeriod::forRange(
            CarbonImmutable::parse($this->startDate),
            CarbonImmutable::parse($this->endDate),
            $this->comparisonBasis,
            $this->preset,
        );
    }

    /**
     * Subtitle suffix naming the compared dates, or '' when off.
     */
    #[Computed]
    public function comparisonNote(): string
    {
        $prior = $this->comparisonRange();

        if ($prior === null) {
            return '';
        }

        return ' · '.__('compared to :start to :end (:basis)', [
            'start' => $prior[0]->toDateString(),
            'end' => $prior[1]->toDateString(),
            'basis' => __(ComparisonPeriod::label($this->comparisonBasis)),
        ]);
    }
}
