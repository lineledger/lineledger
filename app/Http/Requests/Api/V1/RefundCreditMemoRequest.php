<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountSubtype;
use App\Models\Company;
use App\Models\CreditMemo;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Refund (money out) against a credit memo — the API equivalent of the
 * "Refund to client → by credit card" action, recording a posted NEGATIVE
 * customer receipt (DR Accounts Receivable, CR the deposit account).
 */
class RefundCreditMemoRequest extends FormRequest
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $creditMemo = $this->route('creditMemo');
            if (! $creditMemo instanceof CreditMemo) {
                return;
            }

            $remaining = $creditMemo->remainingRefundableCents();
            if ((int) $this->input('amount_cents', 0) > $remaining) {
                $v->errors()->add('amount_cents', 'Refund cannot exceed the remaining refundable amount of '.number_format($remaining / 100, 2).'.');
            }
        });
    }
}
