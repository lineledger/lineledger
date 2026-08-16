<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountSubtype;
use App\Enums\CashFlowActivity;
use App\Models\Account;
use App\Models\Company;
use App\Rules\ValidAccountParent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
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

        /** @var Account $account */
        $account = $this->route('account');

        return [
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('accounts', 'code')
                    ->where('company_id', $company->id)
                    ->whereNull('deleted_at')
                    ->ignore($account->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'subtype' => ['required', 'string', Rule::enum(AccountSubtype::class)],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $company->id),
                new ValidAccountParent($company->id, $this->input('subtype'), $account->id),
            ],
            'description' => ['nullable', 'string'],
            'cash_flow_activity' => ['nullable', Rule::enum(CashFlowActivity::class)],
            'default_tax_code_id' => [
                'nullable', 'integer',
                Rule::exists('tax_codes', 'id')->where('company_id', $company->id),
            ],
            'currency_code' => ['nullable', 'string', Rule::in($this->enabledForeignCurrencyCodes($company))],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->currency_code)) {
            $this->merge(['currency_code' => mb_strtoupper(trim($this->currency_code))]);
        }
    }

    /**
     * @return list<string>
     */
    private function enabledForeignCurrencyCodes(Company $company): array
    {
        return $company->currencies()
            ->where('is_home', false)
            ->where('is_active', true)
            ->pluck('currency_code')
            ->map(fn ($c) => mb_strtoupper((string) $c))
            ->all();
    }
}
