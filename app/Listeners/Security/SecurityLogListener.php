<?php

namespace App\Listeners\Security;

use App\Enums\SecurityEvent;
use App\Models\User;
use App\Services\Audit\SecurityLogRecorder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Auth\Events\Verified;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

class SecurityLogListener
{
    public function __construct(protected SecurityLogRecorder $recorder) {}

    public function onLogin(Login $event): void
    {
        $this->recorder->record(SecurityEvent::LoginSucceeded, $this->user($event->user));
    }

    public function onLoginFailed(Failed $event): void
    {
        $this->recorder->record(
            SecurityEvent::LoginFailed,
            $this->user($event->user),
            $this->emailFromCredentials($event->credentials),
            ['guard' => $event->guard],
        );
    }

    public function onLogout(Logout $event): void
    {
        $this->recorder->record(SecurityEvent::LoggedOut, $this->user($event->user));
    }

    public function onLockout(Lockout $event): void
    {
        $email = null;

        if (property_exists($event, 'request') && $event->request !== null) {
            $value = $event->request->input('email');
            $email = is_string($value) ? $value : null;
        }

        $this->recorder->record(SecurityEvent::LoginLockout, null, $email);
    }

    public function onPasswordResetLinkSent(PasswordResetLinkSent $event): void
    {
        $this->recorder->record(SecurityEvent::PasswordResetRequested, $this->user($event->user));
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        $this->recorder->record(SecurityEvent::PasswordReset, $this->user($event->user));
    }

    public function onEmailVerified(Verified $event): void
    {
        $this->recorder->record(SecurityEvent::EmailVerified, $this->user($event->user));
    }

    public function onOtherDeviceLogout(OtherDeviceLogout $event): void
    {
        $this->recorder->record(SecurityEvent::OtherDeviceLoggedOut, $this->user($event->user));
    }

    public function onPasswordUpdated(PasswordUpdatedViaController $event): void
    {
        $this->recorder->record(SecurityEvent::PasswordChanged, $this->user($event->user));
    }

    public function onTwoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        $this->recorder->record(SecurityEvent::TwoFactorEnabled, $this->user($event->user));
    }

    public function onTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->recorder->record(SecurityEvent::TwoFactorDisabled, $this->user($event->user));
    }

    public function onTwoFactorChallenged(TwoFactorAuthenticationChallenged $event): void
    {
        $this->recorder->record(SecurityEvent::TwoFactorChallenged, $this->user($event->user));
    }

    public function onTwoFactorPassed(ValidTwoFactorAuthenticationCodeProvided $event): void
    {
        $this->recorder->record(SecurityEvent::TwoFactorPassed, $this->user($event->user));
    }

    public function onTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->recorder->record(SecurityEvent::TwoFactorFailed, $this->user($event->user));
    }

    public function onRecoveryCodeReplaced(RecoveryCodeReplaced $event): void
    {
        $this->recorder->record(SecurityEvent::RecoveryCodeUsed, $this->user($event->user));
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Failed::class => 'onLoginFailed',
            Logout::class => 'onLogout',
            Lockout::class => 'onLockout',
            PasswordResetLinkSent::class => 'onPasswordResetLinkSent',
            PasswordReset::class => 'onPasswordReset',
            Verified::class => 'onEmailVerified',
            OtherDeviceLogout::class => 'onOtherDeviceLogout',
            PasswordUpdatedViaController::class => 'onPasswordUpdated',
            TwoFactorAuthenticationEnabled::class => 'onTwoFactorEnabled',
            TwoFactorAuthenticationDisabled::class => 'onTwoFactorDisabled',
            TwoFactorAuthenticationChallenged::class => 'onTwoFactorChallenged',
            ValidTwoFactorAuthenticationCodeProvided::class => 'onTwoFactorPassed',
            TwoFactorAuthenticationFailed::class => 'onTwoFactorFailed',
            RecoveryCodeReplaced::class => 'onRecoveryCodeReplaced',
        ];
    }

    protected function user(mixed $user): ?User
    {
        return $user instanceof User ? $user : null;
    }

    /**
     * @param  array<string, mixed>|null  $credentials
     */
    protected function emailFromCredentials(?array $credentials): ?string
    {
        if ($credentials === null) {
            return null;
        }

        $value = $credentials['email'] ?? null;

        return is_string($value) ? $value : null;
    }
}
