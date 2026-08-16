<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app()->bound('current_api_key');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $company = app('current_company');
        assert($company instanceof Company);

        $inCompany = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $company->id);

        return [
            'contact_id' => [
                'required',
                'integer',
                Rule::exists('contacts', 'id')
                    ->where('company_id', $company->id)
                    ->where('is_customer', true),
            ],
            'receipt_date' => ['required', 'date'],
            'deposit_to_account_id' => ['required', 'integer', $inCompany('accounts')],
            'payment_method_id' => ['nullable', 'integer', $inCompany('payment_methods')],
            'reference' => ['nullable', 'string', 'max:100'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'memo' => ['nullable', 'string'],

            'applications' => ['nullable', 'array', 'max:1000'],
            'applications.*.invoice_id' => [
                'required',
                'integer',
                Rule::exists('invoices', 'id')
                    ->where('company_id', $company->id)
                    // 'paid' is allowed on update: a receipt being edited may
                    // already have driven its target invoice to paid.
                    ->whereIn('status', ['posted', 'partial', 'paid']),
            ],
            'applications.*.amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $applied = collect($this->input('applications', []))->sum('amount_cents');
            $total = (int) $this->input('amount_cents', 0);

            if ($applied > $total) {
                $v->errors()->add('applications', 'Sum of application amounts cannot exceed the receipt amount.');
            }

            $contactId = (int) $this->input('contact_id', 0);
            foreach ((array) $this->input('applications', []) as $i => $app) {
                $invoiceId = $app['invoice_id'] ?? null;
                if (! $invoiceId) {
                    continue;
                }

                $exists = Invoice::query()
                    ->where('id', $invoiceId)
                    ->where('contact_id', $contactId)
                    ->exists();

                if (! $exists) {
                    $v->errors()->add("applications.$i.invoice_id", 'Invoice does not belong to the same customer.');
                }
            }
        });
    }
}
