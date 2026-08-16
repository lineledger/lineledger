<?php

use App\Enums\CompanyRole;
use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\SecurityLog;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Every change to who can access a company — and at what privilege level — must
 * land in the immutable security log (SOC 2 Common Criteria: logical access
 * provisioning/deprovisioning and privilege change). These cover the company
 * membership lifecycle end to end.
 */
function latestEvent(SecurityEvent $event): ?SecurityLog
{
    return SecurityLog::query()->where('event', $event)->latest('id')->first();
}

test('inviting a member records a CompanyMemberInvited event', function () {
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

    $row = latestEvent(SecurityEvent::CompanyMemberInvited);

    expect($row)->not->toBeNull();
    expect($row->user_id)->toBe($owner->id);
    expect($row->metadata['company_id'])->toBe($company->id);
    expect($row->metadata['invitation_email'])->toBe('invited@example.com');
    expect($row->metadata['role'])->toBe(CompanyRole::Accountant->value);
});

test('accepting an invitation records a CompanyMemberJoined event', function () {
    $owner = User::factory()->create();
    $invited = User::factory()->create(['email' => 'invited@example.com']);
    $company = Company::factory()->create();
    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);

    $invitation = CompanyInvitation::factory()->create([
        'company_id' => $company->id,
        'email' => 'invited@example.com',
        'role' => CompanyRole::Accountant,
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invited);

    Livewire::test('pages::companies.accept-invitation', ['invitation' => $invitation]);

    $row = latestEvent(SecurityEvent::CompanyMemberJoined);

    expect($row)->not->toBeNull();
    expect($row->user_id)->toBe($invited->id);
    expect($row->metadata['company_id'])->toBe($company->id);
    expect($row->metadata['role'])->toBe(CompanyRole::Accountant->value);
});

test('changing a member role records a CompanyMemberRoleChanged event with from and to roles', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);

    $this->actingAs($owner);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->call('updateMember', $member->id, CompanyRole::Admin->value)
        ->assertHasNoErrors();

    $row = latestEvent(SecurityEvent::CompanyMemberRoleChanged);

    expect($row)->not->toBeNull();
    expect($row->user_id)->toBe($owner->id);
    expect($row->metadata['target_user_id'])->toBe($member->id);
    expect($row->metadata['from_role'])->toBe(CompanyRole::Accountant->value);
    expect($row->metadata['to_role'])->toBe(CompanyRole::Admin->value);
});

test('removing a member records a CompanyMemberRemoved event', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);

    $this->actingAs($owner);

    Livewire::test('pages::companies.remove-member-modal', ['company' => $company])
        ->set('memberId', $member->id)
        ->call('removeMember')
        ->assertHasNoErrors();

    $row = latestEvent(SecurityEvent::CompanyMemberRemoved);

    expect($row)->not->toBeNull();
    expect($row->user_id)->toBe($owner->id);
    expect($row->metadata['company_id'])->toBe($company->id);
    expect($row->metadata['removed_user_id'])->toBe($member->id);
});

test('cancelling an invitation records a CompanyInvitationCancelled event', function () {
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

    $row = latestEvent(SecurityEvent::CompanyInvitationCancelled);

    expect($row)->not->toBeNull();
    expect($row->user_id)->toBe($owner->id);
    expect($row->metadata['company_id'])->toBe($company->id);
});

test('switching company records a CompanySwitched event with from and to slugs', function () {
    $user = User::factory()->create();
    $companyB = Company::factory()->create();
    $companyB->members()->attach($user, ['role' => CompanyRole::Accountant->value]);

    $user->switchCompany($user->personalCompany());
    $fromSlug = $user->personalCompany()->slug;

    $this->actingAs($user);

    Livewire::test('company-switcher')
        ->call('switchCompany', $companyB->slug);

    $row = latestEvent(SecurityEvent::CompanySwitched);

    expect($row)->not->toBeNull();
    expect($row->user_id)->toBe($user->id);
    expect($row->metadata['from_company_slug'])->toBe($fromSlug);
    expect($row->metadata['to_company_slug'])->toBe($companyB->slug);
});
