<?php

namespace App\Exceptions\Posting;

use App\Contracts\ClientSafeException;
use Carbon\CarbonInterface;
use RuntimeException;

class PeriodLockedException extends RuntimeException implements ClientSafeException
{
    public static function for(CarbonInterface $entryDate, CarbonInterface $lockDate): self
    {
        return new self(
            "Entry date {$entryDate->toDateString()} is on or before the company lock date {$lockDate->toDateString()}; posting is blocked."
        );
    }

    public function clientSafeMessage(): string
    {
        return 'The accounting period for this date is locked; posting is blocked.';
    }
}
