<?php

namespace App\Exceptions\Posting;

use App\Contracts\ClientSafeException;
use RuntimeException;

class ReconciliationOutOfBalanceException extends RuntimeException implements ClientSafeException
{
    public static function from(int $differenceCents): self
    {
        $diff = abs($differenceCents) / 100;

        return new self("Reconciliation is out of balance by {$diff}. Adjust marked items until the difference is zero.");
    }

    public function clientSafeMessage(): string
    {
        return 'The reconciliation is out of balance; adjust marked items until the difference is zero.';
    }
}
