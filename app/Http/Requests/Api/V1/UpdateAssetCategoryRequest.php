<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetCategoryRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('asset_categories', 'name')
                    ->where('company_id', $company->id)
                    ->ignore($this->route('assetCategory'))
                    ->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string'],
            'default_asset_account_id' => ['nullable', 'integer', $inCompany('accounts')->where('subtype', AccountSubtype::FixedAsset->value)],
            'default_accumulated_depreciation_account_id' => ['nullable', 'integer', $inCompany('accounts')->where('subtype', AccountSubtype::FixedAsset->value)],
            'default_depreciation_expense_account_id' => ['nullable', 'integer', $inCompany('accounts')->where('type', AccountType::Expense->value)],
            'default_useful_life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
