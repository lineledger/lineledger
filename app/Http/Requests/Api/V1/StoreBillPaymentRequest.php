<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\BillType;
use App\Models\Bill;
use App\Models\Company;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBillPaymentRequest extends FormRequest
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

        $contactRole = $this->input('payment_type') === BillType::Reimbursement->value
            ? 'is_employee'
            : 'is_vendor';

        return [
            'post' => ['sometimes', 'boolean'],
            'payment_type' => ['sometimes', Rule::enum(BillType::class)],
            'contact_id' => [
                'required',
                'integer',
                Rule::exists('contacts', 'id')
                    ->where('company_id', $company->id)
                    ->where($contactRole, true),
            ],
            'payment_no' => ['nullable', 'string', 'max:40'],
            'payment_date' => ['required', 'date'],
            'paid_from_account_id' => ['required', 'integer', $inCompany('accounts')],
            'payment_method_id' => ['nullable', 'integer', $inCompany('payment_methods')],
            'reference' => ['nullable', 'string', 'max:100'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'memo' => ['nullable', 'string'],

            'applications' => ['nullable', 'array', 'max:1000'],
            'applications.*.bill_id' => ['required', 'integer', $inCompany('bills')],
            'applications.*.amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $applied = collect($this->input('applications', []))->sum('amount_cents');
            $total = (int) $this->input('amount_cents', 0);

            if ($applied > $total) {
                $v->errors()->add('applications', 'Sum of application amounts cannot exceed the payment amount.');
            }

            $contactId = (int) $this->input('contact_id', 0);
            $type = $this->input('payment_type', BillType::Vendor->value);

            foreach ((array) $this->input('applications', []) as $i => $app) {
                $billId = $app['bill_id'] ?? null;
                if (! $billId) {
                    continue;
                }

                $exists = Bill::query()
                    ->where('id', $billId)
                    ->where('contact_id', $contactId)
                    ->where('bill_type', $type)
                    ->whereIn('status', ['posted', 'partial'])
                    ->exists();

                if (! $exists) {
                    $v->errors()->add("applications.$i.bill_id", 'Bill is not open or does not belong to the same contact.');
                }
            }
        });
    }
}
