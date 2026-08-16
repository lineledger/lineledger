<?php

namespace App\Actions\Accounting;

use App\Models\Company;
use App\Models\CurrencyRevaluation;
use App\Services\Posting\CurrencyRevaluationService;
use Carbon\CarbonImmutable;

/**
 * Thin entry point for the period-end Home Currency Adjustment, shared by the
 * Livewire settings page and the API. Delegates to {@see CurrencyRevaluationService}.
 */
class RunHomeCurrencyAdjustment
{
    public function __construct(protected CurrencyRevaluationService $revaluations) {}

    /**
     * @param  array<string, string>  $rateOverrides  currency code => closing rate
     */
    public function handle(Company $company, CarbonImmutable $asOf, array $rateOverrides = []): ?CurrencyRevaluation
    {
        return $this->revaluations->revalue($company, $asOf, $rateOverrides);
    }
}
