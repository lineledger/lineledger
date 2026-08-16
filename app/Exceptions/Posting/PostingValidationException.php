<?php

namespace App\Exceptions\Posting;

use App\Contracts\ClientSafeException;
use RuntimeException;

/**
 * A caller-fixable guard hit while building/posting a document (e.g. "no lines",
 * "transfer needs two accounts"). Its message is authored and free of internal
 * identifiers, so it is surfaced to API clients verbatim — unlike a bare
 * RuntimeException, which the API treats as internal and returns generically.
 */
class PostingValidationException extends RuntimeException implements ClientSafeException
{
    public function clientSafeMessage(): string
    {
        return $this->getMessage();
    }
}
