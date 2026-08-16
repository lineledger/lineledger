<?php

namespace App\Actions\Budgeting;

use App\Models\Budget;
use Illuminate\Support\Facades\DB;

/**
 * Persists a budget and its per-account monthly lines. Framework-agnostic:
 * money arrives already in cents so the same action backs the Livewire form
 * and any future API endpoint. Lines are fully re-synced on every save.
 */
final class SaveBudget
{
    /**
     * @param  array{
     *     name: string,
     *     fiscal_year: int,
     *     class_id?: int|null,
     *     location_id?: int|null,
     *     notes?: string|null,
     *     lines: array<int, array{account_id: int, month_1_cents?: int, month_2_cents?: int, month_3_cents?: int, month_4_cents?: int, month_5_cents?: int, month_6_cents?: int, month_7_cents?: int, month_8_cents?: int, month_9_cents?: int, month_10_cents?: int, month_11_cents?: int, month_12_cents?: int}>
     * }  $data
     */
    public function handle(array $data, ?Budget $budget = null): Budget
    {
        return DB::transaction(function () use ($data, $budget): Budget {
            $header = [
                'name' => $data['name'],
                'fiscal_year' => (int) $data['fiscal_year'],
                'class_id' => $data['class_id'] ?? null,
                'location_id' => $data['location_id'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];

            if ($budget && $budget->exists) {
                $budget->update($header);
            } else {
                $budget = Budget::create($header);
            }

            $budget->lines()->delete();

            $order = 0;

            foreach (array_values($data['lines']) as $line) {
                $months = [];
                $total = 0;

                for ($month = 1; $month <= 12; $month++) {
                    $cents = (int) ($line["month_{$month}_cents"] ?? 0);
                    $months["month_{$month}_cents"] = $cents;
                    $total += $cents;
                }

                // An all-zero row carries no budget information; skip it so the
                // grid can keep blank rows without persisting noise.
                if ($total === 0) {
                    continue;
                }

                $budget->lines()->create([
                    'account_id' => $line['account_id'],
                    'line_order' => $order++,
                    ...$months,
                ]);
            }

            return $budget->refresh();
        });
    }
}
