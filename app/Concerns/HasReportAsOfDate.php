<?php

namespace App\Concerns;

use App\Support\Reporting\ReportDatePresets;
use Livewire\Attributes\Url;

/**
 * Shared single-date ("as of") controls for balance reports (Balance Sheet,
 * Trial Balance, AR/AP Aging, Open Invoices/Bills). Provides the as-of date
 * plus a preset dropdown that resolves to the period END date (QuickBooks shows
 * the same period dropdown for as-of reports).
 *
 * The host component must expose a public Company $company. Call
 * initReportAsOfDate() from the component's mount() after $company is set.
 */
trait HasReportAsOfDate
{
    #[Url(as: 'as_of')]
    public string $asOf = '';

    #[Url(as: 'period')]
    public string $asOfPreset = 'custom';

    public function initReportAsOfDate(): void
    {
        if ($this->asOf === '') {
            $this->asOf = $this->company->currentDateTime()->toDateString();
        }
    }

    /**
     * @return array<string, string>
     */
    public function asOfPresetOptions(): array
    {
        return ReportDatePresets::options();
    }

    public function updatedAsOfPreset(): void
    {
        $range = ReportDatePresets::resolve(
            $this->asOfPreset,
            (int) ($this->company->fiscal_year_start_month ?? 1),
            $this->company->currentDateTime(),
        );

        if ($range !== null) {
            // As-of reports show the balance at a point in time: use the period end.
            $this->asOf = $range[1]->toDateString();
        }
    }

    public function updatedAsOf(): void
    {
        $this->asOfPreset = 'custom';
    }
}
