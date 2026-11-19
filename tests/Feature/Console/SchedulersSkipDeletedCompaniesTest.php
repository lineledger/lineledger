<?php

use App\Jobs\GenerateDailyInsightForCompany;
use App\Jobs\GenerateDepreciationForCompany;
use App\Jobs\GenerateRecurringDocumentsForCompany;
use App\Jobs\SendDeadlineNotificationsForCompany;
use App\Jobs\SendPaymentRemindersForCompany;
use App\Jobs\SendScheduledReportEmailsForCompany;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * The nightly schedulers fan out one queued job per company. A soft-deleted
 * company must not be queued (its job could only fail — the per-company job
 * loads the company with the soft-delete scope on), and a job whose company
 * vanished between dispatch and execution must finish quietly rather than
 * land in failed_jobs and page ops every hour.
 */
beforeEach(function () {
    $this->live = Company::factory()->create();
    $this->deleted = Company::factory()->create();
    $this->deleted->delete();
});

it('queues per-company work for live companies only', function (string $command, string $job) {
    Queue::fake();

    // Deadline emails go out from 07:00 in each company's timezone.
    if ($command === 'deadlines:notify') {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 18:00', 'UTC'));
    }

    $this->artisan($command)->assertSuccessful();

    Queue::assertPushed($job, fn ($queued) => $queued->companyId === $this->live->id);
    Queue::assertNotPushed($job, fn ($queued) => $queued->companyId === $this->deleted->id);
})->with([
    'recurring documents' => ['recurring:generate', GenerateRecurringDocumentsForCompany::class],
    'depreciation' => ['depreciation:generate', GenerateDepreciationForCompany::class],
    'payment reminders' => ['reminders:send', SendPaymentRemindersForCompany::class],
    'daily insights' => ['insights:generate', GenerateDailyInsightForCompany::class],
    'scheduled reports' => ['reports:send-scheduled', SendScheduledReportEmailsForCompany::class],
    'deadline notifications' => ['deadlines:notify', SendDeadlineNotificationsForCompany::class],
]);

it('does not resolve a deleted company by id or slug', function () {
    Queue::fake();

    $this->artisan('recurring:generate', ['company' => $this->deleted->id])->assertFailed();
    $this->artisan('recurring:generate', ['company' => $this->deleted->slug])->assertFailed();
    $this->artisan('recurring:generate', ['company' => $this->live->slug])->assertSuccessful();

    Queue::assertPushed(GenerateRecurringDocumentsForCompany::class, 1);
});

it('finishes quietly when the company vanished before the job ran', function (string $job) {
    Log::spy();

    app()->call([new $job($this->deleted->id), 'handle']);
    app()->call([new $job(999999), 'handle']);

    Log::shouldHaveReceived('info')->twice();
})->with([
    GenerateRecurringDocumentsForCompany::class,
    GenerateDepreciationForCompany::class,
    SendPaymentRemindersForCompany::class,
    GenerateDailyInsightForCompany::class,
    SendScheduledReportEmailsForCompany::class,
    SendDeadlineNotificationsForCompany::class,
]);
