<?php

namespace App\Support\Mcp;

use App\Models\BillLine;
use App\Models\InvoiceLine;
use App\Models\TaxCode;

/**
 * Per-tax-code totals for a document's lines: the taxable base (the subtotal
 * of every line carrying the code, as its primary or secondary tax) and the
 * tax charged. "GST 5% on $10,100.00 → $505.00". Codes appear in the order
 * the lines first use them; amounts are cents in the document's currency.
 */
final class DocumentTaxSummary
{
    /**
     * @param  iterable<InvoiceLine|BillLine>  $lines  with taxCode and secondaryTaxCode loaded
     * @return list<array{code: string, name: string, rate: string, base_cents: int, tax_cents: int}>
     */
    public static function for(iterable $lines): array
    {
        /** @var array<int, array{code: string, name: string, rate: string, base_cents: int, tax_cents: int}> $byCode */
        $byCode = [];

        foreach ($lines as $line) {
            $base = (int) $line->line_subtotal_cents;

            self::add($byCode, $line->taxCode, $base, (int) $line->line_tax_cents);
            self::add($byCode, $line->secondaryTaxCode, $base, (int) $line->secondary_tax_cents);
        }

        return array_values($byCode);
    }

    /**
     * @param  array<int, array{code: string, name: string, rate: string, base_cents: int, tax_cents: int}>  $byCode
     */
    private static function add(array &$byCode, ?TaxCode $code, int $base, int $tax): void
    {
        if ($code === null) {
            return;
        }

        $byCode[$code->id] ??= [
            'code' => (string) $code->code,
            'name' => (string) $code->name,
            'rate' => DocumentLinePresenter::ratePercent($code),
            'base_cents' => 0,
            'tax_cents' => 0,
        ];

        $byCode[$code->id]['base_cents'] += $base;
        $byCode[$code->id]['tax_cents'] += $tax;
    }
}
