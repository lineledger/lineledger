<?php

namespace App\Http\Requests\Api\V1;

use App\Actions\Sales\FulfillSalesOrder;
use Illuminate\Foundation\Http\FormRequest;

class FulfillSalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app()->bound('current_api_key');
    }

    /**
     * Quantities to invoice, keyed by sales_order_line_id. Over-fulfilment and
     * unknown line ids are enforced in {@see FulfillSalesOrder}
     * against the order's live backordered amounts.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['required', 'numeric', 'gt:0', 'max:1000000'],
        ];
    }
}
