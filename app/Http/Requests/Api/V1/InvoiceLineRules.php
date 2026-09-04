<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;

/**
 * Shared cross-line validation for invoice lines. A line's unit price may be
 * negative (a discount or credit against the invoice), but the lines as a whole
 * must still net to a positive total — a net credit is a credit memo, and the
 * poster's "zero total" exception would mislead an integrator who sent one.
 */
final class InvoiceLineRules
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function validatePositiveTotal(Validator $validator, array $lines): void
    {
        // A malformed line already has a precise field error; don't stack a
        // misleading total error on top of it.
        if ($validator->errors()->hasAny(['lines', 'lines.*'])) {
            return;
        }

        $total = 0;

        foreach ($lines as $line) {
            // Same scaled-integer math as TaxCalculator::line(), so the guard
            // sees exactly the subtotal the poster will.
            $qtyScaled = (int) round(((float) ($line['quantity'] ?? 0)) * 10000);
            $total += intdiv($qtyScaled * (int) ($line['unit_price_cents'] ?? 0), 10000);
        }

        if ($total <= 0) {
            $validator->errors()->add(
                'lines',
                'The invoice total must be greater than zero. Use a credit memo for a net credit.',
            );
        }
    }
}
