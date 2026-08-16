<?php

namespace App\Concerns;

use App\Support\Reporting\ReportNumberFormat;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * Number-format display preferences for the core statements (QuickBooks
 * "Negative numbers" / "Show numbers"): negative style (minus/paren/red) and
 * units (cents/whole/thousands). URL-bound so a customized view is shareable,
 * and persisted by Memorizable via ReportSettings::KEYS.
 *
 * Scope of each preference:
 * - Screen and PDF honour both the negative style and the units.
 * - XLSX honours only the negative style (via the cell number format);
 *   'units' is a screen/PDF display preference — spreadsheets keep full
 *   2-decimal precision so figures stay summable.
 * - CSV is untouched: machine-readable, always plain '-1234.56'.
 */
trait HasReportNumberFormat
{
    #[Url(as: 'neg')]
    public string $negativeStyle = 'minus';

    #[Url(as: 'units')]
    public string $numberUnits = 'cents';

    #[Computed]
    public function numberFormat(): ReportNumberFormat
    {
        return ReportNumberFormat::fromProps($this->negativeStyle, $this->numberUnits);
    }
}
