<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\StockAdjustmentReason;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockAdjustmentRequest extends FormRequest
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
            'post' => ['sometimes', 'boolean'],
            'adjustment_no' => [
                'sometimes',
                'string',
                'max:40',
                Rule::unique('stock_adjustments', 'adjustment_no')->where('company_id', $company->id),
            ],
            'adjustment_date' => ['required', 'date'],
            'reason' => ['required', Rule::enum(StockAdjustmentReason::class)],
            'notes' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1', 'max:1000'],
            'lines.*.item_id' => [
                'required',
                'integer',
                Rule::exists('items', 'id')
                    ->where('company_id', $company->id)
                    ->where('track_inventory', true),
            ],
            'lines.*.qty_change' => ['required', 'numeric', 'not_in:0'],
            'lines.*.unit_cost_cents' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
