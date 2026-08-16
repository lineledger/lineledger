<?php

namespace App\Actions\Payroll;

use App\Enums\PayFrequency;
use App\Models\PayrollSchedule;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a payroll schedule, denormalizing periods_per_year from the
 * frequency. Shared by the Livewire setup UI and the API.
 *
 * Expected $data shape:
 *   name:                    string
 *   frequency:               string (PayFrequency value)
 *   anchor_period_end_date:  string (Y-m-d)
 *   default_pay_offset_days: ?int
 *   is_active:               ?bool
 */
final class SavePayrollSchedule
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?PayrollSchedule $schedule = null): PayrollSchedule
    {
        return DB::transaction(function () use ($data, $schedule): PayrollSchedule {
            $frequency = $data['frequency'] instanceof PayFrequency
                ? $data['frequency']
                : PayFrequency::from($data['frequency']);

            $attributes = [
                'name' => $data['name'],
                'frequency' => $frequency,
                'periods_per_year' => $frequency->periodsPerYear(),
                'anchor_period_end_date' => $data['anchor_period_end_date'],
                'default_pay_offset_days' => (int) ($data['default_pay_offset_days'] ?? 0),
                'is_active' => $data['is_active'] ?? true,
            ];

            if ($schedule && $schedule->exists) {
                $schedule->update($attributes);
            } else {
                $schedule = PayrollSchedule::create($attributes);
            }

            return $schedule;
        });
    }
}
