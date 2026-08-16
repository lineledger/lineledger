<?php

namespace App\Support\Tax;

use App\Models\TaxCode;

/**
 * Builds the per-tax-code summary rows for a document — one row per distinct tax
 * code applied across its lines, summing each line's primary and secondary tax.
 *
 * GST and PST never combine into one rate: each is computed on the line subtotal
 * independently and remitted to its own agency, so they surface as separate rows
 * (e.g. "GST 5.00% … 5.00" and "PST 7.00% … 7.00") rather than a single "Tax".
 */
final class LineTaxBreakdown
{
    /**
     * Group document lines by tax code, summing the primary and secondary tax on
     * each. Lines must expose `taxCode`/`line_tax_cents` and
     * `secondaryTaxCode`/`secondary_tax_cents`. Null codes and zero amounts are
     * skipped, so a single-tax line yields one row.
     *
     * @param  iterable<int, object{taxCode: ?TaxCode, line_tax_cents: int, secondaryTaxCode: ?TaxCode, secondary_tax_cents: int}>  $lines
     * @return array<int, array{label: string, rate: float, tax_cents: int}>
     */
    public static function forLines(iterable $lines): array
    {
        $rows = [];

        foreach ($lines as $line) {
            foreach ([
                [$line->taxCode, (int) $line->line_tax_cents],
                [$line->secondaryTaxCode, (int) $line->secondary_tax_cents],
            ] as [$code, $cents]) {
                if (! $code || $cents === 0) {
                    continue;
                }

                $rows[$code->id] ??= [
                    'label' => (string) $code->name,
                    'rate' => $code->ratePercent(),
                    'tax_cents' => 0,
                ];
                $rows[$code->id]['tax_cents'] += $cents;
            }
        }

        return array_values($rows);
    }
}
