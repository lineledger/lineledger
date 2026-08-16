<?php

namespace App\Actions\Sales;

use App\Models\InvoiceTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Builds or updates a reusable invoice template and its line items. Stores the
 * raw line template only — totals/tax are recomputed when the template is applied
 * to an invoice, so each invoice reflects the tax rates in force at that time.
 *
 * Expected $data shape:
 *   name:      string
 *   is_active: ?bool
 *   lines: array<int, array{
 *     item_id: ?int, account_id: ?int, description: ?string,
 *     quantity: string|int|float, unit_price_cents: int,
 *     line_discount_pct: ?(string|float), line_markup_pct: ?(string|float),
 *     tax_code_id: ?int, secondary_tax_code_id: ?int, class_id: ?int, location_id: ?int
 *   }>
 */
final class SaveInvoiceTemplate
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?InvoiceTemplate $template = null): InvoiceTemplate
    {
        return DB::transaction(function () use ($data, $template): InvoiceTemplate {
            $header = [
                'name' => $data['name'],
                'is_active' => $data['is_active'] ?? true,
            ];

            if ($template && $template->exists) {
                $template->update($header);
            } else {
                $template = InvoiceTemplate::create($header);
            }

            $template->lines()->delete();

            foreach (array_values($data['lines']) as $index => $line) {
                $template->lines()->create([
                    'company_id' => $template->company_id,
                    'item_id' => $line['item_id'] ?? null,
                    'account_id' => $line['account_id'] ?? null,
                    'description' => $line['description'] ?? null,
                    'quantity' => $line['quantity'],
                    'unit_price_cents' => (int) $line['unit_price_cents'],
                    'line_discount_pct' => $line['line_discount_pct'] ?? null,
                    'line_markup_pct' => $line['line_markup_pct'] ?? null,
                    'tax_code_id' => $line['tax_code_id'] ?? null,
                    'secondary_tax_code_id' => $line['secondary_tax_code_id'] ?? null,
                    'class_id' => $line['class_id'] ?? null,
                    'location_id' => $line['location_id'] ?? null,
                    'line_order' => $index,
                ]);
            }

            return $template->refresh();
        });
    }
}
