<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountSubtype;
use App\Enums\AssetStatus;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetRequest extends FormRequest
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
            'asset_no' => [
                'sometimes',
                'string',
                'max:40',
                Rule::unique('assets', 'asset_no')->where('company_id', $company->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'asset_category_id' => ['nullable', 'integer', $inCompany('asset_categories')],
            'asset_account_id' => [
                'required',
                'integer',
                $inCompany('accounts')->where('subtype', AccountSubtype::FixedAsset->value),
            ],
            'accumulated_depreciation_account_id' => ['nullable', 'integer', $inCompany('accounts')],
            'depreciation_expense_account_id' => ['nullable', 'integer', $inCompany('accounts')],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'acquired_date' => ['required', 'date'],
            'in_service_date' => ['nullable', 'date'],
            'cost_cents' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'salvage_value_cents' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'status' => ['sometimes', Rule::enum(AssetStatus::class)],
            'disposed_at' => [
                'nullable',
                'date',
                Rule::requiredIf(fn () => in_array($this->input('status'), [
                    AssetStatus::Disposed->value,
                    AssetStatus::Sold->value,
                    AssetStatus::Lost->value,
                ], true)),
            ],
            'disposal_notes' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
