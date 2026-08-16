<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountType;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContactRequest extends FormRequest
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

        $belongsToCompany = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $company->id);

        return [
            'display_name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'account_no' => ['nullable', 'string', 'max:100'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'invoice_emails_enabled' => ['sometimes', 'boolean'],
            'reminder_emails_enabled' => ['sometimes', 'boolean'],

            'billing_address' => ['nullable', 'array'],
            'billing_address.line1' => ['nullable', 'string', 'max:255'],
            'billing_address.line2' => ['nullable', 'string', 'max:255'],
            'billing_address.city' => ['nullable', 'string', 'max:255'],
            'billing_address.region' => ['nullable', 'string', 'max:255'],
            'billing_address.postal_code' => ['nullable', 'string', 'max:255'],
            'billing_address.country' => ['nullable', 'string', 'size:2'],

            'shipping_address' => ['nullable', 'array'],
            'shipping_address.line1' => ['nullable', 'string', 'max:255'],
            'shipping_address.line2' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['nullable', 'string', 'max:255'],
            'shipping_address.region' => ['nullable', 'string', 'max:255'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:255'],
            'shipping_address.country' => ['nullable', 'string', 'size:2'],

            'default_terms_id' => ['nullable', 'integer', $belongsToCompany('payment_terms')->where('is_active', true)],
            'default_tax_code_id' => ['nullable', 'integer', $belongsToCompany('tax_codes')->where('is_active', true)],
            'default_income_account_id' => ['nullable', 'integer', $belongsToCompany('accounts')->where('is_active', true)->where('type', AccountType::Income->value)],
            'default_expense_account_id' => ['nullable', 'integer', $belongsToCompany('accounts')->where('is_active', true)->where('type', AccountType::Expense->value)],
        ];
    }
}
