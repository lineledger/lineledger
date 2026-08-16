<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Laravel\Fortify\Features;
use Livewire\Livewire;

/**
 * When a company turns on require_two_factor, its owners and admins must have
 * 2FA enabled before using the app (SOC 2 CC6.1). EnforceTwoFactor bounces an
 * un-enrolled privileged user to the security settings page to enroll; it never
 * locks them out, and lower-privilege roles are never affected.
 */
beforeEach(function () {
    if (! Features::enabled(Features::twoFactorAuthentication())) {
        $this->markTestSkipped('Two-factor authentication is not enabled.');
    }
});

function memberOf(Company $company, CompanyRole $role, bool $withTwoFactor = false): User
{
    $user = $withTwoFactor ? User::factory()->withTwoFactor()->create() : User::factory()->create();
    $company->members()->attach($user, ['role' => $role->value]);

    return $user;
}

it('redirects a privileged user without 2FA to enroll when the company requires it', function () {
    $company = Company::factory()->create(['require_two_factor' => true]);
    $owner = memberOf($company, CompanyRole::Owner);

    $this->actingAs($owner)
        ->get(route('accounts.index', ['company' => $company->slug]))
        ->assertRedirect(route('security.edit'));
});

it('allows a privileged user who has enrolled in 2FA', function () {
    $company = Company::factory()->create(['require_two_factor' => true]);
    $owner = memberOf($company, CompanyRole::Owner, withTwoFactor: true);

    $this->actingAs($owner)
        ->get(route('accounts.index', ['company' => $company->slug]))
        ->assertOk();
});

it('does not enforce 2FA on lower-privilege roles', function () {
    $company = Company::factory()->create(['require_two_factor' => true]);
    $accountant = memberOf($company, CompanyRole::Accountant);

    $this->actingAs($accountant)
        ->get(route('accounts.index', ['company' => $company->slug]))
        ->assertOk();
});

it('does not enforce 2FA when the company has not opted in', function () {
    $company = Company::factory()->create(['require_two_factor' => false]);
    $owner = memberOf($company, CompanyRole::Owner);

    $this->actingAs($owner)
        ->get(route('accounts.index', ['company' => $company->slug]))
        ->assertOk();
});

it('persists the require_two_factor toggle from company settings', function () {
    $company = Company::factory()->create(['require_two_factor' => false]);
    $owner = memberOf($company, CompanyRole::Owner, withTwoFactor: true);

    $this->actingAs($owner);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->set('requireTwoFactor', true)
        ->call('updateCompany')
        ->assertHasNoErrors();

    expect($company->fresh()->require_two_factor)->toBeTrue();
});
