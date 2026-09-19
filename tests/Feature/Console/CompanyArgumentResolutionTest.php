<?php

use App\Jobs\GenerateDailyInsightForCompany;
use App\Jobs\GenerateDepreciationForCompany;
use App\Jobs\GenerateRecurringDocumentsForCompany;
use App\Jobs\SendPaymentRemindersForCompany;
use App\Jobs\SendScheduledReportEmailsForCompany;
use App\Models\Company;
use Illuminate\Support\Facades\Queue;

/**
 * Every per-company command takes an optional `{company?}` that is an id OR a
 * slug — resolved as one or the other, never `id = ? OR slug = ?`. MySQL
 * coerces `slug = 149` to match '149st-street-bakery', so the OR form swept a
 * second tenant into a run that named one (ResolvesCompanyArgument).
 */
beforeEach(function () {
    $this->real = Company::factory()->create();
    $this->decoy = Company::factory()->create(['slug' => $this->real->id.'st-street-bakery']);
});

it('queues work only for the organization the argument names', function (string $command, string $job) {
    Queue::fake();

    $this->artisan($command, ['company' => $this->real->id])->assertSuccessful();

    Queue::assertPushed($job, fn ($queued) => $queued->companyId === $this->real->id);
    Queue::assertNotPushed($job, fn ($queued) => $queued->companyId === $this->decoy->id);

    Queue::fake();

    $this->artisan($command, ['company' => $this->decoy->slug])->assertSuccessful();

    Queue::assertPushed($job, fn ($queued) => $queued->companyId === $this->decoy->id);
    Queue::assertNotPushed($job, fn ($queued) => $queued->companyId === $this->real->id);
})->with([
    'recurring documents' => ['recurring:generate', GenerateRecurringDocumentsForCompany::class],
    'depreciation' => ['depreciation:generate', GenerateDepreciationForCompany::class],
    'payment reminders' => ['reminders:send', SendPaymentRemindersForCompany::class],
    'daily insights' => ['insights:generate', GenerateDailyInsightForCompany::class],
    'scheduled reports' => ['reports:send-scheduled', SendScheduledReportEmailsForCompany::class],
]);

it('limits the reconciliation-stamp backfill to the named organization', function () {
    $this->artisan('banking:backfill-reconciliation-stamps', ['company' => $this->real->id, '--dry-run' => true])
        ->expectsOutputToContain('Company '.$this->real->slug)
        ->doesntExpectOutputToContain($this->decoy->slug)
        ->assertSuccessful();
});

it('refuses an argument that names no organization', function () {
    $this->artisan('recurring:generate', ['company' => 'no-such-organization'])->assertFailed();
});
