<?php

use App\Enums\Country;
use App\Models\Account;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\TaxAgency;
use App\Models\TaxCode;

test('a Canadian company is seeded with the GST/HST chart and CRA tax codes', function () {
    $company = Company::factory()->forCountry(Country::Canada)->create();

    $accounts = Account::withoutGlobalScopes()->where('company_id', $company->id)->pluck('name', 'code');
    expect($accounts['1000'])->toBe('Chequing');
    expect($accounts['2200'])->toBe('GST/HST Payable');
    expect($accounts['2210'])->toBe('PST Payable');
    expect($accounts['2700'])->toBe('Bank Loan');

    $agencies = TaxAgency::withoutGlobalScopes()->where('company_id', $company->id)->pluck('name');
    expect($agencies)->toContain('Canada Revenue Agency');

    $codes = TaxCode::withoutGlobalScopes()->where('company_id', $company->id)->pluck('code');
    expect($codes)->toContain('GST');
    expect($codes)->toContain('HST-ON');

    $methods = PaymentMethod::withoutGlobalScopes()->where('company_id', $company->id)->pluck('name');
    expect($methods)->toContain('Cheque');
    expect($methods)->toContain('E-transfer');
});

test('a US company is seeded with the Sales Tax chart and no Canadian-only accounts', function () {
    $company = Company::factory()->forCountry(Country::UnitedStates)->create();

    $accounts = Account::withoutGlobalScopes()->where('company_id', $company->id)->pluck('name', 'code');
    expect($accounts['1000'])->toBe('Checking');
    expect($accounts['2200'])->toBe('Sales Tax Payable');
    expect($accounts->has('2210'))->toBeFalse();
    expect($accounts['2700'])->toBe('Bank Loan');

    $codes = TaxCode::withoutGlobalScopes()->where('company_id', $company->id)->pluck('code');
    expect($codes)->not->toContain('GST');

    $methods = PaymentMethod::withoutGlobalScopes()->where('company_id', $company->id)->pluck('name');
    expect($methods)->toContain('Check');
    expect($methods)->toContain('ACH');
    expect($methods)->not->toContain('Cheque');
});
