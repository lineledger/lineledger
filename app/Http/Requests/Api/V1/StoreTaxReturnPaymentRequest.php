<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountSubtype;
use App\Enums\TaxReturnPaymentDirection;
use App\Enums\TaxReturnStatus;
use App\Models\Company;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaxReturnPaymentRequest extends FormRequest
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
            'post' => ['sometimes', 'boolean'],
            'tax_return_id' => [
                'required',
                'integer',
                Rule::exists('tax_returns', 'id')
                    ->where('company_id', $company->id)
                    ->where('status', TaxReturnStatus::Filed->value),
            ],
            'payment_no' => [
                'sometimes',
                'string',
                'max:40',
                Rule::unique('tax_return_payments', 'payment_no')->where('company_id', $company->id),
            ],
            'payment_date' => ['required', 'date'],
            'direction' => ['required', Rule::enum(TaxReturnPaymentDirection::class)],
            'bank_account_id' => [
                'required',
                'integer',
                $inCompany('accounts')->where('subtype', AccountSubtype::Bank->value),
            ],
            'payment_method_id' => ['nullable', 'integer', $inCompany('payment_methods')],
            'reference' => ['nullable', 'string', 'max:120'],
            'net_amount_cents' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'penalty_cents' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'penalty_account_id' => ['nullable', 'integer', $inCompany('accounts')],
            'interest_cents' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'interest_account_id' => ['nullable', 'integer', $inCompany('accounts')],
            'commission_cents' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'commission_account_id' => ['nullable', 'integer', $inCompany('accounts')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $isOutgoing = $this->input('direction') === TaxReturnPaymentDirection::Outgoing->value;

            if ((int) $this->input('penalty_cents', 0) > 0 && ! $this->input('penalty_account_id')) {
                $validator->errors()->add('penalty_account_id', 'A penalty account is required when a penalty amount is set.');
            }

            if ((int) $this->input('interest_cents', 0) > 0 && ! $this->input('interest_account_id')) {
                $validator->errors()->add('interest_account_id', 'An interest account is required when an interest amount is set.');
            }

            if ($isOutgoing && (int) $this->input('commission_cents', 0) > 0 && ! $this->input('commission_account_id')) {
                $validator->errors()->add('commission_account_id', 'A commission account is required when a commission amount is set.');
            }

            if (! $isOutgoing) {
                if ((int) $this->input('penalty_cents', 0) > 0) {
                    $validator->errors()->add('penalty_cents', 'Penalty is only valid on outgoing payments.');
                }
                if ((int) $this->input('commission_cents', 0) > 0) {
                    $validator->errors()->add('commission_cents', 'Commission is only valid on outgoing payments.');
                }
            }
        });
    }
}
