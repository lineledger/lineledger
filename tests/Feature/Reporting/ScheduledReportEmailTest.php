<?php

use App\Jobs\SendScheduledReportEmailsForCompany;
use App\Models\Company;
use App\Models\MemorizedReport;
use App\Models\MemorizedReportGroup;
use App\Models\ReportEmailSchedule;
use App\Models\User;
use App\Notifications\Reports\ReportEmailNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1]);
    $this->user = User::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function scheduledEmailReport(array $overrides = []): MemorizedReport
{
    return MemorizedReport::create(array_merge([
        'company_id' => test()->company->id,
        'user_id' => test()->user->id,
        'report_key' => 'reports.income-statement',
        'name' => 'Monthly P&L',
        'settings' => ['preset' => 'last_month'],
    ], $overrides));
}

function scheduledEmailSchedule(array $overrides = []): ReportEmailSchedule
{
    $today = test()->company->currentDateTime()->startOfDay();

    return ReportEmailSchedule::create(array_merge([
        'company_id' => test()->company->id,
        'user_id' => test()->user->id,
        'recipients' => ['boss@example.com'],
        'frequency' => 'monthly',
        'start_date' => $today->toDateString(),
        'day_of_month' => (int) $today->format('j'),
        'end_type' => 'never',
        'next_run_date' => $today->toDateString(),
        'is_active' => true,
    ], $overrides));
}

it('creates an email schedule from the memorized reports page with a computed first run', function () {
    $report = scheduledEmailReport();

    Livewire::actingAs($this->user)
        ->test('pages::reports.memorized', ['company' => $this->company])
        ->call('openSchedule', $report->id)
        ->set('scheduleFrequency', 'monthly')
        ->set('scheduleStartDate', '2026-07-15')
        ->set('scheduleDayOfMonth', 15)
        ->set('scheduleRecipients', 'boss@example.com, cfo@example.com')
        ->set('scheduleSubject', 'Monthly P&L')
        ->call('saveSchedule')
        ->assertHasNoErrors();

    $schedule = ReportEmailSchedule::query()->where('user_id', $this->user->id)->first();

    expect($schedule)->not->toBeNull()
        ->and($schedule->memorized_report_id)->toBe($report->id)
        ->and($schedule->memorized_report_group_id)->toBeNull()
        ->and($schedule->recipients)->toBe(['boss@example.com', 'cfo@example.com'])
        ->and($schedule->subject)->toBe('Monthly P&L')
        ->and($schedule->frequency->value)->toBe('monthly')
        // First run anchors to the start date, like SaveRecurringDocument.
        ->and($schedule->next_run_date->toDateString())->toBe('2026-07-15')
        ->and($schedule->is_active)->toBeTrue();
});

it('rejects invalid schedule recipients', function () {
    $report = scheduledEmailReport();

    $component = Livewire::actingAs($this->user)
        ->test('pages::reports.memorized', ['company' => $this->company])
        ->call('openSchedule', $report->id);

    $component->set('scheduleRecipients', '')->call('saveSchedule')
        ->assertHasErrors('scheduleRecipients');

    $component->set('scheduleRecipients', 'not-an-email')->call('saveSchedule')
        ->assertHasErrors('scheduleRecipients');

    $tooMany = collect(range(1, 11))->map(fn ($i) => "user{$i}@example.com")->implode(', ');
    $component->set('scheduleRecipients', $tooMany)->call('saveSchedule')
        ->assertHasErrors('scheduleRecipients');

    expect(ReportEmailSchedule::query()->count())->toBe(0);
});

it('sends a due schedule to its recipients and advances the run date', function () {
    Notification::fake();

    $report = scheduledEmailReport();
    $schedule = scheduledEmailSchedule([
        'memorized_report_id' => $report->id,
        'recipients' => ['boss@example.com', 'cfo@example.com'],
        'subject' => 'Monthly numbers',
        'attach_xlsx' => true,
    ]);

    Artisan::call('reports:send-scheduled', ['company' => $this->company->id, '--sync' => true]);

    Notification::assertSentOnDemand(
        ReportEmailNotification::class,
        function (ReportEmailNotification $notification, array $channels, AnonymousNotifiable $notifiable) {
            return $notifiable->routes['mail'] === ['boss@example.com', 'cfo@example.com']
                && $notification->reportKey === 'reports.income-statement'
                && $notification->settings['preset'] === 'last_month'
                && $notification->subjectLine === 'Monthly numbers'
                && $notification->attachXlsx === true
                && $notification->resolvePresets === true
                && $notification->replyToAddress === $this->user->email;
        },
    );

    $today = $this->company->currentDateTime()->startOfDay();
    $schedule->refresh();

    expect($schedule->next_run_date->toDateString())->toBeGreaterThan($today->toDateString())
        ->and($schedule->last_sent_at)->not->toBeNull()
        ->and($schedule->occurrences_generated)->toBe(1)
        ->and($schedule->is_active)->toBeTrue();
});

