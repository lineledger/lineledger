<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use App\Models\UserPreference;
use App\Support\Preferences\UserPreferences;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('returns the default until a choice is stored', function () {
    expect(UserPreferences::get($this->company, 'banking.register.show_cleared', true))->toBeTrue();

    UserPreferences::put($this->company, 'banking.register.show_cleared', false);

    expect(UserPreferences::get($this->company, 'banking.register.show_cleared', true))->toBeFalse();
});

it('overwrites a stored choice in place rather than adding a row', function () {
    UserPreferences::put($this->company, 'banking.last_account', 7);
    UserPreferences::put($this->company, 'banking.last_account', 9);

    expect(UserPreferences::get($this->company, 'banking.last_account'))->toBe(9)
        ->and(UserPreference::withoutGlobalScopes()->count())->toBe(1);
});

it('forgets a choice when it is set to null', function () {
    UserPreferences::put($this->company, 'banking.last_account', 7);
    UserPreferences::put($this->company, 'banking.last_account', null);

    expect(UserPreferences::get($this->company, 'banking.last_account'))->toBeNull()
        ->and(UserPreference::withoutGlobalScopes()->count())->toBe(0);
});

it('survives the session ending', function () {
    UserPreferences::put($this->company, 'reports.ar-aging.open_only', false);

    session()->flush();

    expect(UserPreferences::get($this->company, 'reports.ar-aging.open_only', true))->toBeFalse();
});

it('keeps each user and each company separate', function () {
    UserPreferences::put($this->company, 'banking.register.show_cleared', false);

    $colleague = User::factory()->create();
    $this->company->members()->attach($colleague, ['role' => CompanyRole::Owner->value]);
    $other = Company::factory()->create();

    expect(UserPreferences::get($other, 'banking.register.show_cleared', true))->toBeTrue();

    $this->actingAs($colleague);

    expect(UserPreferences::get($this->company, 'banking.register.show_cleared', true))->toBeTrue();
});

it('reads the default and writes nothing without a signed-in user', function () {
    auth()->logout();

    UserPreferences::put($this->company, 'banking.last_account', 7);

    expect(UserPreferences::get($this->company, 'banking.last_account', 'fallback'))->toBe('fallback')
        ->and(UserPreference::withoutGlobalScopes()->count())->toBe(0);
});
