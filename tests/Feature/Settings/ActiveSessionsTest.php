<?php

use App\Enums\SecurityEvent;
use App\Models\SecurityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

function seedSession(string $id, ?int $userId, string $ip = '10.0.0.1', string $agent = 'Chrome'): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => $ip,
        'user_agent' => $agent,
        'payload' => '',
        'last_activity' => time(),
    ]);
}

it('lists only the current user sessions and marks the current one', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $component = Livewire::actingAs($user)->test('pages::settings.security');
    $currentId = session()->getId();

    seedSession($currentId, $user->id, '10.0.0.1', 'Mozilla/5.0 Chrome/140');
    seedSession('other-device-row', $user->id, '10.0.0.2', 'Mozilla/5.0 Firefox/129');
    seedSession('someone-else', $other->id, '10.0.0.3', 'Mozilla/5.0 Safari');

    $component->call('loadSessions')
        ->assertSee('10.0.0.1')
        ->assertSee('10.0.0.2')
        ->assertDontSee('10.0.0.3')
        ->assertSee('This device');
});

it('revokes a single other session and logs it', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);
    seedSession('other-device-row', $user->id);

    Livewire::actingAs($user)->test('pages::settings.security')
        ->call('revokeSession', 'other-device-row');

    expect(DB::table('sessions')->where('id', 'other-device-row')->exists())->toBeFalse();
    expect(SecurityLog::query()->where('event', SecurityEvent::SessionRevoked->value)->exists())->toBeTrue();
});

it('will not revoke the current session', function () {
    $user = User::factory()->create();
    $component = Livewire::actingAs($user)->test('pages::settings.security');
    $currentId = session()->getId();
    seedSession($currentId, $user->id);

    $component->call('revokeSession', $currentId);

    expect(DB::table('sessions')->where('id', $currentId)->exists())->toBeTrue();
});

it('rejects logout-others with a wrong password and keeps sessions', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse')]);
    seedSession('other-device-row', $user->id);

    Livewire::actingAs($user)->test('pages::settings.security')
        ->set('logoutPassword', 'wrong-password')
        ->call('logoutOtherSessions')
        ->assertHasErrors('logoutPassword');

    expect(DB::table('sessions')->where('id', 'other-device-row')->exists())->toBeTrue();
});

it('signs out other sessions with the correct password', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse')]);
    $currentId = session()->getId();
    seedSession($currentId, $user->id);
    seedSession('other-device-row', $user->id);

    Livewire::actingAs($user)->test('pages::settings.security')
        ->set('logoutPassword', 'correct-horse')
        ->call('logoutOtherSessions')
        ->assertHasNoErrors();

    expect(DB::table('sessions')->where('id', 'other-device-row')->exists())->toBeFalse();
});
