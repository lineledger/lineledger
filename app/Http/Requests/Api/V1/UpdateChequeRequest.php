<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountSubtype;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChequeRequest extends FormRequest
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
            'cheque_no' => ['required', 'string', 'max:40'],
            'cheque_date' => ['required', 'date'],
            'payee_contact_id' => ['nullable', 'integer', $inCompany('contacts')],
            'payee_name' => ['required_without:payee_contact_id', 'nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string'],

            'lines' => ['required', 'array', 'min:1', 'max:1000'],
            'lines.*.account_id' => ['required', 'integer', $inCompany('accounts')],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.amount_cents' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'lines.*.tax_code_id' => ['nullable', 'integer', $inCompany('tax_codes')],
            'lines.*.secondary_tax_code_id' => ['nullable', 'integer', $inCompany('tax_codes')],
        ];
    }
}
