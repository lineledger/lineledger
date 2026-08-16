<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountSubtype;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTransferRequest extends FormRequest
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

        $bankAccount = fn () => [
            'required',
            'integer',
            Rule::exists('accounts', 'id')
                ->where('company_id', $company->id)
                ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value]),
        ];

        return [
            'from_account_id' => $bankAccount(),
            'to_account_id' => [...$bankAccount(), 'different:from_account_id'],
            'transfer_no' => ['nullable', 'string', 'max:40'],
            'transfer_date' => ['required', 'date'],
            'from_amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'to_amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'memo' => ['nullable', 'string'],
        ];
    }
}
