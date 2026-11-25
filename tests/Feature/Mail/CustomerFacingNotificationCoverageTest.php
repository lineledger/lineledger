<?php

use App\Contracts\CustomerFacingNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\File;

/*
 * While customer email is kept behind two-factor authentication,
 * HoldCustomerMailWithoutTwoFactor holds every notification marked
 * CustomerFacingNotification. A new notification has to say which kind it is,
 * or one sent to a customer would slip past that backstop.
 */

/** Mail to members, site admins and the operator: never held. */
const ACCOUNT_AND_OPS_NOTIFICATIONS = [
    'App\Notifications\Companies\CompanyInvitation',
    'App\Notifications\Companies\StripeConnectionBrokenNotification',
    'App\Notifications\Deadlines\DeadlineDigestNotification',
    'App\Notifications\Deadlines\DeadlineDueSoonNotification',
    'App\Notifications\Deadlines\DeadlineOverdueNotification',
    'App\Notifications\ExchangeRateHealthAlert',
    'App\Notifications\FailedJobsAlert',
    'App\Notifications\LedgerIntegrityAlert',
    'App\Notifications\NewDeviceLoginNotification',
    'App\Notifications\Payroll\TimeOffRequestAwaitingConfirmation',
    'App\Notifications\Payroll\TimeOffRequestSubmitted',
    'App\Notifications\ScheduledTaskFailedAlert',
    'App\Notifications\SecurityAnomalyAlert',
    'App\Notifications\Support\SupportTicketActivityNotification',
    'App\Notifications\Support\SupportTicketReplyNotification',
];

it('marks every notification as customer-facing or as account and ops mail', function () {
    $undecided = collect(File::allFiles(app_path('Notifications')))
        ->map(fn ($file): string => 'App\\Notifications\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()))
        ->filter(fn (string $class): bool => class_exists($class) && is_subclass_of($class, Notification::class))
        ->reject(fn (string $class): bool => is_subclass_of($class, CustomerFacingNotification::class)
            || in_array($class, ACCOUNT_AND_OPS_NOTIFICATIONS, true))
        ->values()
        ->all();

    expect($undecided)->toBe([]);
});

it('keeps account and ops mail unmarked', function () {
    $marked = array_values(array_filter(
        ACCOUNT_AND_OPS_NOTIFICATIONS,
        fn (string $class): bool => is_subclass_of($class, CustomerFacingNotification::class),
    ));

    expect($marked)->toBe([]);
});
