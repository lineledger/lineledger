<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaxAgencyRequest extends FormRequest
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

        return [
            'name' => ['required', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:50'],
            'payable_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $company->id)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
