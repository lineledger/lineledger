<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountSubtype;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Refund (money out) of a customer's overpayment, issued from an invoice — the
 * API equivalent of the invoice's "Refund to client → by credit card". Pays
 * back the unapplied credit of refunded_receipt_id as a posted NEGATIVE
 * customer receipt (DR Accounts Receivable, CR the deposit account).
 *
 * Shape only: that the payment belongs to the invoice's customer, is posted and
 * still holds the credit is checked by RecordCustomerRefund, which answers 422.
 */
class RefundInvoiceRequest extends FormRequest
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
            'refunded_receipt_id' => [
                'required',
                'integer',
                Rule::exists('customer_receipts', 'id')->where('company_id', $company->id)->whereNull('deleted_at'),
            ],
            'refund_date' => ['required', 'date'],
            'receipt_no' => ['nullable', 'string', 'max:50', Rule::unique('customer_receipts', 'receipt_no')->where('company_id', $company->id)],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'deposit_to_account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')
                    ->where('company_id', $company->id)
                    ->whereIn('subtype', [AccountSubtype::UndepositedFunds->value, AccountSubtype::Bank->value]),
            ],
            'payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')->where('company_id', $company->id)],
            'reference' => ['nullable', 'string', 'max:100'],
            'memo' => ['nullable', 'string'],
        ];
    }
}