it('sends exactly once when runs were missed and fast-forwards past today', function () {
    Notification::fake();

    $today = $this->company->currentDateTime()->startOfDay();
    $report = scheduledEmailReport();
    $schedule = scheduledEmailSchedule([
        'memorized_report_id' => $report->id,
        'start_date' => $today->subMonths(5)->toDateString(),
        'next_run_date' => $today->subMonths(4)->toDateString(),
    ]);

    Artisan::call('reports:send-scheduled', ['company' => $this->company->id, '--sync' => true]);

    // Catch-up resends of stale reports are wrong — one send, then skip ahead.
    Notification::assertSentOnDemandTimes(ReportEmailNotification::class, 1);

    $schedule->refresh();

    expect($schedule->next_run_date->toDateString())->toBeGreaterThan($today->toDateString())
        ->and($schedule->occurrences_generated)->toBe(1);
});

it('deactivates a schedule once max occurrences is reached', function () {
    Notification::fake();

    $report = scheduledEmailReport();
    $schedule = scheduledEmailSchedule([
        'memorized_report_id' => $report->id,
        'end_type' => 'after_occurrences',
        'max_occurrences' => 1,
    ]);

    Artisan::call('reports:send-scheduled', ['company' => $this->company->id, '--sync' => true]);

    Notification::assertSentOnDemandTimes(ReportEmailNotification::class, 1);

    $schedule->refresh();

    expect($schedule->is_active)->toBeFalse()
        ->and($schedule->next_run_date)->toBeNull()
        ->and($schedule->occurrences_generated)->toBe(1);
});

it('pauses a schedule whose memorized report can no longer be emailed', function () {
    Notification::fake();

    // e.g. the report key left the renderable allowlist after the schedule was made.
    $report = scheduledEmailReport(['report_key' => 'reports.removed-report']);
    $schedule = scheduledEmailSchedule(['memorized_report_id' => $report->id]);

    Artisan::call('reports:send-scheduled', ['company' => $this->company->id, '--sync' => true]);

    Notification::assertNothingSent();

    $schedule->refresh();

    expect($schedule->is_active)->toBeFalse()
        ->and($schedule->paused_reason)->not->toBeNull();
});

it('removes the schedule when its memorized report is deleted, sending nothing', function () {
    Notification::fake();

    $report = scheduledEmailReport();
    $schedule = scheduledEmailSchedule(['memorized_report_id' => $report->id]);

    $report->delete(); // FK cascades the schedule away

    Artisan::call('reports:send-scheduled', ['company' => $this->company->id, '--sync' => true]);

    Notification::assertNothingSent();
    expect(ReportEmailSchedule::query()->find($schedule->id))->toBeNull();
});

it('sends one email per renderable report in a group schedule', function () {
    Notification::fake();

    $group = MemorizedReportGroup::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'name' => 'Month End',
    ]);
    scheduledEmailReport(['memorized_report_group_id' => $group->id]);
    scheduledEmailReport([
        'memorized_report_group_id' => $group->id,
        'report_key' => 'reports.balance-sheet',
        'name' => 'Month-end BS',
    ]);

    scheduledEmailSchedule(['memorized_report_group_id' => $group->id]);

    Artisan::call('reports:send-scheduled', ['company' => $this->company->id, '--sync' => true]);

    Notification::assertSentOnDemandTimes(ReportEmailNotification::class, 2);
});

it('queues a per-company job when run without --sync', function () {
    Queue::fake();

    $report = scheduledEmailReport();
    scheduledEmailSchedule(['memorized_report_id' => $report->id]);

    Artisan::call('reports:send-scheduled');

    Queue::assertPushed(
        SendScheduledReportEmailsForCompany::class,
        fn (SendScheduledReportEmailsForCompany $job) => $job->companyId === $this->company->id,
    );
});

it('deletes a schedule from the memorized reports page', function () {
    $report = scheduledEmailReport();
    $schedule = scheduledEmailSchedule(['memorized_report_id' => $report->id]);

    Livewire::actingAs($this->user)
        ->test('pages::reports.memorized', ['company' => $this->company])
        ->assertSee('Scheduled')
        ->call('deleteSchedule', $schedule->id);

    expect(ReportEmailSchedule::query()->find($schedule->id))->toBeNull();
});
