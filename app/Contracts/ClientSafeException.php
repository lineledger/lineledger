<?php

namespace App\Contracts;

/**
 * Marks an exception whose message is safe to return to API clients.
 *
 * The default-deny error policy treats every exception message as internal
 * (it may leak GL account names, company ids, balances, SKUs, lock dates, …).
 * Only exceptions implementing this contract have their message surfaced to a
 * client, via {@see clientSafeMessage()} — and that message must contain no
 * internal identifiers. The original (detailed) message is still logged.
 */
interface ClientSafeException
{
    /**
     * A message safe to return to API clients — no internal identifiers.
     */
    public function clientSafeMessage(): string;
}
