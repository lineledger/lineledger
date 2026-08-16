<?php

use App\Enums\CompanyRole;
use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Models\SecurityLog;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->actAs = function (CompanyRole $role): User {
        $user = User::factory()->create();
        $this->company->members()->attach($user, ['role' => $role->value]);
        // current_company_id is guarded; set it through the model helper so the
        // audit recorder stamps company_id on the SecurityLog rows.
        $user->switchCompany($this->company);
        $this->actingAs($user);

        return $user;
    };
});

test('a non-admin member cannot open the inbox email settings page', function () {
    // Accountant/Custom lack CompanyPermission::UpdateCompany, so the mount gate
    // must reject them — otherwise they could read or rotate the ingest token.
    ($this->actAs)(CompanyRole::Accountant);

    Livewire::test('pages::settings.inbox-email')->assertForbidden();
});

test('an owner can enable inbound email and the change is audit logged', function () {
    ($this->actAs)(CompanyRole::Owner);

    Livewire::test('pages::settings.inbox-email')
        ->set('inboundEnabled', true)
        ->call('saveInbound')
        ->assertHasNoErrors();

    expect($this->company->fresh()->inbound_email_enabled)->toBeTrue()
        ->and($this->company->fresh()->inbound_email_token)->not->toBeNull();

    expect(SecurityLog::query()
        ->where('event', SecurityEvent::InboundEmailSettingChanged->value)
        ->where('company_id', $this->company->id)
        ->exists())->toBeTrue();
});

test('rotating the ingest token changes it and is audit logged', function () {
    ($this->actAs)(CompanyRole::Owner);
    $this->company->forceFill([
        'inbound_email_enabled' => true,
        'inbound_email_token' => 'oldtokenoldtokenoldtokenoldtoken1234',
    ])->save();

    Livewire::test('pages::settings.inbox-email')
        ->call('rotateToken')
        ->assertHasNoErrors();

    expect($this->company->fresh()->inbound_email_token)
        ->not->toBe('oldtokenoldtokenoldtokenoldtoken1234');

    expect(SecurityLog::query()
        ->where('event', SecurityEvent::InboundEmailTokenRotated->value)
        ->where('company_id', $this->company->id)
        ->exists())->toBeTrue();
});
