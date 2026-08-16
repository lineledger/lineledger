<?php

namespace App\Listeners\Security;

use App\Enums\SecurityEvent;
use App\Models\User;
use App\Notifications\NewDeviceLoginNotification;
use App\Services\Audit\SecurityLogRecorder;
use App\Services\Security\LoginDeviceTracker;
use Illuminate\Auth\Events\Login;

/**
 * On a successful web login, records the device and — if it is a new device for
 * a user who already had devices on file — logs a security event and emails the
 * "was this you?" notification. Portal logins authenticate a Contact, not a
 * User, so they are ignored.
 */
class NotifyNewDeviceLogin
{
    public function __construct(
        private LoginDeviceTracker $tracker,
        private SecurityLogRecorder $recorder,
    ) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $request = request();

        if (! $this->tracker->track($user, $request)) {
            return;
        }

        $this->recorder->record(SecurityEvent::LoginFromNewDevice, $user, metadata: [
            'ip' => $request->ip(),
        ]);

        $user->notify(new NewDeviceLoginNotification($request->ip(), $request->userAgent(), now()));
    }
}
