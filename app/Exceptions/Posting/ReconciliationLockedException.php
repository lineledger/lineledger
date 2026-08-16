<?php

namespace App\Exceptions\Posting;

use App\Contracts\ClientSafeException;
use Carbon\CarbonInterface;
use RuntimeException;

class ReconciliationLockedException extends RuntimeException implements ClientSafeException
{
    public static function for(string $accountName, CarbonInterface $entryDate, CarbonInterface $statementDate): self
    {
        return new self(
            "Entry date {$entryDate->toDateString()} falls within a completed bank reconciliation for {$accountName} through {$statementDate->toDateString()}; undo that reconciliation before posting or voiding."
        );
    }

    public function clientSafeMessage(): string
    {
        return 'This date falls within a completed bank reconciliation; undo it before posting or voiding.';
    }
}
