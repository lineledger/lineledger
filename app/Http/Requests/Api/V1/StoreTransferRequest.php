<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\TransferAccount;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransferRequest extends FormRequest
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
        return [
            'post' => ['sometimes', 'boolean'],
            'from_account_id' => ['required', 'integer', new TransferAccount],
            'to_account_id' => ['required', 'integer', new TransferAccount, 'different:from_account_id'],
            'transfer_no' => ['nullable', 'string', 'max:40'],
            'transfer_date' => ['required', 'date'],
            'from_amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'to_amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'memo' => ['nullable', 'string'],
        ];
    }
}
