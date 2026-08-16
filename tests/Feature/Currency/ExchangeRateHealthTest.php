<?php

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Notifications\ExchangeRateHealthAlert;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

/**
 * Covers the FX freshness check end to end: the rates:health command (exit code
 * + alert email), the /health/fx endpoint (status codes), and the edge cases that
 * must NOT alarm (no foreign currencies configured).
 */
beforeEach(function () {
    config()->set('services.exchange_rates.health.max_age_hours', 26);
    config()->set('services.exchange_rates.health.alert_email', 'hello@lineledger.ca');

    $this->company = Company::factory()->create(['currency_code' => 'CAD']);
    app()->instance('current_company', $this->company);
    app(EnableCompanyCurrency::class)->handle($this->company, 'USD');
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function storeApiRate(string $base, string $quote, $fetchedAt): ExchangeRate
{
    return ExchangeRate::create([
        'company_id' => null,
        'base_code' => $base,
        'quote_code' => $quote,
        'rate' => '1.38',
        'rate_date' => $fetchedAt->toDateString(),
        'source' => ExchangeRate::SOURCE_API,
        'provider' => 'frankfurter',
        'fetched_at' => $fetchedAt,
    ]);
}

it('passes and sends no alert when the expected pair was fetched recently', function () {
    Notification::fake();
    storeApiRate('USD', 'CAD', now()->subHour());

    $exit = Artisan::call('rates:health');

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('refreshed within');
    Notification::assertNothingSent();
});

it('fails and emails the ops address when the newest rate is older than the threshold', function () {
    Notification::fake();
    storeApiRate('USD', 'CAD', now()->subHours(48));

    $exit = Artisan::call('rates:health');

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('stale');

    Notification::assertSentOnDemand(
        ExchangeRateHealthAlert::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'hello@lineledger.ca',
    );
});

it('fails when the expected pair has never been fetched', function () {
    Notification::fake();

    $exit = Artisan::call('rates:health');

    expect($exit)->toBe(1);
    Notification::assertSentOnDemand(ExchangeRateHealthAlert::class);
});

it('does not email when --no-alert is passed, but still reports failure', function () {
    Notification::fake();
    storeApiRate('USD', 'CAD', now()->subHours(48));

    $exit = Artisan::call('rates:health', ['--no-alert' => true]);

    expect($exit)->toBe(1);
    Notification::assertNothingSent();
});

it('is healthy with no alert when no foreign currencies are configured', function () {
    Notification::fake();
    // A plain single-currency company has no active foreign pair to fetch.
    $solo = Company::factory()->create(['currency_code' => 'CAD']);
    app()->instance('current_company', $solo);
    // Remove the multi-currency company set up in beforeEach so nothing is expected.
    $this->company->currencies()->withoutGlobalScopes()->delete();
    $this->company->forceFill(['multicurrency_enabled' => false])->save();

    $exit = Artisan::call('rates:health');

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('nothing to fetch');
    Notification::assertNothingSent();
});

it('serves 200 from /health/fx when rates are fresh', function () {
    storeApiRate('USD', 'CAD', now()->subHour());

    $this->getJson('/health/fx')
        ->assertStatus(200)
        ->assertJson(['status' => 'ok', 'healthy' => true, 'expected_pairs' => 1]);
});

it('serves 503 from /health/fx when rates are stale', function () {
    storeApiRate('USD', 'CAD', now()->subHours(48));

    $this->getJson('/health/fx')
        ->assertStatus(503)
        ->assertJson(['status' => 'stale', 'healthy' => false]);
});
