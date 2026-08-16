<?php

namespace App\Support\Payroll;

use App\Models\EmployeePayrollProfile;
use App\Models\TimeOffPolicy;
use App\Services\Payroll\CalculatePayRun;

/**
 * The pay codes a time entry can carry: the fixed wage codes (regular work,
 * explicit overtime, banked overtime, stat-holiday hours) plus the company's
 * active time-off policy codes (vacation, sick, banked, …). The pull routes
 * each code to the matching pay-run earning, where
 * {@see CalculatePayRun} prices it (wage codes by the
 * {@see EarningTypeCatalogue} multiplier, time-off codes by the policy's paid
 * flag, banked overtime as a $0 bank accrual) and posting draws any matching
 * accrual balance down.
 *
 * Codes are resolved against the CURRENT company (time-off policies are
 * company-scoped), so every method assumes a bound tenant context.
 */
final class TimeEntryPayCodeCatalogue
{
    public const REGULAR = 'regular';

    public const OVERTIME_BANKED = 'overtime_banked';

    /**
     * Wage codes every company has, code => label. Kept deliberately short:
     * anything leave-like should be a time-off policy so it carries a balance.
     *
     * @return array<string, string>
     */
    private static function wageCodes(): array
    {
        $all = EarningTypeCatalogue::all();

        return [
            self::REGULAR => $all['regular']['name'],
            'overtime' => $all['overtime']['name'],
            'overtime_double' => $all['overtime_double']['name'],
            'stat_holiday' => $all['stat_holiday']['name'],
        ];
    }

    /**
     * All selectable pay codes for the current company, code => label.
     * Banked overtime appears once any active employee has banking enabled
     * (the write paths still gate it per employee); time-off policy codes are
     * appended after the wage codes, and a policy whose code collides with a
     * wage code keeps the wage entry (first wins).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = self::wageCodes();

        if (EmployeePayrollProfile::query()->where('is_active', true)->where('banked_overtime_enabled', true)->exists()) {
            $options[self::OVERTIME_BANKED] = EarningTypeCatalogue::all()[self::OVERTIME_BANKED]['name'];
        }

        foreach (TimeOffPolicy::query()->where('is_active', true)->orderBy('name')->get() as $policy) {
            $options[$policy->code] ??= $policy->name;
        }

        return $options;
    }

    /**
     * The subset THIS employee may pick in the portal: the wage codes, banked
     * overtime only when their own profile may bank, and only the time-off
     * policies assigned to them (an unassigned policy code would silently pay
     * as plain wages instead of drawing a balance).
     *
     * @return array<string, string>
     */
    public static function portalOptions(?EmployeePayrollProfile $profile): array
    {
        $options = self::wageCodes();

        if ($profile === null) {
            return $options;
        }

        if (BankedOvertimeRules::canBank($profile)) {
            $options[self::OVERTIME_BANKED] = EarningTypeCatalogue::all()[self::OVERTIME_BANKED]['name'];
        }

        // Assigned policies plus the company defaults — first use materializes
        // the assignment (see EmployeePayrollProfile::availableTimeOffPolicies).
        foreach ($profile->availableTimeOffPolicies() as $policy) {
            $options[$policy->code] ??= $policy->name;
        }

        return $options;
    }

    public static function isValid(string $code, ?EmployeePayrollProfile $portalProfile = null, bool $portal = false): bool
    {
        return array_key_exists($code, $portal ? self::portalOptions($portalProfile) : self::options());
    }

    /** Whether a code is one of the fixed wage codes (never a time-off policy). */
    public static function isWageCode(string $code): bool
    {
        return $code === self::OVERTIME_BANKED || array_key_exists($code, self::wageCodes());
    }

    /** The display label for a code, falling back to a humanized code. */
    public static function label(string $code): string
    {
        return self::options()[$code]
            ?? EarningTypeCatalogue::all()[$code]['name']
            ?? ucfirst(str_replace('_', ' ', $code));
    }
}
