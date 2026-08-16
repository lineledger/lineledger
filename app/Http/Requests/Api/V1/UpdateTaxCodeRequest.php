<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\TaxAppliesTo;
use App\Models\Company;
use App\Models\TaxCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaxCodeRequest extends FormRequest
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

        /** @var TaxCode $taxCode */
        $taxCode = $this->route('taxCode');

        return [
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('tax_codes', 'code')->where('company_id', $company->id)->ignore($taxCode->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'rate_basis_points' => ['required', 'numeric', 'min:0', 'max:100000'],
            'agency_id' => ['nullable', 'integer', Rule::exists('tax_agencies', 'id')->where('company_id', $company->id)],
            'applies_to' => ['required', Rule::enum(TaxAppliesTo::class)],
            'is_recoverable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
