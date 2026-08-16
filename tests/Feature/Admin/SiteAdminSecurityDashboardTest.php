<?php

use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Models\SecurityLog;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->siteAdmin()->create();
    $this->actingAs($this->admin);
});

function seedSecurityLog(int $companyId, SecurityEvent $event, string $email, string $ip): void
{
    SecurityLog::create([
        'recorded_at' => now(),
        'company_id' => $companyId,
        'event' => $event,
        'attempted_email' => $email,
        'ip_address' => $ip,
    ]);
}

it('blocks a non-admin entirely', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::admin.security')->assertStatus(404);
});

it('shows security-log rows across every tenant', function () {
    $alpha = Company::factory()->create(['name' => 'Alpha Co']);
    $beta = Company::factory()->create(['name' => 'Beta Co']);

    seedSecurityLog($alpha->id, SecurityEvent::LoginFailed, 'alpha-user@example.com', '10.0.0.1');
    seedSecurityLog($beta->id, SecurityEvent::LoginFailed, 'beta-user@example.com', '10.0.0.2');

    // The attempted emails appear only in the log table (not the company select),
    // so seeing both proves cross-tenant visibility.
    Livewire::test('pages::admin.security')
        ->assertSee('alpha-user@example.com')
        ->assertSee('beta-user@example.com');
});

it('filters by event', function () {
    $company = Company::factory()->create();
    seedSecurityLog($company->id, SecurityEvent::LoginFailed, 'failed@example.com', '10.0.0.1');
    seedSecurityLog($company->id, SecurityEvent::LoginLockout, 'locked@example.com', '10.0.0.2');

    Livewire::test('pages::admin.security')
        ->set('eventFilter', SecurityEvent::LoginLockout->value)
        ->assertSee('locked@example.com')
        ->assertDontSee('failed@example.com');
});

it('filters by company', function () {
    $alpha = Company::factory()->create(['name' => 'Alpha Co']);
    $beta = Company::factory()->create(['name' => 'Beta Co']);
    seedSecurityLog($alpha->id, SecurityEvent::LoginFailed, 'alpha-user@example.com', '10.0.0.1');
    seedSecurityLog($beta->id, SecurityEvent::LoginFailed, 'beta-user@example.com', '10.0.0.2');

    Livewire::test('pages::admin.security')
        ->set('companyFilter', (string) $alpha->id)
        ->assertSee('alpha-user@example.com')
        ->assertDontSee('beta-user@example.com');
});

it('surfaces a failed-login spike in the anomalies panel', function () {
    $company = Company::factory()->create();
    for ($i = 0; $i < 12; $i++) {
        seedSecurityLog($company->id, SecurityEvent::LoginFailed, "attacker{$i}@example.com", '198.51.100.9');
    }

    Livewire::test('pages::admin.security')
        ->assertSee('Failed-login spike from 198.51.100.9');
});
