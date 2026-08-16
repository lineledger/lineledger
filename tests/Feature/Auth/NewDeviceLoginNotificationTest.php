<?php

use App\Enums\SecurityEvent;
use App\Models\SecurityLog;
use App\Models\User;
use App\Notifications\NewDeviceLoginNotification;
use App\Services\Security\LoginDeviceTracker;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

function requestWithAgent(string $userAgent, string $ip = '203.0.113.5'): Request
{
    $request = Request::create('/login', 'POST', server: [
        'HTTP_USER_AGENT' => $userAgent,
        'REMOTE_ADDR' => $ip,
    ]);
    app()->instance('request', $request);

    return $request;
}

$chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';
$chromeNewer = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36';
$firefox = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:129.0) Gecko/20100101 Firefox/129.0';

it('seeds the first device silently and reports it as not new', function () use ($chrome) {
    $user = User::factory()->create();
    $tracker = app(LoginDeviceTracker::class);

    $isNew = $tracker->track($user, requestWithAgent($chrome));

    expect($isNew)->toBeFalse()
        ->and($user->loginDevices()->count())->toBe(1);
});

it('does not re-flag the same device and bumps last_seen', function () use ($chrome) {
    $user = User::factory()->create();
    $tracker = app(LoginDeviceTracker::class);

    $tracker->track($user, requestWithAgent($chrome));
    $isNew = $tracker->track($user, requestWithAgent($chrome, '198.51.100.9'));

    expect($isNew)->toBeFalse()
        ->and($user->loginDevices()->count())->toBe(1)
        ->and($user->loginDevices()->first()->ip_address)->toBe('198.51.100.9');
});

it('treats a version-only user-agent change as the same device', function () use ($chrome, $chromeNewer) {
    $user = User::factory()->create();
    $tracker = app(LoginDeviceTracker::class);

    $tracker->track($user, requestWithAgent($chrome));
    $isNew = $tracker->track($user, requestWithAgent($chromeNewer));

    expect($isNew)->toBeFalse()
        ->and($user->loginDevices()->count())->toBe(1);
});

it('flags a genuinely different device once the user has one on file', function () use ($chrome, $firefox) {
    $user = User::factory()->create();
    $tracker = app(LoginDeviceTracker::class);

    $tracker->track($user, requestWithAgent($chrome));
    $isNew = $tracker->track($user, requestWithAgent($firefox));

    expect($isNew)->toBeTrue()
        ->and($user->loginDevices()->count())->toBe(2);
});

it('emails and logs on a login from a new device, but not on the first login', function () use ($chrome, $firefox) {
    Notification::fake();
    $user = User::factory()->create();

    // First-ever login seeds silently.
    requestWithAgent($chrome);
    event(new Login('web', $user, false));
    Notification::assertNotSentTo($user, NewDeviceLoginNotification::class);

    // A subsequent login from a different device notifies and logs.
    requestWithAgent($firefox);
    event(new Login('web', $user, false));

    Notification::assertSentTo($user, NewDeviceLoginNotification::class);
    expect(SecurityLog::query()->where('event', SecurityEvent::LoginFromNewDevice->value)->count())->toBe(1);
});

it('handles a null user agent', function () {
    $user = User::factory()->create();
    $tracker = app(LoginDeviceTracker::class);

    $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.1']);

    expect($tracker->track($user, $request))->toBeFalse()
        ->and($user->loginDevices()->count())->toBe(1);
});
