<?php

namespace App\Actions\Banking;

use App\Enums\ChequeStatus;
use App\Models\Cheque;
use App\Models\Contact;
use App\Models\TaxCode;
use Illuminate\Support\Facades\DB;

/**
 * Builds or updates an expense cheque header and its line items, recalculating
 * the cheque amount. Shared by the Livewire form and the API. Does NOT post —
 * the caller decides whether to hand the result to ChequePoster.
 *
 * Only draft cheques may be edited; ChequePoster has no repost path, so a
 * posted cheque must be voided and recreated.
 *
 * Expected $data shape (cents-based, framework-agnostic):
 *   bank_account_id:  int
 *   cheque_no:        string
 *   cheque_date:      string
 *   payee_contact_id: ?int
 *   payee_name:       ?string  (null → resolved from payee contact)
 *   memo:             ?string
 *   lines: array<int, array{
 *     account_id: int, description: ?string, amount_cents: int, tax_code_id: ?int,
 *     tax_override_cents: ?int, class_id: ?int, location_id: ?int
 *   }>
 *
 * tax_override_cents, when non-null, is the exact tax the user typed and wins
 * over the tax code's computed amount.
 */
final class SaveCheque
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Cheque $cheque = null): Cheque
    {
        return DB::transaction(function () use ($data, $cheque): Cheque {
            $payeeName = $data['payee_name'] ?? null;

            if (! $payeeName && ! empty($data['payee_contact_id'])) {
                $payeeName = Contact::find($data['payee_contact_id'])?->display_name;
            }

            $header = [
                'bank_account_id' => $data['bank_account_id'],
                'cheque_no' => $data['cheque_no'],
                'cheque_date' => $data['cheque_date'],
                'payee_contact_id' => $data['payee_contact_id'] ?? null,
                'payee_name' => $payeeName,
                'memo' => $data['memo'] ?? null,
            ];

            if ($cheque && $cheque->exists) {
                $cheque->update($header);
            } else {
                $cheque = Cheque::create($header + [
                    'status' => ChequeStatus::Draft,
                ]);
            }

            $cheque->lines()->delete();

            foreach (array_values($data['lines']) as $index => $line) {
                $amountCents = (int) $line['amount_cents'];
                $taxCode = isset($line['tax_code_id'])
                    ? TaxCode::withoutGlobalScopes()->where('company_id', app('current_company')->id)->find($line['tax_code_id'])
                    : null;

                $secondaryTaxCode = isset($line['secondary_tax_code_id'])
                    ? TaxCode::withoutGlobalScopes()->where('company_id', app('current_company')->id)->find($line['secondary_tax_code_id'])
                    : null;

                $override = $line['tax_override_cents'] ?? null;
                $taxCents = $override !== null
                    ? (int) $override
                    : ($taxCode ? $taxCode->taxFor($amountCents) : 0);

                $secondaryOverride = $line['secondary_tax_override_cents'] ?? null;
                $secondaryTaxCents = $secondaryOverride !== null
                    ? (int) $secondaryOverride
                    : ($secondaryTaxCode ? $secondaryTaxCode->taxFor($amountCents) : 0);

                $cheque->lines()->create([
                    'account_id' => $line['account_id'],
                    'description' => $line['description'] ?? null,
                    'amount_cents' => $amountCents,
                    'tax_code_id' => $taxCode?->id,
                    'tax_cents' => $taxCents,
                    'tax_override_cents' => $override !== null ? (int) $override : null,
                    'secondary_tax_code_id' => $secondaryTaxCode?->id,
                    'secondary_tax_cents' => $secondaryTaxCents,
                    'secondary_tax_override_cents' => $secondaryOverride !== null ? (int) $secondaryOverride : null,
                    'line_order' => $index,
                    'class_id' => $line['class_id'] ?? null,
                    'location_id' => $line['location_id'] ?? null,
                ]);
            }

            $cheque->refresh();
            $cheque->recalculateAmount();

            return $cheque;
        });
    }
}
