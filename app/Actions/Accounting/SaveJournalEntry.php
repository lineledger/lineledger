<?php

namespace App\Actions\Accounting;

use App\Exceptions\Posting\LinkedJournalEntryException;
use App\Models\JournalEntry;
use App\Services\Posting\EntryNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds or rebuilds a journal entry header and its lines. Shared by the
 * Livewire journal form and the API. Does NOT post — balance enforcement,
 * period locking and the draft → posted flip stay in JournalPoster.
 *
 * Zero/zero lines (no debit and no credit) are dropped, matching the form.
 *
 * Expected $data shape (cents-based, framework-agnostic):
 *   entry_no:   ?string  (null → auto-generated JE-xxxxxx)
 *   entry_date: string
 *   memo:       ?string
 *   lines: array<int, array{
 *     account_id: int, debit_cents: int, credit_cents: int,
 *     memo: ?string, contact_id: ?int, tax_code_id: ?int,
 *     class_id: ?int, location_id: ?int,
 *     currency_code: ?string, fx_rate: ?string,
 *     foreign_debit_cents: ?int, foreign_credit_cents: ?int
 *   }>
 *
 * tax_code_id is a reporting tag only — no tax amounts are derived from it.
 *
 * debit_cents / credit_cents are always HOME cents (the entry balances in home).
 * The optional currency_code / fx_rate / foreign_* fields are a memo of the
 * original foreign amount on a foreign-account line; they do not affect balancing.
 */
final class SaveJournalEntry
{
    public function __construct(protected EntryNumberGenerator $numbers) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?JournalEntry $entry = null): JournalEntry
    {
        return DB::transaction(function () use ($data, $entry): JournalEntry {
            $company = app('current_company');
            $entryDate = CarbonImmutable::parse($data['entry_date'])->toDateString();

            $header = [
                'entry_date' => $entryDate,
                'memo' => $data['memo'] ?? null,
            ];

            if ($entry && $entry->exists) {
                if ($entry->source_type !== null) {
                    throw LinkedJournalEntryException::for($entry);
                }
                if (! empty($data['entry_no'])) {
                    $header['entry_no'] = $data['entry_no'];
                }
                $entry->update($header);
            } else {
                $entry = JournalEntry::create($header + [
                    'entry_no' => $data['entry_no'] ?? $this->numbers->next($company),
                ]);
            }

            $entry->lines()->delete();

            $order = 0;
            foreach (array_values($data['lines']) as $line) {
                $debit = (int) ($line['debit_cents'] ?? 0);
                $credit = (int) ($line['credit_cents'] ?? 0);

                if ($debit === 0 && $credit === 0) {
                    continue;
                }

                $currencyCode = ! empty($line['currency_code']) && ! $company->isHomeCurrency($line['currency_code'])
                    ? mb_strtoupper((string) $line['currency_code'])
                    : null;

                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'contact_id' => $line['contact_id'] ?? null,
                    'debit_cents' => $debit,
                    'credit_cents' => $credit,
                    'memo' => $line['memo'] ?? null,
                    'tax_code_id' => $line['tax_code_id'] ?? null,
                    'line_order' => $order++,
                    'class_id' => $line['class_id'] ?? null,
                    'location_id' => $line['location_id'] ?? null,
                    'fund_id' => $line['fund_id'] ?? null,
                    ...($currencyCode !== null ? [
                        'currency_code' => $currencyCode,
                        'fx_rate' => $line['fx_rate'] ?? null,
                        'foreign_debit_cents' => (int) ($line['foreign_debit_cents'] ?? 0),
                        'foreign_credit_cents' => (int) ($line['foreign_credit_cents'] ?? 0),
                    ] : []),
                ]);
            }

            $entry->refresh();

            return $entry;
        });
    }
}
