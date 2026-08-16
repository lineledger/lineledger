<?php

namespace App\Exceptions\Posting;

use App\Contracts\ClientSafeException;
use RuntimeException;

class UnbalancedJournalException extends RuntimeException implements ClientSafeException
{
    public static function from(int $debits, int $credits): self
    {
        $diff = abs($debits - $credits) / 100;

        return new self("Journal entry is out of balance by {$diff} (debits: {$debits}c, credits: {$credits}c)");
    }

    public function clientSafeMessage(): string
    {
        return 'The journal entry does not balance.';
    }
}
