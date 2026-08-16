<?php

use App\Notifications\FailedJobsAlert;
use App\Notifications\ScheduledTaskFailedAlert;
use App\Support\SchedulerFailureAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;

// --- SchedulerFailureAlert (the ->onFailure hook on every scheduled task) ---

it('emails ops when a scheduled task fails', function () {
    Notification::fake();
    config()->set('services.ops_alerts.alert_email', 'ops@example.com');

    (SchedulerFailureAlert::for('recurring:generate'))(new Stringable('boom'));

    Notification::assertSentOnDemand(
        ScheduledTaskFailedAlert::class,
        fn (ScheduledTaskFailedAlert $n) => $n->command === 'recurring:generate' && $n->output === 'boom',
    );
});

it('sends nothing when no ops alert email is configured', function () {
    Notification::fake();
    config()->set('services.ops_alerts.alert_email', '');

    (SchedulerFailureAlert::for('recurring:generate'))(new Stringable('boom'));

    Notification::assertNothingSent();
});

// --- ops:monitor-failed-jobs ---

function seedFailedJob(string $displayName, string $failedAt): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => $displayName]),
        'exception' => "RuntimeException: {$displayName} blew up\n#0 stack",
        'failed_at' => $failedAt,
    ]);
}

it('alerts on jobs that failed within the window', function () {
    Notification::fake();
    config()->set('services.ops_alerts.alert_email', 'ops@example.com');

    seedFailedJob('App\\Jobs\\Backup\\ExportBackup', now()->subMinutes(10)->toDateTimeString());
    seedFailedJob('App\\Jobs\\Inbox\\ProcessInboxItem', now()->subMinutes(120)->toDateTimeString());

    // Window 60m: only the 10-minutes-ago failure is in scope.
    $this->artisan('ops:monitor-failed-jobs', ['--window' => 60])->assertExitCode(1);

    Notification::assertSentOnDemand(
        FailedJobsAlert::class,
        fn (FailedJobsAlert $n) => count($n->failures) === 1
            && str_contains($n->failures[0], 'ExportBackup')
            && $n->totalFailed === 2,
    );
});

it('stays silent when no jobs failed in the window', function () {
    Notification::fake();
    config()->set('services.ops_alerts.alert_email', 'ops@example.com');

    seedFailedJob('App\\Jobs\\Old', now()->subMinutes(300)->toDateTimeString());

    $this->artisan('ops:monitor-failed-jobs', ['--window' => 60])->assertExitCode(0);

    Notification::assertNothingSent();
});

it('honours --no-alert', function () {
    Notification::fake();
    config()->set('services.ops_alerts.alert_email', 'ops@example.com');

    seedFailedJob('App\\Jobs\\Recent', now()->subMinutes(5)->toDateTimeString());

    $this->artisan('ops:monitor-failed-jobs', ['--window' => 60, '--no-alert' => true])->assertExitCode(1);

    Notification::assertNothingSent();
});
