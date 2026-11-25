<?php

namespace App\Exceptions\Access;

use App\Contracts\ClientSafeException;
use App\Contracts\CompanyAccess;
use RuntimeException;

/**
 * Something tried to change the books of an organization that is read-only
 * ({@see CompanyAccess}). Permissions already refuse every write
 * there; this is the posting backstop's refusal.
 */
class CompanyReadOnlyException extends RuntimeException implements ClientSafeException
{
    public function __construct()
    {
        parent::__construct(__('This organization is read-only, so nothing can be posted, voided or changed in its books.'));
    }

    public function clientSafeMessage(): string
    {
        return $this->getMessage();
    }
}
