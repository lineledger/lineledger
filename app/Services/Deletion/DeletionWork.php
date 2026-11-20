<?php

namespace App\Services\Deletion;

/**
 * What a delete touched, collected while it runs so the caches can be
 * recomputed once the rows are gone, and so the files are removed only after
 * the transaction commits.
 */
final class DeletionWork
{
    /** @var array<int, true> */
    public array $accountIds = [];

    /** @var array<int, true> */
    public array $contactIds = [];

    /** @var array<int, true> */
    public array $invoiceIds = [];

    /** @var array<int, true> */
    public array $itemIds = [];

    /** @var list<array{disk: string, path: string}> */
    public array $blobs = [];

    public function account(?int $id): void
    {
        if ($id !== null) {
            $this->accountIds[$id] = true;
        }
    }

    public function contact(?int $id): void
    {
        if ($id !== null) {
            $this->contactIds[$id] = true;
        }
    }

    public function invoice(?int $id): void
    {
        if ($id !== null) {
            $this->invoiceIds[$id] = true;
        }
    }

    public function item(?int $id): void
    {
        if ($id !== null) {
            $this->itemIds[$id] = true;
        }
    }
}
