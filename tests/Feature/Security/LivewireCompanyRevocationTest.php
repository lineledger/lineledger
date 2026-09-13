<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Livewire\Livewire;

/**
 * Finding #7 (2026-08-18 security review): Livewire's AJAX update endpoint
 * bypasses EnsureCompanyMembership, so a user removed from a company mid-session
 * could keep reading/writing its data through an already-open tab until the
 * tab refreshed or the session was killed server-side. BindCurrentCompanyHook
 * now re-checks membership on every hydrate/call/update/render.
 */
it('lets a current company member continue making Livewire AJAX calls', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user);
    app()->forgetInstance('current_company');

    Livewire::test('pages::customers.index', ['company' => $company])
        ->set('f_display_name', 'Acme Customer')
        ->call('save')
        ->assertHasNoErrors();

    $row = Contact::withoutGlobalScopes()->where('display_name', 'Acme Customer')->first();
    expect($row)->not->toBeNull();
});

it('403s a revoked member’s next Livewire AJAX call on an already-open tab', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user);
    app()->forgetInstance('current_company');

    // Mount succeeds while membership is still active — mirrors the tab
    // already being open before an admin revokes access.
    $component = Livewire::test('pages::customers.index', ['company' => $company]);

    $company->members()->detach($user);

    // Livewire's test harness renders a thrown HttpException as a real 403
    // response rather than letting it bubble up as a PHP exception, so the
    // very first post-revocation request already fails here — the chained
    // `.call('save')` from the "still a member" test above never gets a
    // chance to run.
    $component->set('f_display_name', 'Should Not Save')->assertForbidden();

    expect(Contact::withoutGlobalScopes()->where('display_name', 'Should Not Save')->exists())->toBeFalse();
});

it('does not affect portal (customer-guard) Livewire components', function () {
    $company = Company::factory()->create();
    app()->instance('current_company', $company);

    $contact = Contact::create(['company_id' => $company->id, 'display_name' => 'Alice', 'email' => 'alice@x.test', 'is_customer' => true]);

    $this->actingAs($contact, 'customer');

    Livewire::test('pages::portal.dashboard', ['company' => $company])
        ->assertOk();

    app()->forgetInstance('current_company');
});
