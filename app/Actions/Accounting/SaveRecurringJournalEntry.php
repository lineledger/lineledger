<?php

namespace App\Actions\Accounting;

use App\Actions\Recurring\SaveRecurringDocument;
use App\Enums\RecurrenceEndType;
use App\Enums\RecurrenceFrequency;
use App\Models\RecurringJournalEntry;
use App\Services\Recurring\NextRunDateCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Builds or updates a memorized journal-entry template and its debit/credit lines.
 * Mirrors {@see SaveRecurringDocument}: header + lines in one
 * transaction, next_run_date anchored only before the first generation, lines fully
 * replaced on each save.
 *
 * Expected $data shape:
 *   name:            ?string
 *   memo:            ?string
 *   frequency:       string  weekly|monthly|quarterly|semi_annual|annual
 *   start_date:      string
 *   day_of_month:    ?int    (monthly+ cadences)
 *   end_type:        string  never|on_date|after_occurrences
 *   end_date:        ?string
 *   max_occurrences: ?int
 *   lines: array<int, array{
 *     account_id: int, debit_cents: int, credit_cents: int,
 *     memo: ?string, contact_id: ?int, class_id: ?int, location_id: ?int, fund_id: ?int
 *   }>
 */
final class SaveRecurringJournalEntry
{
    public function __construct(protected NextRunDateCalculator $calculator) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?RecurringJournalEntry $schedule = null): RecurringJournalEntry
    {
        return DB::transaction(function () use ($data, $schedule): RecurringJournalEntry {
            $frequency = RecurrenceFrequency::from($data['frequency']);
            $endType = RecurrenceEndType::from($data['end_type']);

            $header = [
                'name' => $data['name'] ?? null,
                'memo' => $data['memo'] ?? null,
                'frequency' => $frequency,
                'start_date' => $data['start_date'],
                'day_of_month' => $frequency->usesDayOfMonth() ? ($data['day_of_month'] ?? null) : null,
                'end_type' => $endType,
                'end_date' => $endType === RecurrenceEndType::OnDate ? ($data['end_date'] ?? null) : null,
                'max_occurrences' => $endType === RecurrenceEndType::AfterOccurrences
                    ? ($data['max_occurrences'] ?? null)
                    : null,
            ];

            if ($schedule && $schedule->exists) {
                $schedule->update($header);
            } else {
                $schedule = RecurringJournalEntry::create($header + [
                    'occurrences_generated' => 0,
                    'is_active' => true,
                ]);
            }

            // Anchor the upcoming run date only before the first generation.
            if ((int) $schedule->occurrences_generated === 0) {
                $schedule->next_run_date = $this->calculator->first($schedule)->toDateString();
                $schedule->save();
            }

            $schedule->lines()->delete();

            $order = 0;
            foreach (array_values($data['lines']) as $line) {
                $debit = (int) ($line['debit_cents'] ?? 0);
                $credit = (int) ($line['credit_cents'] ?? 0);

                if ($debit === 0 && $credit === 0) {
                    continue;
                }

                $schedule->lines()->create([
                    'company_id' => $schedule->company_id,
                    'account_id' => $line['account_id'],
                    'debit_cents' => $debit,
                    'credit_cents' => $credit,
                    'memo' => $line['memo'] ?? null,
                    'contact_id' => $line['contact_id'] ?? null,
                    'class_id' => $line['class_id'] ?? null,
                    'location_id' => $line['location_id'] ?? null,
                    'fund_id' => $line['fund_id'] ?? null,
                    'line_order' => $order++,
                ]);
            }

            return $schedule->refresh();
        });
    }
}
