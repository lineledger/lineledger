<?php

namespace App\Exceptions\Access;

use RuntimeException;

/**
 * Saving or deleting a role would take permissions away from people who hold
 * it. Taking access away signs them out and revokes the API keys they made, so
 * the change waits for an explicit confirmation listing who is affected.
 */
class RoleNarrowingNeedsConfirmation extends RuntimeException
{
    /**
     * @param  list<string>  $affected  names of the people who would lose access
     */
    public function __construct(public readonly array $affected)
    {
        parent::__construct('Confirm taking access away from: '.implode(', ', $affected));
    }
}
