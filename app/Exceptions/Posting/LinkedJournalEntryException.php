<?php

namespace App\Exceptions\Posting;

use App\Contracts\ClientSafeException;
use App\Models\JournalEntry;
use RuntimeException;

/**
 * Guards a journal entry the general journal does not manage from being
 * changed there. Entries with a non-null source_type are owned by another
 * document (Deposit, Invoice, Bill, …) which keeps its own state in sync, and
 * the reversing entry a void wrote belongs to that void; editing either here
 * would desync the record. Manual entries are exempt (see
 * JournalEntry::isManualEntry()).
 */
class LinkedJournalEntryException extends RuntimeException implements ClientSafeException
{
    public static function for(JournalEntry $entry): self
    {
        if ($entry->source_type === null && $entry->isVoidReversal()) {
            return new self(__('This entry reverses voided entry :no and belongs to that void, so it can\'t be changed from the general journal.', [
                'no' => (string) $entry->reverses?->entry_no,
            ]));
        }

        $source = class_basename((string) $entry->source_type);

        return new self(
            "This entry is managed by {$source} and can't be edited from the general journal; edit it from that record instead."
        );
    }

    public function clientSafeMessage(): string
    {
        return $this->getMessage();
    }
}
