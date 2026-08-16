<?php

use App\Enums\SecurityEvent;
use App\Models\SecurityLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Support\Facades\Event;

it('records a successful login event with user, ip, and user agent', function () {
    $user = User::factory()->create();

    $this->withServerVariables([
        'REMOTE_ADDR' => '203.0.113.42',
        'HTTP_USER_AGENT' => 'TestBrowser/1.0',
    ])->get('/');

    Event::dispatch(new Login('web', $user, false));

    $row = SecurityLog::query()->where('event', SecurityEvent::LoginSucceeded)->latest('id')->first();

    expect($row)->not->toBeNull();
    expect($row->user_id)->toBe($user->id);
    expect($row->ip_address)->toBe('203.0.113.42');
    expect($row->user_agent)->toBe('TestBrowser/1.0');
});

it('records a failed login with the attempted email and no user_id', function () {
    Event::dispatch(new Failed('web', null, ['email' => 'unknown@example.com', 'password' => 'wrong']));

    $row = SecurityLog::query()->where('event', SecurityEvent::LoginFailed)->latest('id')->first();

    expect($row)->not->toBeNull();
    expect($row->user_id)->toBeNull();
    expect($row->attempted_email)->toBe('unknown@example.com');
});

it('records a password reset link request', function () {
    $user = User::factory()->create();

    Event::dispatch(new PasswordResetLinkSent($user));

    $row = SecurityLog::query()->where('event', SecurityEvent::PasswordResetRequested)->latest('id')->first();

    expect($row)->not->toBeNull();
    expect($row->user_id)->toBe($user->id);
});

it('records a logout event', function () {
    $user = User::factory()->create();

    Event::dispatch(new Logout('web', $user));

    $row = SecurityLog::query()->where('event', SecurityEvent::LoggedOut)->latest('id')->first();

    expect($row)->not->toBeNull();
    expect($row->user_id)->toBe($user->id);
});

it('refuses to update a SecurityLog row via Eloquent', function () {
    $user = User::factory()->create();
    Event::dispatch(new Login('web', $user, false));

    $row = SecurityLog::query()->latest('id')->first();

    expect(function () use ($row) {
        $row->ip_address = '1.2.3.4';
        $row->save();
    })->toThrow(LogicException::class);
});
