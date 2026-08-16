<?php

namespace App\Services\Posting;

use App\Models\TaxCode;

/**
 * Computes line subtotal/tax/total cents from raw decimal-string quantity,
 * cent-integer unit price, an optional per-line discount, and an optional tax code.
 * Quantity * unit price is done in cents; the discount is subtracted before tax, so
 * the returned subtotal is net of discount and tax applies to the discounted amount.
 */
class TaxCalculator
{
    /**
     * Resolve and apply a per-line markup then discount before tax.
     *
     * Markup ($markupPct e.g. "10", or fixed $markupCents) is added to the gross first;
     * the discount ($discountPct or $discountCents) is then taken off the marked-up base.
     * Percent wins over the fixed amount for each. The discount is clamped to [0, base]
     * so a line can never invert. The returned subtotal_cents is the net amount callers
     * persist and the GL posters sum — markup raises that line's own income, so no
     * separate account is needed (a document-level discount is handled by the poster).
     *
     * A line can carry a second tax (e.g. GST + PST/QST in a PST/QST province);
     * the secondary tax is computed on the same discounted subtotal and tracked
     * separately so the two never merge into one combined rate.
     *
     * @return array{subtotal_cents: int, tax_cents: int, secondary_tax_cents: int, total_cents: int, discount_cents: int, markup_cents: int}
     */
    public function line(
        string $quantity,
        int $unitPriceCents,
        ?TaxCode $taxCode = null,
        int $discountCents = 0,
        ?string $discountPct = null,
        int $markupCents = 0,
        ?string $markupPct = null,
        ?TaxCode $secondaryTaxCode = null,
    ): array {
        // Quantity supports up to 4 decimals — scale to int math
        $qtyScaled = (int) round(((float) $quantity) * 10000);
        $gross = (int) intdiv($qtyScaled * $unitPriceCents, 10000);

        $markup = $markupPct !== null && $markupPct !== ''
            ? (int) round($gross * ((float) $markupPct) / 100)
            : $markupCents;
        $markup = max(0, $markup);

        $base = $gross + $markup;

        $discount = $discountPct !== null && $discountPct !== ''
            ? (int) round($base * ((float) $discountPct) / 100)
            : $discountCents;
        $discount = max(0, min($discount, $base));

        $subtotal = $base - $discount;
        $tax = $taxCode ? $taxCode->taxFor($subtotal) : 0;
        $secondaryTax = $secondaryTaxCode ? $secondaryTaxCode->taxFor($subtotal) : 0;

        return [
            'subtotal_cents' => $subtotal,
            'tax_cents' => $tax,
            'secondary_tax_cents' => $secondaryTax,
            'total_cents' => $subtotal + $tax + $secondaryTax,
            'discount_cents' => $discount,
            'markup_cents' => $markup,
        ];
    }
}
