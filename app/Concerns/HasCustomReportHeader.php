<?php

namespace App\Concerns;

use Livewire\Attributes\Url;

/**
 * Lets the user override a report's title (QuickBooks "Header/Footer" tab). The
 * override is empty by default — so it never bloats the URL — and falls back to
 * the report's standard name via effectiveTitle(). The resolved title feeds both
 * the on-screen heading and the exporter $title.
 */
trait HasCustomReportHeader
{
    #[Url(as: 'title')]
    public string $reportTitle = '';

    public function effectiveTitle(string $default): string
    {
        return trim($this->reportTitle) !== '' ? trim($this->reportTitle) : $default;
    }
}
