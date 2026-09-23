<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\TaxReturnAdjustmentKind;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaxReturnRequest extends FormRequest
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
            'tax_agency_id' => [
                'required',
                'integer',
                Rule::exists('tax_agencies', 'id')->where('company_id', $company->id),
            ],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'filing_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Both optional: omitted leaves the saved ones as they are, an
            // empty list clears them.
            'excluded_journal_line_ids' => ['sometimes', 'array'],
            'excluded_journal_line_ids.*' => ['integer'],
            'adjustments' => ['sometimes', 'array'],
            'adjustments.*.kind' => ['required', Rule::enum(TaxReturnAdjustmentKind::class)],
            'adjustments.*.account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where('company_id', $company->id),
            ],
            'adjustments.*.amount_cents' => ['required', 'integer', 'not_in:0'],
            'adjustments.*.memo' => ['nullable', 'string', 'max:255'],
        ];
    }
}
