<?php

namespace App\Actions\Purchasing;

use App\Enums\ExpenseStatus;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\TaxCode;
use Illuminate\Support\Facades\DB;

/**
 * Builds or updates a pay-now expense header and its line items, recalculating
 * the amount. Shared by the Livewire form and the API. Does NOT post — the
 * caller decides whether to hand the result to ExpensePoster.
 *
 * Only draft expenses may be edited; ExpensePoster has no repost path, so a
 * posted expense must be voided and recreated (mirrors the cheque flow).
 *
 * Expected $data shape (cents-based, framework-agnostic):
 *   payment_account_id: int       (bank asset OR credit-card liability)
 *   payment_method_id:  ?int
 *   reference:          ?string   (confirmation / cheque number, optional)
 *   expense_date:       string
 *   payee_contact_id:   ?int
 *   payee_name:         ?string   (null → resolved from payee contact)
 *   memo:               ?string
 *   lines: array<int, array{
 *     account_id: int, description: ?string, amount_cents: int, tax_code_id: ?int,
 *     tax_override_cents: ?int, class_id: ?int, location_id: ?int, fund_id: ?int
 *   }>
 *
 * tax_override_cents, when non-null, is the exact tax the user typed and wins
 * over the tax code's computed amount.
 */
final class SaveExpense
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Expense $expense = null): Expense
    {
        return DB::transaction(function () use ($data, $expense): Expense {
            $payeeName = $data['payee_name'] ?? null;

            if (! $payeeName && ! empty($data['payee_contact_id'])) {
                $payeeName = Contact::find($data['payee_contact_id'])?->display_name;
            }

            $header = [
                'payment_account_id' => $data['payment_account_id'],
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'expense_date' => $data['expense_date'],
                'payee_contact_id' => $data['payee_contact_id'] ?? null,
                'payee_name' => $payeeName,
                'memo' => $data['memo'] ?? null,
            ];

            if ($expense && $expense->exists) {
                $expense->update($header);
            } else {
                $expense = Expense::create($header + [
                    'status' => ExpenseStatus::Draft,
                ]);
            }

            $expense->lines()->delete();

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

                $expense->lines()->create([
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
                    'fund_id' => $line['fund_id'] ?? null,
                ]);
            }

            $expense->refresh();
            $expense->recalculateAmount();

            return $expense;
        });
    }
}
