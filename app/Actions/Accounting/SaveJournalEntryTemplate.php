<?php

namespace App\Actions\Accounting;

use App\Models\JournalEntryTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Builds or updates a reusable journal-entry template and its lines. Stores the
 * raw line scaffold only (home-currency debit/credit cents); a template need not
 * balance — the user completes and balances the entry when applying it.
 *
 * Templates carry no tax code: the tag is filled from the account's default
 * when the template is applied to a journal entry, exactly as if the account
 * had been picked by hand. (The legacy `tax_code_id` column stays nullable and
 * is never written.)
 *
 * Expected $data shape:
 *   name:      string
 *   is_active: ?bool
 *   lines: array<int, array{
 *     account_id: ?int, debit_cents: int, credit_cents: int, memo: ?string,
 *     class_id: ?int, location_id: ?int, fund_id: ?int
 *   }>
 *
 * Optional ids may arrive as '' from a form select's "—" option; they are
 * stored as null (MySQL strict mode rejects '' for an integer column).
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
                    'account_id' => $this->optionalId($line['account_id'] ?? null),
                    'debit_cents' => (int) ($line['debit_cents'] ?? 0),
                    'credit_cents' => (int) ($line['credit_cents'] ?? 0),
                    'memo' => filled($line['memo'] ?? null) ? $line['memo'] : null,
                    'class_id' => $this->optionalId($line['class_id'] ?? null),
                    'location_id' => $this->optionalId($line['location_id'] ?? null),
                    'fund_id' => $this->optionalId($line['fund_id'] ?? null),
                    'line_order' => $index,
                ]);
            }

            return $template->refresh();
        });
    }

    /**
     * Normalize an optional foreign key: '' / null / 0 → null, anything else → int.
     */
    private function optionalId(mixed $value): ?int
    {
        return filled($value) && (int) $value > 0 ? (int) $value : null;
    }
}
