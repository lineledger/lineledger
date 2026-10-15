<?php

namespace App\Exceptions\Posting;

use App\Contracts\ClientSafeException;
use Carbon\CarbonInterface;
use RuntimeException;

class ReconciliationLockedException extends RuntimeException implements ClientSafeException
{
    public static function for(string $accountName, CarbonInterface $statementDate): self
    {
        return new self(
            "This transaction includes a line reconciled on the {$accountName} reconciliation through {$statementDate->toDateString()}. Undo that reconciliation to change or void it, or post a separate adjusting entry."
        );
    }

    public function clientSafeMessage(): string
    {
        return 'This transaction includes a reconciled bank line. Undo that reconciliation to change or void it, or post a separate adjusting entry.';
    }
}
