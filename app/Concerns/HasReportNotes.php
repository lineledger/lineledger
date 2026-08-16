<?php

namespace App\Concerns;

/**
 * Free-form footer notes shown under the report and on its PDF (QuickBooks
 * "Footer" tab). Deliberately NOT URL-bound — up to 4,000 characters does not
 * belong in a query string; persistence happens through Memorizable, which
 * captures the property via ReportSettings::KEYS.
 */
trait HasReportNotes
{
    public string $reportNotes = '';

    public function updatedReportNotes(): void
    {
        $this->reportNotes = mb_substr($this->reportNotes, 0, 4000);
    }
}
