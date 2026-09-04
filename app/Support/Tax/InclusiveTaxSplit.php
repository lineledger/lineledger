<?php

namespace App\Support\Tax;

use App\Models\TaxCode;
use InvalidArgumentException;

/**
 * Splits a tax-INCLUSIVE gross amount into net + primary tax + secondary tax,
 * the way this ledger computes tax elsewhere (each tax = round(net × rate),
 * both on the same net, non-compounding — see {@see TaxCode::taxFor()}).
 *
 * The invariant callers rely on: `net + tax + secondary == gross`, exactly,
 * by construction (the taxes are computed on a first estimate of the net and
 * the net is then taken as the remainder). That is what lets an Expense built
 * from a bank-statement amount post with its payment leg equal to the
 * statement to the cent, without a rounding plug.
 *
 * Because `taxFor(net)` can differ from the returned tax by a cent, callers
 * must persist the returned taxes as explicit overrides rather than let the
 * tax code recompute them from the net.
 */
final class InclusiveTaxSplit
{
    /**
     * @return array{net_cents: int, tax_cents: int, secondary_tax_cents: int}
     */
    public static function split(int $grossCents, ?TaxCode $primary, ?TaxCode $secondary = null): array
    {
        if ($grossCents < 0) {
            throw new InvalidArgumentException('A tax-inclusive amount cannot be negative.');
        }

        $primaryRate = $primary !== null ? (float) $primary->rate_basis_points : 0.0;
        $secondaryRate = $secondary !== null ? (float) $secondary->rate_basis_points : 0.0;

        if ($grossCents === 0 || ($primaryRate === 0.0 && $secondaryRate === 0.0)) {
            return ['net_cents' => $grossCents, 'tax_cents' => 0, 'secondary_tax_cents' => 0];
        }

        $estimatedNet = (int) round($grossCents * 10000 / (10000 + $primaryRate + $secondaryRate));

        $tax = $primary !== null ? $primary->taxFor($estimatedNet) : 0;
        $secondaryTax = $secondary !== null ? $secondary->taxFor($estimatedNet) : 0;

        return [
            'net_cents' => $grossCents - $tax - $secondaryTax,
            'tax_cents' => $tax,
            'secondary_tax_cents' => $secondaryTax,
        ];
    }
}
