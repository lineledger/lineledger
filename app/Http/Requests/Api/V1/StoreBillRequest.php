<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\BillType;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBillRequest extends FormRequest
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

        $contactRole = $this->input('bill_type') === BillType::Reimbursement->value
            ? 'is_employee'
            : 'is_vendor';

        return [
            'post' => ['sometimes', 'boolean'],
            'bill_type' => ['sometimes', Rule::enum(BillType::class)],
            'contact_id' => [
                'required',
                'integer',
                Rule::exists('contacts', 'id')
                    ->where('company_id', $company->id)
                    ->where($contactRole, true),
            ],
            'bill_no' => ['nullable', 'string', 'max:40'],
            'vendor_reference' => ['nullable', 'string', 'max:100'],
            'bill_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:bill_date'],
            'terms_id' => ['nullable', 'integer', $inCompany('payment_terms')],
            'memo' => ['nullable', 'string'],

            'lines' => ['required', 'array', 'min:1', 'max:1000'],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'lines.*.unit_price_cents' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'lines.*.account_id' => ['required', 'integer', $inCompany('accounts')],
            'lines.*.item_id' => ['nullable', 'integer', $inCompany('items')],
            'lines.*.tax_code_id' => ['nullable', 'integer', $inCompany('tax_codes')],
            'lines.*.secondary_tax_code_id' => ['nullable', 'integer', $inCompany('tax_codes')],
        ];
    }
}
