<?php

namespace App\Exceptions\Posting;

use App\Contracts\ClientSafeException;
use RuntimeException;

class AlreadyPostedException extends RuntimeException implements ClientSafeException
{
    public static function for(int $entryId): self
    {
        return new self("Journal entry #{$entryId} is already posted; corrections must be made via void/reverse.");
    }

    public function clientSafeMessage(): string
    {
        return 'This document is already posted; corrections must be made via void or reverse.';
    }
}
