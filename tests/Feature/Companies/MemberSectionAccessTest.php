<?php

use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('role presets expose the expected sections', function () {
    expect(CompanyRole::Owner->sections())->toBe(Section::cases());
    expect(CompanyRole::Admin->sections())->toBe(Section::cases());
    expect(CompanyRole::Accountant->sections())->not->toContain(Section::Settings)
        ->and(CompanyRole::Accountant->sections())->toContain(Section::Reports);
    expect(CompanyRole::Custom->sections())->toBe([]);
    expect(CompanyRole::Custom->usesCustomSections())->toBeTrue();
    expect(CompanyRole::Accountant->usesCustomSections())->toBeFalse();
});

test('an owner membership can access every section regardless of stored sections', function () {
    $owner = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);

    expect($owner->canAccessSection($company, Section::Settings))->toBeTrue()
        ->and($owner->canAccessSection($company, Section::Banking))->toBeTrue();
});

test('a custom membership only accesses its stored sections', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->memberships()->create([
        'user_id' => $user->id,
        'role' => CompanyRole::Custom,
        'sections' => [Section::Banking->value],
    ]);

    expect($user->canAccessSection($company, Section::Banking))->toBeTrue()
        ->and($user->canAccessSection($company, Section::Customers))->toBeFalse();
});

test('inviting a custom member persists the selected sections', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($owner);

    Livewire::test('pages::companies.invite-member-modal', ['company' => $company])
        ->set('inviteEmail', 'invited@example.com')
        ->set('inviteRole', CompanyRole::Custom->value)
        ->set('inviteSections', [Section::Banking->value, Section::Reports->value])
        ->call('createInvitation')
        ->assertHasNoErrors();

    $invitation = CompanyInvitation::query()->where('email', 'invited@example.com')->firstOrFail();

    expect($invitation->role)->toBe(CompanyRole::Custom)
        ->and($invitation->sections)->toBe([Section::Banking->value, Section::Reports->value]);
});

test('a preset role invitation stores no custom sections', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($owner);

    Livewire::test('pages::companies.invite-member-modal', ['company' => $company])
        ->set('inviteEmail', 'accountant@example.com')
        ->set('inviteRole', CompanyRole::Accountant->value)
        ->call('createInvitation')
        ->assertHasNoErrors();

    $invitation = CompanyInvitation::query()->where('email', 'accountant@example.com')->firstOrFail();

    expect($invitation->sections)->toBeNull();
});

test('a custom invitation requires at least one section', function () {
    $owner = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($owner);

    Livewire::test('pages::companies.invite-member-modal', ['company' => $company])
        ->set('inviteEmail', 'invited@example.com')
        ->set('inviteRole', CompanyRole::Custom->value)
        ->set('inviteSections', [])
        ->call('createInvitation')
        ->assertHasErrors(['inviteSections']);
});

test('accepting a custom invitation carries its sections onto the membership', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $company = Company::factory()->create();
    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);

    $invitation = CompanyInvitation::factory()->create([
        'company_id' => $company->id,
        'email' => 'invited@example.com',
        'role' => CompanyRole::Custom,
        'sections' => [Section::Reports->value],
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitedUser);

    Livewire::test('pages::companies.accept-invitation', ['invitation' => $invitation]);

    expect($invitedUser->fresh()->canAccessSection($company, Section::Reports))->toBeTrue()
        ->and($invitedUser->fresh()->canAccessSection($company, Section::Customers))->toBeFalse();
});

test('updating a member to custom stores sections and clearing to a preset removes them', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);

    $this->actingAs($owner);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->call('updateMember', $member->id, CompanyRole::Custom->value, [Section::Reports->value])
        ->assertHasNoErrors();

    $membership = $company->memberships()->where('user_id', $member->id)->firstOrFail();
    expect($membership->role)->toBe(CompanyRole::Custom)
        ->and($membership->sections)->toBe([Section::Reports->value]);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->call('updateMember', $member->id, CompanyRole::Admin->value)
        ->assertHasNoErrors();

    $membership = $company->memberships()->where('user_id', $member->id)->firstOrFail();
    expect($membership->role)->toBe(CompanyRole::Admin)
        ->and($membership->sections)->toBeNull();
});

test('the sidebar only renders sections the member can access', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    $company->memberships()->create([
        'user_id' => $user->id,
        'role' => CompanyRole::Custom,
        'sections' => [Section::Banking->value],
    ]);

    $response = $this->actingAs($user)->get(route('dashboard', ['company' => $company->slug]));

    $response->assertSee('data-sidebar-group="banking"', false)
        ->assertDontSee('data-sidebar-group="customers"', false)
        ->assertDontSee('data-sidebar-group="reports"', false);
});
