<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Services\Currency\ExchangeRateHealth;
use Illuminate\Http\JsonResponse;

/**
 * Liveness probe for the daily provider FX rate fetch. Returns 200 when every
 * active foreign currency pair has a fresh provider rate and 503 when any pair is
 * stale, so an external uptime monitor can alert independently of the email.
 */
class ExchangeRateHealthController extends Controller
{
    public function __invoke(ExchangeRateHealth $health): JsonResponse
    {
        $report = $health->check();

        return response()->json(
            $report->toArray(),
            $report->healthy ? 200 : 503,
        );
    }
}
