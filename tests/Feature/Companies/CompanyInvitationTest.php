<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Support\Legal\LegalDocuments;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('company invitations can be created', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $company = Company::factory()->create();

    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($owner);

    Livewire::test('pages::companies.invite-member-modal', ['company' => $company])
        ->set('inviteEmail', 'invited@example.com')
        ->set('inviteRole', CompanyRole::Accountant->value)
        ->call('createInvitation')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('company_invitations', [
        'company_id' => $company->id,
        'email' => 'invited@example.com',
        'role' => CompanyRole::Accountant->value,
    ]);
});

test('company invitations cannot be created by members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $company = Company::factory()->create();

    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);

    $this->actingAs($member);

    Livewire::test('pages::companies.invite-member-modal', ['company' => $company])
        ->set('inviteEmail', 'invited@example.com')
        ->set('inviteRole', CompanyRole::Accountant->value)
        ->call('createInvitation')
        ->assertForbidden();
});

test('company invitations can be cancelled by owner', function () {
    $owner = User::factory()->create();
    $company = Company::factory()->create();

    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);

    $invitation = CompanyInvitation::factory()->create([
        'company_id' => $company->id,
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($owner);

    Livewire::test('pages::companies.cancel-invitation-modal', ['company' => $company])
        ->set('invitationCode', $invitation->code)
        ->call('cancelInvitation')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('company_invitations', [
        'id' => $invitation->id,
    ]);
});

test('company invitations can be accepted', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $company = Company::factory()->create();

    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);

    $invitation = CompanyInvitation::factory()->create([
        'company_id' => $company->id,
        'email' => 'invited@example.com',
        'role' => CompanyRole::Accountant,
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitedUser);

    $response = Livewire::test('pages::companies.accept-invitation', [
        'invitation' => $invitation,
    ]);

    $response->assertRedirect(route('dashboard'));

    expect($invitation->fresh()->accepted_at)->not->toBeNull();
    expect($invitedUser->fresh()->belongsToCompany($company))->toBeTrue();
});

test('company invitations cannot be accepted by user that wasnt invited', function () {
    $owner = User::factory()->create();
    $uninvitedUser = User::factory()->create(['email' => 'uninvited@example.com']);
    $company = Company::factory()->create();

    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);

    $invitation = CompanyInvitation::factory()->create([
        'company_id' => $company->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($uninvitedUser);

    $response = Livewire::test('pages::companies.accept-invitation', [
        'invitation' => $invitation,
    ]);

    $response->assertHasErrors(['invitation']);

    expect($uninvitedUser->fresh()->belongsToCompany($company))->toBeFalse();
});

test('an invited user with no company can accept over HTTP without being bounced to onboarding', function () {
    // The realistic case: an invited user has no company of their own yet, so
    // the request passes through EnsureUserHasCompany. That middleware must
    // exempt invitations.accept — otherwise it redirects to the onboarding
    // wizard before the invitation is ever accepted. (The existing Livewire::test
    // cases bypass middleware and use factory users that already have a company,
    // so they cannot catch this.)
    $company = Company::factory()->create();

    $invitation = CompanyInvitation::factory()->create([
        'company_id' => $company->id,
        'email' => 'invited@example.com',
        'role' => CompanyRole::Accountant,
    ]);

    // make()->save() skips the factory's afterCreating hook, so this user has no
    // company at all — mirroring a freshly registered invitee.
    $invitedUser = User::factory()->make(['email' => 'invited@example.com']);
    $invitedUser->save();

    // This invitee mirrors a freshly registered user, who accepts the legal
    // terms during registration; record that so the EnsureLegalAcceptance gate
    // doesn't intercept the invitation flow under test.
    app(LegalDocuments::class)->record($invitedUser, ['terms', 'privacy']);

    $this->actingAs($invitedUser)
        ->get(route('invitations.accept', $invitation))
        ->assertRedirect("/{$company->slug}/dashboard");

    expect($invitation->fresh()->accepted_at)->not->toBeNull();
    expect($invitedUser->fresh()->belongsToCompany($company))->toBeTrue();
});

test('a bad invitation does not 500 for a company-less user over HTTP', function () {
    // The error branch throws a ValidationException from mount(); confirm that
    // surfaces as a redirect rather than a 500 (e.g. from rendering a company
    // scoped layout) when the user has no company.
    $company = Company::factory()->create();

    $invitation = CompanyInvitation::factory()->expired()->create([
        'company_id' => $company->id,
        'email' => 'invited@example.com',
    ]);

    $invitedUser = User::factory()->make(['email' => 'invited@example.com']);
    $invitedUser->save();

    // This invitee mirrors a freshly registered user, who accepts the legal
    // terms during registration; record that so the EnsureLegalAcceptance gate
    // doesn't intercept the invitation flow under test.
    app(LegalDocuments::class)->record($invitedUser, ['terms', 'privacy']);

    $response = $this->actingAs($invitedUser)
        ->get(route('invitations.accept', $invitation));

    expect($response->status())->not->toBe(500);
    expect($invitedUser->fresh()->belongsToCompany($company))->toBeFalse();
});

test('expired invitations cannot be accepted', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $company = Company::factory()->create();

    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);

    $invitation = CompanyInvitation::factory()->expired()->create([
        'company_id' => $company->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitedUser);

    $response = Livewire::test('pages::companies.accept-invitation', [
        'invitation' => $invitation,
    ]);

    $response->assertHasErrors(['invitation']);

    expect($invitedUser->fresh()->belongsToCompany($company))->toBeFalse();
});
