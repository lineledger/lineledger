<?php

namespace App\Support\Translation;

/**
 * Report labels that callers translate through a variable
 * (e.g. `__($activity->label())`), registered so KeyExtractor sees them and
 * lang/fr.json keeps their French. The enums themselves return English.
 */
final class ReportCopy
{
    /**
     * @return list<string>
     */
    public static function strings(): array
    {
        return [
            // App\Enums\CashFlowActivity::label()
            __('Operating Activities'),
            __('Investing Activities'),
            __('Financing Activities'),
        ];
    }
}
