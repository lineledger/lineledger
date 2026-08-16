<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountSubtype;
use App\Models\Company;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepositRequest extends FormRequest
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
            'bank_account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')
                    ->where('company_id', $company->id)
                    ->where('subtype', AccountSubtype::Bank->value),
            ],
            'deposit_no' => ['nullable', 'string', 'max:40'],
            'deposit_date' => ['required', 'date'],
            'memo' => ['nullable', 'string'],

            'lines' => ['required', 'array', 'min:1', 'max:1000'],
            'lines.*.customer_receipt_id' => ['nullable', 'integer', $inCompany('customer_receipts')],
            'lines.*.account_id' => ['nullable', 'integer', $inCompany('accounts')],
            'lines.*.contact_id' => ['nullable', 'integer', $inCompany('contacts')],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.amount_cents' => ['nullable', 'integer', 'min:1', 'max:999999999999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach ((array) $this->input('lines', []) as $i => $line) {
                $hasReceipt = ! empty($line['customer_receipt_id']);
                $hasAccount = ! empty($line['account_id']);

                if (! $hasReceipt && ! $hasAccount) {
                    $v->errors()->add("lines.$i", 'Each deposit line needs a customer_receipt_id or an account_id.');

                    continue;
                }

                if (! $hasReceipt && empty($line['amount_cents'])) {
                    $v->errors()->add("lines.$i.amount_cents", 'Account deposit lines require an amount_cents.');
                }
            }
        });
    }
}
