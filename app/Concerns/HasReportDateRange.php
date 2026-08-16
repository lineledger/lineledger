<?php

namespace App\Concerns;

use App\Support\Reporting\ReportDatePresets;
use Livewire\Attributes\Url;

/**
 * Shared date-range controls for period reports (Income Statement, Cash Flow,
 * General Ledger, Sales/Purchases). Provides start/end dates plus a fiscal-aware
 * preset dropdown backed by ReportDatePresets.
 *
 * The host component must expose a public Company $company. Call
 * initReportDateRange() from the component's mount() after $company is set.
 */
trait HasReportDateRange
{
    #[Url(as: 'start')]
    public string $startDate = '';

    #[Url(as: 'end')]
    public string $endDate = '';

    #[Url(as: 'range')]
    public string $preset = 'custom';

    public function initReportDateRange(string $defaultPreset = 'this_fiscal_year_to_date'): void
    {
        if ($this->startDate === '' || $this->endDate === '') {
            [$start, $end] = ReportDatePresets::resolve($defaultPreset, $this->reportFiscalStartMonth(), $this->company->currentDateTime());
            $this->preset = $defaultPreset;
            $this->startDate = $start->toDateString();
            $this->endDate = $end->toDateString();
        }
    }

    /**
     * @return array<string, string>
     */
    public function presetOptions(): array
    {
        return ReportDatePresets::options();
    }

    public function updatedPreset(): void
    {
        $range = ReportDatePresets::resolve($this->preset, $this->reportFiscalStartMonth(), $this->company->currentDateTime());

        if ($range !== null) {
            $this->startDate = $range[0]->toDateString();
            $this->endDate = $range[1]->toDateString();
        }
    }

    public function updatedStartDate(): void
    {
        $this->preset = 'custom';
    }

    public function updatedEndDate(): void
    {
        $this->preset = 'custom';
    }

    protected function reportFiscalStartMonth(): int
    {
        return (int) ($this->company->fiscal_year_start_month ?? 1);
    }
}
