<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\BillType;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBillRequest extends FormRequest
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

        // Determine the expected contact role from the bound bill's type
        // (falling back to a provided bill_type, then vendor).
        $bill = $this->route('bill');
        $type = $bill?->bill_type
            ?? (
                $this->input('bill_type') !== null
                    ? BillType::from($this->input('bill_type'))
                    : BillType::Vendor
            );

        $contactRole = $type === BillType::Reimbursement ? 'is_employee' : 'is_vendor';

        return [
            'contact_id' => [
                'required',
                'integer',
                Rule::exists('contacts', 'id')
                    ->where('company_id', $company->id)
                    ->where($contactRole, true),
            ],
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
