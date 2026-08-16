<?php

namespace App\Exceptions\Posting;

use App\Contracts\ClientSafeException;
use App\Models\JournalEntry;
use RuntimeException;

/**
 * Guards a source-linked journal entry from being edited through the general
 * journal. Entries with a non-null source_type are owned by another document
 * (Deposit, Invoice, Bill, …) which keeps its own state in sync; editing them
 * here would desync that record. Manual entries (source_type null) are exempt.
 */
class LinkedJournalEntryException extends RuntimeException implements ClientSafeException
{
    public static function for(JournalEntry $entry): self
    {
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
