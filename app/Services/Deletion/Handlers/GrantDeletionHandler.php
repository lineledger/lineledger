<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;
use App\Services\Deletion\DeletionWork;
use App\Services\Deletion\SnapshotValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a grant. It posted an award entry and one entry per recognition;
 * all of them (and any void's reversals) leave the ledger with it, each
 * checked against the lock date and filed returns on its own date.
 */
final class GrantDeletionHandler extends DocumentDeletionHandler
{
    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::Grant;
    }

    protected function lines(): ?array
    {
        return null;
    }

    protected function contactColumn(): string
    {
        return 'funder_contact_id';
    }

    protected function totalColumn(): string
    {
        return 'award_amount_cents';
    }

    public function journalEntryIds(Model $document): array
    {
        $award = $document->getAttribute('award_journal_entry_id');

        return array_values(array_unique(array_merge(
            $award !== null ? [(int) $award] : [],
            DB::table('grant_recognitions')->where('grant_id', $document->getKey())->whereNotNull('journal_entry_id')->pluck('journal_entry_id')->map(fn ($id): int => (int) $id)->all(),
        )));
    }

    protected function extraSnapshot(Model $document): array
    {
        return [
            'name' => $this->stringOrNull($document->getAttribute('name')),
            'recognitions' => DB::table('grant_recognitions')
                ->where('grant_id', $document->getKey())
                ->orderBy('recognition_date')
                ->get(['recognition_date', 'amount_cents', 'memo'])
                ->map(fn ($row): array => ['date' => SnapshotValue::date($row->recognition_date), 'amount_cents' => (int) $row->amount_cents, 'memo' => $row->memo])
                ->all(),
        ];
    }

    protected function beforePurge(Model $document, DeletionWork $work): void
    {
        DB::table('grant_recognitions')->where('grant_id', $document->getKey())->delete();
    }
}
