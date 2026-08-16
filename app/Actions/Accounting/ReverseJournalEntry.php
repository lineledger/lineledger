<?php

namespace App\Actions\Accounting;

use App\Models\JournalEntry;
use App\Services\Posting\EntryNumberGenerator;
use App\Services\Posting\JournalPoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds an accrual-style reversing entry: a new *draft* journal entry whose lines
 * are the original's with debit and credit swapped, linked back via reverses_entry_id.
 *
 * Unlike {@see JournalPoster::void()} (a correction that voids
 * the original and posts its reversal), this leaves the original posted and untouched
 * and produces a draft for the user to review and post by hand. The original's
 * reversed_by_entry_id is set when that draft is eventually posted (see JournalPoster::post()).
 */
final class ReverseJournalEntry
{
    public function __construct(protected EntryNumberGenerator $numbers) {}

    public function handle(JournalEntry $original, CarbonImmutable $date, ?string $memo = null): JournalEntry
    {
        return DB::transaction(function () use ($original, $date, $memo): JournalEntry {
            $original->loadMissing('lines', 'company');

            $reversal = JournalEntry::query()->create([
                'company_id' => $original->company_id,
                'entry_no' => $this->numbers->next($original->company),
                'entry_date' => $date->toDateString(),
                'memo' => $memo ?: "Reversal of {$original->entry_no}",
                'reverses_entry_id' => $original->id,
            ]);

            foreach ($original->lines as $i => $line) {
                $reversal->lines()->create([
                    'account_id' => $line->account_id,
                    'debit_cents' => $line->credit_cents,
                    'credit_cents' => $line->debit_cents,
                    'memo' => $line->memo,
                    'contact_id' => $line->contact_id,
                    'tax_code_id' => $line->tax_code_id,
                    'line_order' => $i,
                    'class_id' => $line->class_id,
                    'location_id' => $line->location_id,
                    'fund_id' => $line->fund_id,
                ]);
            }

            return $reversal->fresh(['lines']);
        });
    }
}
