<?php

namespace App\Support\Translation;

/**
 * Seeded tax-code identifiers and names, registered so lang/fr.json can
 * translate picker labels (QST-QC → TVQ) while stored codes stay stable.
 */
final class TaxCopy
{
    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return [
            __('EX'),
            __('GST'),
            __('GST (5%)'),
            __('HST Nova Scotia (15%)'),
            __('HST Ontario (13%)'),
            __('HST-NS'),
            __('HST-ON'),
            __('PST (6%)'),
            __('PST (7%)'),
            __('PST-BC'),
            __('PST-SK'),
            __('QST (9.975%)'),
            __('QST-QC'),
            __('RST (7%)'),
            __('RST-MB'),
            __('ZR'),
            __('Zero-rated (0%)'),
            __('Canada Revenue Agency'),
            __('Revenu Québec'),
            __('BC Ministry of Finance'),
            __('Saskatchewan Ministry of Finance'),
            __('Manitoba Finance'),
        ];
    }
}
