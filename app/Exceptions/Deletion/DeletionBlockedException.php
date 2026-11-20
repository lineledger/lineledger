<?php

namespace App\Exceptions\Deletion;

use App\Contracts\ClientSafeException;
use App\Services\Deletion\DeletionBlocker;
use RuntimeException;

/**
 * A delete refused because something still depends on the transaction (a
 * payment applied to the invoice, a deposit holding the receipt, …) or a rule
 * forbids it (the setting is off, the stock has moved since). Carries every
 * reason at once, each with a link to the record to fix, so the user sees the
 * whole list rather than one refusal at a time.
 */
class DeletionBlockedException extends RuntimeException implements ClientSafeException
{
    /**
     * @param  list<DeletionBlocker>  $blockers
     */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct(implode(' ', array_map(fn (DeletionBlocker $b): string => $b->message, $blockers)));
    }

    public function clientSafeMessage(): string
    {
        return $this->getMessage();
    }
}
