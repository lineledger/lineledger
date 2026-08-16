<?php

namespace App\Actions\Accounting;

use App\Models\JournalEntryTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Builds or updates a reusable journal-entry template and its lines. Stores the
 * raw line scaffold only (home-currency debit/credit cents); a template need not
 * balance — the user completes and balances the entry when applying it.
 *
 * Expected $data shape:
 *   name:      string
 *   is_active: ?bool
 *   lines: array<int, array{
 *     account_id: ?int, debit_cents: int, credit_cents: int, memo: ?string,
 *     tax_code_id: ?int, class_id: ?int, location_id: ?int, fund_id: ?int
 *   }>
 */
final class SaveJournalEntryTemplate
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?JournalEntryTemplate $template = null): JournalEntryTemplate
    {
        return DB::transaction(function () use ($data, $template): JournalEntryTemplate {
            $header = [
                'name' => $data['name'],
                'is_active' => $data['is_active'] ?? true,
            ];

            if ($template && $template->exists) {
                $template->update($header);
            } else {
                $template = JournalEntryTemplate::create($header);
            }

            $template->lines()->delete();

            foreach (array_values($data['lines']) as $index => $line) {
                $template->lines()->create([
                    'company_id' => $template->company_id,
                    'account_id' => $line['account_id'] ?? null,
                    'debit_cents' => (int) ($line['debit_cents'] ?? 0),
                    'credit_cents' => (int) ($line['credit_cents'] ?? 0),
                    'memo' => $line['memo'] ?? null,
                    'tax_code_id' => $line['tax_code_id'] ?? null,
                    'class_id' => $line['class_id'] ?? null,
                    'location_id' => $line['location_id'] ?? null,
                    'fund_id' => $line['fund_id'] ?? null,
                    'line_order' => $index,
                ]);
            }

            return $template->refresh();
        });
    }
}
