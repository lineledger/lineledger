<?php

use App\Support\SchedulerFailureAlert;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every scheduled task carries ->onFailure(SchedulerFailureAlert::for(...)) so a
// task that exits non-zero OR throws before its own alerting emails ops instead
// of failing silently. Without it a crashing command is invisible.

Schedule::command('recurring:generate')->dailyAt('02:00')->withoutOverlapping()
    ->onFailure(SchedulerFailureAlert::for('recurring:generate'));
// Draft monthly book-depreciation entries for assets opted into auto-depreciation.
// Idempotent per asset-month, so a daily run is safe.
Schedule::command('depreciation:generate')->dailyAt('02:30')->withoutOverlapping()
    ->onFailure(SchedulerFailureAlert::for('depreciation:generate'));
// Scheduled report emails (QBO "Set email schedule" on a memorized report).
// Runs after recurring generation so the morning's drafts are reflected.
Schedule::command('reports:send-scheduled')->dailyAt('07:00')->withoutOverlapping()->onOneServer()
    ->onFailure(SchedulerFailureAlert::for('reports:send-scheduled'));
// Automated invoice payment reminders (dunning). Runs after recurring generation
// so freshly issued invoices are considered, and is idempotent per (invoice, tier).
Schedule::command('reminders:send')->dailyAt('07:30')->withoutOverlapping()->onOneServer()
    ->onFailure(SchedulerFailureAlert::for('reminders:send'));
// Grant beginning-of-year / anniversary time-off lumps and roll balances over at
// the cycle boundary. Idempotent per cycle, so a daily run is safe.
Schedule::command('payroll:accrue-time-off')->dailyAt('01:00')->withoutOverlapping()->onOneServer()
    ->onFailure(SchedulerFailureAlert::for('payroll:accrue-time-off'));
Schedule::command('rates:fetch')->dailyAt('06:00')->withoutOverlapping()
    ->onFailure(SchedulerFailureAlert::for('rates:fetch'));
// Runs well after the fetch so a missed/failed 06:00 run trips the ~26h threshold
// the same morning rather than the next day.
Schedule::command('rates:health')->dailyAt('08:30')->withoutOverlapping()->onOneServer()
    ->onFailure(SchedulerFailureAlert::for('rates:health'));
Schedule::command('backups:prune-expired')->daily()->onOneServer()
    ->onFailure(SchedulerFailureAlert::for('backups:prune-expired'));
// Nightly proof the books reconcile (hash chain + GL balance + balance cache).
// Emails ops on any failure; the run history is SOC 2 Type II monitoring evidence.
Schedule::command('integrity:check')->dailyAt('04:00')->withoutOverlapping()->onOneServer()
    ->onFailure(SchedulerFailureAlert::for('integrity:check'));
// Compute each company's daily "Did you know?" insight. Runs after
// recurring:generate (02:00) so the paused-template detector sees fresh state.
// Idempotent per company-day (unique daily_insights index), so re-runs are safe.
Schedule::command('insights:generate')->dailyAt('05:00')->withoutOverlapping()->onOneServer()
    ->onFailure(SchedulerFailureAlert::for('insights:generate'));
// Hourly security-log anomaly scan (failed-login spikes, lockouts, mass API-key
// revocation, privilege escalation). Window defaults to 60m to tile the hourly
// cadence. Emails ops on any finding — CC7.2/CC7.3 monitoring evidence.
Schedule::command('security:monitor')->hourly()->withoutOverlapping()->onOneServer()
    ->onFailure(SchedulerFailureAlert::for('security:monitor'));
// Hourly digest of newly failed queued jobs. Window defaults to 60m to tile the
// cadence. Emails ops on any finding — the queue is where backups, restores,
// recurring-doc generation, and mail run, so a silent failure there is invisible.
Schedule::command('ops:monitor-failed-jobs')->hourly()->withoutOverlapping()->onOneServer()
    ->onFailure(SchedulerFailureAlert::for('ops:monitor-failed-jobs'));
