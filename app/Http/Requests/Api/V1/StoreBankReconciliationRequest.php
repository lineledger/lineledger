<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountSubtype;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBankReconciliationRequest extends FormRequest
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
            'account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')
                    ->where('company_id', $company->id)
                    ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value]),
            ],
            'statement_date' => ['required', 'date'],
            'ending_balance_cents' => ['required', 'integer', 'min:-999999999999', 'max:999999999999'],

            'service_charge' => ['nullable', 'array'],
            'service_charge.cents' => ['required_with:service_charge', 'integer', 'min:1', 'max:999999999999'],
            'service_charge.date' => ['nullable', 'date'],
            'service_charge.account_id' => ['required_with:service_charge', 'integer', $inCompany('accounts')],

            'interest_earned' => ['nullable', 'array'],
            'interest_earned.cents' => ['required_with:interest_earned', 'integer', 'min:1', 'max:999999999999'],
            'interest_earned.date' => ['nullable', 'date'],
            'interest_earned.account_id' => ['required_with:interest_earned', 'integer', $inCompany('accounts')],
        ];
    }
}
