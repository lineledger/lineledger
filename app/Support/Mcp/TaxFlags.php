<?php

namespace App\Support\Mcp;

use App\Models\TaxCode;

/**
 * The short tax flags a statement prints beside each line — "G" for GST,
 * "P" for PST, "GP" for both, "E" for exempt, "Z" for zero-rated. Derived
 * from the line's tax codes (first letter of each code), never stored, so a
 * renamed or new code flags itself. A line with no tax code reads "E".
 */
final class TaxFlags
{
    public static function for(?TaxCode $primary, ?TaxCode $secondary = null): string
    {
        $flags = '';

        foreach ([$primary, $secondary] as $code) {
            $letter = $code !== null ? mb_strtoupper(mb_substr((string) preg_replace('/[^A-Za-z]/', '', $code->code), 0, 1)) : '';

            if ($letter !== '' && ! str_contains($flags, $letter)) {
                $flags .= $letter;
            }
        }

        return $flags === '' ? 'E' : $flags;
    }
}
