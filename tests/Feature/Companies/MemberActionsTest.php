<?php

use App\Actions\Companies\Members\AcceptInvitation;
use App\Actions\Companies\Members\CancelInvitation;
use App\Actions\Companies\Members\InviteMember;
use App\Actions\Companies\Members\RemoveMember;
use App\Actions\Companies\Members\UpdateMemberAccess;
use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\CompanyInvitation;
use App\Models\SecurityLog;
use App\Models\User;
use App\Notifications\Companies\CompanyInvitation as CompanyInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The member and invitation writes live in Actions shared by the members page
 * (and, later, the API): each records the change, writes the security log and
 * tears down access on a reduction. Authorization stays with the caller.
 */
beforeEach(function () {
    Notification::fake();

    $this->company = Company::factory()->create();
    $this->owner = User::factory()->create();
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);

    $this->logged = fn (SecurityEvent $event) => SecurityLog::query()->where('event', $event->value)->latest('id')->first();
});

it('invites with the role, keeping sections only for Custom, and emails the invitation', function () {
    $custom = app(InviteMember::class)->handle($this->company, $this->owner, 'ana@example.com', CompanyRole::Custom, ['customers']);
    $accountant = app(InviteMember::class)->handle($this->company, $this->owner, 'bo@example.com', CompanyRole::Accountant, ['customers']);

    expect($custom->sections)->toBe(['customers'])
        ->and($accountant->sections)->toBeNull()
        ->and($custom->invited_by)->toBe($this->owner->id)
        ->and($custom->expires_at->isFuture())->toBeTrue()
        ->and(($this->logged)(SecurityEvent::CompanyMemberInvited)->metadata['invitation_email'])->toBe('bo@example.com');

    Notification::assertSentOnDemand(CompanyInvitationNotification::class);
});

it('never invites someone as Owner', function () {
    app(InviteMember::class)->handle($this->company, $this->owner, 'ana@example.com', CompanyRole::Owner);
})->throws(HttpException::class);

it('cancels an invitation of this company only', function () {
    $invitation = app(InviteMember::class)->handle($this->company, $this->owner, 'ana@example.com', CompanyRole::Accountant);

    app(CancelInvitation::class)->handle($this->company, $this->owner, $invitation);

    expect(CompanyInvitation::query()->find($invitation->id))->toBeNull()
        ->and(($this->logged)(SecurityEvent::CompanyInvitationCancelled))->not->toBeNull();

    $elsewhere = app(InviteMember::class)->handle(Company::factory()->create(), $this->owner, 'cy@example.com', CompanyRole::Accountant);

    expect(fn () => app(CancelInvitation::class)->handle($this->company, $this->owner, $elsewhere))
        ->toThrow(HttpException::class);
});

it('accepts an invitation into a membership with its access and switches the user there', function () {
    $user = User::factory()->create(['email' => 'Ana@Example.com']);
    $invitation = app(InviteMember::class)->handle($this->company, $this->owner, 'ana@example.com', CompanyRole::Custom, ['reports']);

    $membership = app(AcceptInvitation::class)->handle($invitation, $user);

    expect($membership->role)->toBe(CompanyRole::Custom)
        ->and($membership->sections)->toBe(['reports'])
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($user->fresh()->current_company_id)->toBe($this->company->id)
        ->and(($this->logged)(SecurityEvent::CompanyMemberJoined))->not->toBeNull();
});

it('keeps an existing member\'s access when they accept another invitation', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $this->company->memberships()->create(['user_id' => $user->id, 'role' => CompanyRole::Admin]);
    $invitation = app(InviteMember::class)->handle($this->company, $this->owner, 'ana@example.com', CompanyRole::Custom, ['reports']);

    expect(app(AcceptInvitation::class)->handle($invitation, $user)->role)->toBe(CompanyRole::Admin)
        ->and(($this->logged)(SecurityEvent::CompanyMemberJoined))->toBeNull();
});

it('refuses an invitation that is accepted, expired or for another address', function (Closure $tamper) {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $invitation = app(InviteMember::class)->handle($this->company, $this->owner, 'ana@example.com', CompanyRole::Accountant);
    $tamper($invitation, $user);

    app(AcceptInvitation::class)->handle($invitation->fresh(), $user->fresh());
})->with([
    'accepted' => [fn ($invitation) => $invitation->update(['accepted_at' => now()])],
    'expired' => [fn ($invitation) => $invitation->update(['expires_at' => now()->subMinute()])],
    'other address' => [fn ($invitation, $user) => $user->forceFill(['email' => 'someone@example.com'])->save()],
])->throws(ValidationException::class);

it('changes a member\'s access, logging only a real change', function () {
    $member = User::factory()->create();
    $this->company->memberships()->create(['user_id' => $member->id, 'role' => CompanyRole::Accountant]);

    app(UpdateMemberAccess::class)->handle($this->company, $this->owner, $member, CompanyRole::Accountant);
    expect(($this->logged)(SecurityEvent::CompanyMemberRoleChanged))->toBeNull();

    $membership = app(UpdateMemberAccess::class)->handle($this->company, $this->owner, $member, CompanyRole::Admin);
    expect($membership->role)->toBe(CompanyRole::Admin)
        ->and(($this->logged)(SecurityEvent::CompanyMemberRoleChanged)->metadata['to_role'])->toBe('admin');
});

it('revokes the member\'s API keys on a downgrade, not an upgrade', function () {
    $member = User::factory()->create();
    $this->company->memberships()->create(['user_id' => $member->id, 'role' => CompanyRole::Custom, 'sections' => [Section::Reports->value]]);
    $key = CompanyApiKey::mint($this->company, 'Member key', $member->id)['key'];

    app(UpdateMemberAccess::class)->handle($this->company, $this->owner, $member, CompanyRole::Accountant);
    expect($key->fresh()->revoked_at)->toBeNull();

    app(UpdateMemberAccess::class)->handle($this->company, $this->owner, $member, CompanyRole::Custom, [Section::Customers->value]);
    expect($key->fresh()->revoked_at)->not->toBeNull();
});

it('never changes or removes the Owner', function (Closure $act) {
    $act($this);
})->with([
    'change' => [fn ($test) => app(UpdateMemberAccess::class)->handle($test->company, $test->owner, $test->owner, CompanyRole::Accountant)],
    'remove' => [fn ($test) => app(RemoveMember::class)->handle($test->company, $test->owner, $test->owner)],
])->throws(HttpException::class);

it('removes a member, tears down their access and logs it', function () {
    $member = User::factory()->create();
    $this->company->memberships()->create(['user_id' => $member->id, 'role' => CompanyRole::Accountant]);
    $member->forceFill(['current_company_id' => $this->company->id])->save();
    $key = CompanyApiKey::mint($this->company, 'Member key', $member->id)['key'];

    app(RemoveMember::class)->handle($this->company, $this->owner, $member);

    expect($member->belongsToCompany($this->company))->toBeFalse()
        ->and($key->fresh()->revoked_at)->not->toBeNull()
        ->and($member->fresh()->current_company_id)->not->toBe($this->company->id)
        ->and(($this->logged)(SecurityEvent::CompanyMemberRemoved)->metadata['removed_user_id'])->toBe($member->id);
});
