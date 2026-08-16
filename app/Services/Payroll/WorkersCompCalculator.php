<?php

namespace App\Services\Payroll;

use App\Models\Company;
use App\Models\EmployeePayrollProfile;
use App\Models\WorkersCompSetting;

/**
 * Computes the employer workers'-comp (WSIB/WCB) levy for one pay-run line, from
 * the company's per-province {@see WorkersCompSetting}. The levy is the assessable
 * earnings (gross, capped at the province's annual maximum per worker) × the rate
 * per $100. Quebec returns 0 — it is covered by CNESST — so the two never
 * double-count; exempt employees and provinces with no rate also return 0.
 */
class WorkersCompCalculator
{
    /** @var array<string, ?WorkersCompSetting> */
    private array $cache = [];

    public function compute(Company $company, EmployeePayrollProfile $profile, string $province, int $grossCents, int $ytdGrossCents): int
    {
        $province = mb_strtoupper(trim($province));

        // Quebec is assessed via CNESST, not WC; exempt workers and non-positive
        // pay never accrue.
        if ($province === 'QC' || $profile->workers_comp_exempt || $grossCents <= 0) {
            return 0;
        }

        $setting = $this->settingFor((int) $company->id, $province);

        // A per-employee rate group overrides the province default.
        $rateBp = $profile->workers_comp_rate_bp ?? ($setting?->is_active ? $setting->rate_bp : null);

        if ($rateBp === null || $rateBp <= 0) {
            return 0;
        }

        // Cap the assessable earnings at the province's per-worker annual maximum.
        $assessable = $grossCents;
        $max = $setting?->annual_max_assessable_cents;

        if ($max !== null) {
            $assessable = min($assessable, max(0, $max - $ytdGrossCents));
        }

        return (int) round($assessable * $rateBp / 10000);
    }

    private function settingFor(int $companyId, string $province): ?WorkersCompSetting
    {
        $key = $companyId.':'.$province;

        return $this->cache[$key] ??= WorkersCompSetting::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('province', $province)
            ->first();
    }
}
