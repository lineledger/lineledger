<?php

namespace App\Support\Payroll;

use App\Models\EmployeePayrollProfile;

/**
 * Provincial employment-standards rules for banking overtime as paid time off
 * (time-off-in-lieu), keyed by the employee's province of employment. One
 * auditable table, same shape as {@see PayStatementJurisdiction}:
 *
 * - allowed: New Brunswick's ES Act has no banking provision — block it there.
 * - multiplier_bp: most jurisdictions bank at the OVERTIME rate (1.5 hours per
 *   overtime hour, 15000); Alberta (agreements after Sept 2019) and Yukon bank
 *   at straight time (10000). A pre-2019 Alberta agreement can override per
 *   employee on the profile.
 * - agreement_required: every banking jurisdiction expects a written
 *   agreement/request — the profile's agreement date is the server-side gate.
 * - payout_deadline_days: how long banked time may sit before it must be taken
 *   or paid out (advisory reporting; null = no statutory deadline).
 *
 * Sources: provincial/territorial employment-standards acts + Canada Labour
 * Code (federal), reviewed 2026-06.
 */
final class BankedOvertimeRules
{
    /**
     * @return array{allowed: bool, multiplier_bp: int, agreement_required: bool, payout_deadline_days: ?int}
     */
    public static function forProvince(string $code): array
    {
        $rule = fn (int $multiplierBp, ?int $deadlineDays): array => [
            'allowed' => true,
            'multiplier_bp' => $multiplierBp,
            'agreement_required' => true,
            'payout_deadline_days' => $deadlineDays,
        ];

        return match (mb_strtoupper($code)) {
            'NB' => ['allowed' => false, 'multiplier_bp' => 15000, 'agreement_required' => true, 'payout_deadline_days' => null],
            'AB' => $rule(10000, 180),
            'YT' => $rule(10000, null),
            'BC' => $rule(15000, 180),
            'SK', 'QC', 'NT', 'NU' => $rule(15000, 365),
            'MB', 'ON', 'PE' => $rule(15000, 90),
            'NS', 'NL' => $rule(15000, null),
            default => $rule(15000, 90), // federal (CLC) default
        };
    }

    public static function isAllowed(string $provinceCode): bool
    {
        return self::forProvince($provinceCode)['allowed'];
    }

    /**
     * The bank-accrual multiplier for an employee: their profile override
     * (e.g. a pre-2019 Alberta agreement at 1.5×) or the province default.
     */
    public static function multiplierBpFor(EmployeePayrollProfile $profile): int
    {
        return (int) ($profile->banked_overtime_multiplier_bp
            ?? self::forProvince((string) $profile->province_of_employment)['multiplier_bp']);
    }

    /**
     * Whether this employee may bank overtime right now: the feature is enabled
     * on the profile, the province permits banking, and (where required) the
     * written agreement date is recorded. This is the server-side gate the
     * time-entry writes and the pay-run calculation both enforce.
     */
    public static function canBank(EmployeePayrollProfile $profile): bool
    {
        if (! $profile->banked_overtime_enabled) {
            return false;
        }

        $rules = self::forProvince((string) $profile->province_of_employment);

        if (! $rules['allowed']) {
            return false;
        }

        return ! $rules['agreement_required'] || $profile->banked_overtime_agreement_date !== null;
    }
}
