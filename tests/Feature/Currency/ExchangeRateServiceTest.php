<?php

use App\Models\Company;
use App\Models\ExchangeRate;
use App\Services\Currency\ExchangeRateService;
use App\Services\Currency\MissingExchangeRateException;
use App\Services\Currency\Providers\NullExchangeRateProvider;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create(['currency_code' => 'CAD']);
    $this->provider = new NullExchangeRateProvider;
    $this->service = new ExchangeRateService($this->provider);
});

it('returns 1 for the home currency', function () {
    expect($this->service->rateFor($this->company, 'CAD', CarbonImmutable::parse('2026-01-01')))->toBe('1');
});

it('uses a stored global rate, most recent on or before the date', function () {
    ExchangeRate::create([
        'company_id' => null, 'base_code' => 'USD', 'quote_code' => 'CAD',
        'rate' => '1.30', 'rate_date' => '2026-01-01', 'source' => ExchangeRate::SOURCE_API,
    ]);
    ExchangeRate::create([
        'company_id' => null, 'base_code' => 'USD', 'quote_code' => 'CAD',
        'rate' => '1.40', 'rate_date' => '2026-01-10', 'source' => ExchangeRate::SOURCE_API,
    ]);

    // As of the 5th, the 1st's rate applies (10th is in the future).
    expect((float) $this->service->rateFor($this->company, 'USD', CarbonImmutable::parse('2026-01-05')))->toBe(1.30)
        ->and((float) $this->service->rateFor($this->company, 'USD', CarbonImmutable::parse('2026-01-15')))->toBe(1.40);
});

it('prefers a company manual override over the global rate', function () {
    ExchangeRate::create([
        'company_id' => null, 'base_code' => 'USD', 'quote_code' => 'CAD',
        'rate' => '1.40', 'rate_date' => '2026-01-10', 'source' => ExchangeRate::SOURCE_API,
    ]);

    $this->service->setManualRate($this->company, 'USD', '1.50', CarbonImmutable::parse('2026-01-10'));

    expect((float) $this->service->rateFor($this->company, 'USD', CarbonImmutable::parse('2026-01-10')))->toBe(1.50);
});

it('falls back to the provider and caches the fetched rate as a global row', function () {
    $this->provider->set('USD', ['CAD' => '1.37']);

    $rate = $this->service->rateFor($this->company, 'USD', CarbonImmutable::parse('2026-02-01'));

    expect((float) $rate)->toBe(1.37);

    $this->assertDatabaseHas('exchange_rates', [
        'company_id' => null, 'base_code' => 'USD', 'quote_code' => 'CAD',
        'rate_date' => '2026-02-01', 'source' => ExchangeRate::SOURCE_API,
    ]);
});

it('throws when no rate can be resolved', function () {
    $this->service->rateFor($this->company, 'GBP', CarbonImmutable::parse('2026-02-01'));
})->throws(MissingExchangeRateException::class);
