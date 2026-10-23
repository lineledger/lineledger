<?php

use App\Models\User;
use App\Notifications\ScheduledTaskFailedAlert;
use App\Support\AlertRecipients;
use App\Support\SchedulerFailureAlert;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Stringable;

/**
 * A one-click install leaves the alert addresses blank (nobody's address is
 * known at first boot), so operator alerts must reach the install's site
 * admins rather than nobody, or the upstream project's inbox.
 */
it('uses the configured address when there is one', function () {
    User::factory()->siteAdmin()->create(['email' => 'admin@example.com']);
    config()->set('services.ops_alerts.alert_email', 'ops@example.com');

    expect(AlertRecipients::for('services.ops_alerts.alert_email'))->toBe(['ops@example.com']);
});

it('falls back to the active site admins when the address is blank', function () {
    User::factory()->siteAdmin()->create(['email' => 'first@example.com']);
    User::factory()->siteAdmin()->create(['email' => 'second@example.com']);
    User::factory()->siteAdmin()->create(['email' => 'gone@example.com', 'disabled_at' => now()]);
    User::factory()->create(['email' => 'member@example.com']);
    config()->set('services.ops_alerts.alert_email', '');

    expect(AlertRecipients::for('services.ops_alerts.alert_email'))->toBe(['first@example.com', 'second@example.com']);
});

it('emails the site admins when a scheduled task fails and no ops address is set', function () {
    Notification::fake();
    User::factory()->siteAdmin()->create(['email' => 'admin@example.com']);
    config()->set('services.ops_alerts.alert_email', '');

    (SchedulerFailureAlert::for('recurring:generate'))(new Stringable('boom'));

    Notification::assertSentOnDemand(
        ScheduledTaskFailedAlert::class,
        fn ($notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === ['admin@example.com'],
    );
});
