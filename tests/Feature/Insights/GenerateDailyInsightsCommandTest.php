<?php

use App\Jobs\GenerateDailyInsightForCompany;
use App\Models\Company;
use Illuminate\Support\Facades\Queue;

it('dispatches one job per company', function () {
    Queue::fake();

    Company::factory()->count(2)->create();

    $this->artisan('insights:generate')->assertExitCode(0);

    Queue::assertPushed(GenerateDailyInsightForCompany::class, 2);
});

it('filters to a single company by slug', function () {
    Queue::fake();

    $target = Company::factory()->create();
    Company::factory()->create();

    $this->artisan("insights:generate {$target->slug}")->assertExitCode(0);

    Queue::assertPushed(
        GenerateDailyInsightForCompany::class,
        fn (GenerateDailyInsightForCompany $job): bool => $job->companyId === $target->id,
    );
    Queue::assertPushed(GenerateDailyInsightForCompany::class, 1);
});

it('errors on an unknown company', function () {
    Queue::fake();

    $this->artisan('insights:generate nope')->assertExitCode(1);

    Queue::assertNothingPushed();
});

it('runs inline with --sync and reports a quiet day for empty books', function () {
    $company = Company::factory()->create();

    $this->artisan('insights:generate --sync')
        ->expectsOutputToContain("{$company->slug} — no insight today.")
        ->assertExitCode(0);

    $this->assertDatabaseCount('daily_insights', 0);
});

it('the queued job runs end to end without error for empty books', function () {
    $company = Company::factory()->create();

    dispatch_sync(new GenerateDailyInsightForCompany($company->id));

    $this->assertDatabaseCount('daily_insights', 0);
});
