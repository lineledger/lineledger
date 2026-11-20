<?php

namespace App\Services\Deletion;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\TaxCode;

/**
 * Readable copies of what a delete removes, for the audit row and the
 * tombstone. Every reference carries its name or code beside its id: after a
 * restore the ids name nothing (an audit payload is never remapped), so the
 * names are what the Voided/Deleted report and the deleted transaction's page
 * show.
 *
 * Deliberately separate from AccountingAuditRecorder::snapshotJournalEntry(),
 * whose shape every posted entry's audit row already carries.
 */
final class DeletionSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function journalEntry(JournalEntry $entry): array
    {
        $entry->loadMissing('lines.account', 'lines.contact', 'lines.classification', 'lines.location');

        $taxCodes = TaxCode::withoutGlobalScopes()
            ->whereIn('id', $entry->lines->pluck('tax_code_id')->filter()->unique()->all())
            ->pluck('code', 'id');

        return [
            'id' => (int) $entry->id,
            'entry_no' => $entry->entry_no,
            'entry_date' => SnapshotValue::date($entry->entry_date),
            'memo' => $entry->memo,
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id !== null ? (int) $entry->source_id : null,
            'posted_at' => SnapshotValue::stamp($entry->posted_at),
            'voided_at' => SnapshotValue::stamp($entry->voided_at),
            'reverses_entry_id' => $entry->reverses_entry_id !== null ? (int) $entry->reverses_entry_id : null,
            'lines' => $entry->lines
                ->sortBy('line_order')
                ->values()
                ->map(fn (JournalLine $line): array => [
                    'id' => (int) $line->id,
                    'account_id' => (int) $line->account_id,
                    'account_code' => $line->account?->code,
                    'account_name' => $line->account?->name,
                    'debit_cents' => (int) $line->debit_cents,
                    'credit_cents' => (int) $line->credit_cents,
                    'memo' => $line->memo,
                    'contact_id' => $line->getAttribute('contact_id') !== null ? (int) $line->getAttribute('contact_id') : null,
                    'contact_name' => $line->contact?->display_name,
                    'tax_code_id' => $line->getAttribute('tax_code_id') !== null ? (int) $line->getAttribute('tax_code_id') : null,
                    'tax_code' => $taxCodes[(int) $line->getAttribute('tax_code_id')] ?? null,
                    'currency_code' => $line->currency_code,
                    'fx_rate' => $line->fx_rate !== null ? (string) $line->fx_rate : null,
                    'foreign_debit_cents' => $line->getAttribute('foreign_debit_cents') !== null ? (int) $line->getAttribute('foreign_debit_cents') : null,
                    'foreign_credit_cents' => $line->getAttribute('foreign_credit_cents') !== null ? (int) $line->getAttribute('foreign_credit_cents') : null,
                    'class' => $line->classification?->getAttribute('name'),
                    'location' => $line->location?->getAttribute('name'),
                    'fund_id' => $line->fund_id !== null ? (int) $line->fund_id : null,
                    'cleared_at' => $line->cleared_at !== null ? (string) $line->cleared_at : null,
                ])
                ->all(),
        ];
    }
}
