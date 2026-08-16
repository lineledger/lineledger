<?php

namespace App\Actions\Payroll;

use App\Models\EmployeePayrollProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records an employee's termination: stamps the termination date and deactivates
 * the payroll profile so they drop out of future pay runs. The ROE itself is then
 * produced from the (now-complete) posted history — see {@see RoeCalculator}.
 */
final class TerminateEmployee
{
    public function handle(EmployeePayrollProfile $profile, string $terminationDate): EmployeePayrollProfile
    {
        return DB::transaction(function () use ($profile, $terminationDate): EmployeePayrollProfile {
            $profile->forceFill([
                'termination_date' => CarbonImmutable::parse($terminationDate)->toDateString(),
                'is_active' => false,
            ])->save();

            return $profile->refresh();
        });
    }
}
