<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'income_account_id' => ['required', 'integer', $inCompany('accounts')],
            'expense_account_id' => ['nullable', 'integer', $inCompany('accounts')],
            'default_tax_code_id' => ['nullable', 'integer', $inCompany('tax_codes')],
            'default_secondary_tax_code_id' => ['nullable', 'integer', $inCompany('tax_codes')],
            'default_price_cents' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'is_active' => ['sometimes', 'boolean'],

            'track_inventory' => ['sometimes', 'boolean'],
            'inventory_asset_account_id' => ['required_if:track_inventory,true', 'nullable', 'integer', $inCompany('accounts')],
            'cogs_account_id' => ['required_if:track_inventory,true', 'nullable', 'integer', $inCompany('accounts')],
            'reorder_point' => ['nullable', 'numeric', 'min:0'],
            'opening_qty' => ['nullable', 'numeric', 'min:0'],
            'opening_cost_cents' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ];
    }
}
