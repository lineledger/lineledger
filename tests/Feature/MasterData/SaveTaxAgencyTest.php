<?php

use App\Actions\MasterData\SaveTaxAgency;
use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\TaxAgency;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

it('creates a Tax Payable account named after the authority when none is given', function () {
    $agency = app(SaveTaxAgency::class)->handle([
        'name' => 'Custom Authority',
    ]);

    expect($agency->payable_account_id)->not->toBeNull()
        ->and($agency->payableAccount->subtype)->toBe(AccountSubtype::TaxPayable)
        ->and($agency->payableAccount->name)->toBe('Custom Authority Payable')
        ->and($agency->payableAccount->code)->toStartWith('22');
});

it('honours an explicit payable account name', function () {
    $agency = app(SaveTaxAgency::class)->handle([
        'name' => 'Border Services',
        'payable_account_name' => 'Excise Duty Payable',
    ]);

    expect($agency->payableAccount->name)->toBe('Excise Duty Payable');
});

it('reuses an explicitly chosen account without creating a new one', function () {
    $existing = Account::withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('subtype', AccountSubtype::TaxPayable->value)
        ->orderBy('code')
        ->firstOrFail();

    $before = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->count();

    $agency = app(SaveTaxAgency::class)->handle([
        'name' => 'Reuses Account',
        'payable_account_id' => $existing->id,
    ]);

    expect($agency->payable_account_id)->toBe($existing->id)
        ->and(Account::withoutGlobalScopes()->where('company_id', $this->company->id)->count())->toBe($before);
});

it('leaves the existing account untouched when updating without an account id', function () {
    $agency = TaxAgency::withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->firstOrFail();
    $originalAccountId = $agency->payable_account_id;

    app(SaveTaxAgency::class)->handle([
        'name' => 'Renamed Agency',
    ], $agency);

    expect($agency->fresh()->name)->toBe('Renamed Agency')
        ->and($agency->fresh()->payable_account_id)->toBe($originalAccountId);
});

it('allocates a distinct code for each auto-created account', function () {
    $first = app(SaveTaxAgency::class)->handle(['name' => 'Authority One']);
    $second = app(SaveTaxAgency::class)->handle(['name' => 'Authority Two']);

    expect($second->payableAccount->code)->not->toBe($first->payableAccount->code);
});
