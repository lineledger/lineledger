<?php

namespace App\Services\Deletion;

use Illuminate\Database\Eloquent\Model;

/**
 * A handler whose document owns other documents that post entries of their
 * own (a pay run's payroll cheques). Their entries are the document's own as
 * well, so TransactionDeleter deletes them with it rather than refusing.
 */
interface OwnsChildEntries
{
    /**
     * The child documents whose entries go with this one.
     *
     * @return list<array{0: class-string<Model>, 1: int}>
     */
    public function childEntrySources(Model $document): array;
}
