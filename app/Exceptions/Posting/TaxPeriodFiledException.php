<?php

namespace App\Exceptions\Posting;

use App\Contracts\ClientSafeException;
use Carbon\CarbonInterface;
use RuntimeException;

class TaxPeriodFiledException extends RuntimeException implements ClientSafeException
{
    public static function for(string $agencyName, CarbonInterface $entryDate, CarbonInterface $periodStart, CarbonInterface $periodEnd): self
    {
        return new self(
            "A tax return for {$agencyName} covering {$periodStart->toDateString()} to {$periodEnd->toDateString()} has been filed; "
            ."posting on {$entryDate->toDateString()} with that agency's tax codes is blocked. Void the return first to allow edits."
        );
    }

    public function clientSafeMessage(): string
    {
        return 'A filed tax return covers this date; void the return before posting.';
    }
}
