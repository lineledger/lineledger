<?php

namespace App\Actions\Payroll;

use App\Models\Company;
use App\Models\WorkersCompSetting;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles a company's per-province workers'-comp rates: upserts each row by
 * province and drops the ones the user removed. The UI passes the rate as dollars
 * per $100 of payroll ($2.50 → 250 bp). Quebec rows are ignored — CNESST applies.
 */
final class SaveWorkersCompSettings
{
    /**
     * @param  array<int, array{province?: string, rate?: int|float|string, annual_max?: int|float|string, board_account?: ?string}>  $rows
     */
    public function handle(Company $company, array $rows): void
    {
        DB::transaction(function () use ($company, $rows): void {
            $keep = [];

            foreach ($rows as $row) {
                $province = mb_strtoupper(trim((string) ($row['province'] ?? '')));

                if ($province === '' || $province === 'QC') {
                    continue;
                }

                $maxInput = $row['annual_max'] ?? '';

                $setting = WorkersCompSetting::updateOrCreate(
                    ['company_id' => $company->id, 'province' => $province],
                    [
                        'rate_bp' => (int) round((float) ($row['rate'] ?? 0) * 100), // $/$100 → basis points
                        'annual_max_assessable_cents' => $maxInput !== '' ? (int) round((float) $maxInput * 100) : null,
                        'board_account' => ($row['board_account'] ?? '') ?: null,
                        'is_active' => true,
                    ],
                );

                $keep[] = $setting->id;
            }

            WorkersCompSetting::where('company_id', $company->id)
                ->whereNotIn('id', $keep ?: [0])
                ->delete();
        });
    }
}
