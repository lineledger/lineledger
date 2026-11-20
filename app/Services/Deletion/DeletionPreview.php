<?php

namespace App\Services\Deletion;

use App\Enums\DeletedTransactionState;

/**
 * What deleting a transaction would do, worked out without changing anything:
 * the reasons it can't go ahead yet (blockers) and what the user should know
 * before it does (warnings). The confirmation dialog shows both.
 */
final class DeletionPreview
{
    /**
     * @param  list<DeletionBlocker>  $blockers
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly DeletedTransactionState $state,
        public readonly array $blockers,
        public readonly array $warnings,
    ) {}

    public function allowed(): bool
    {
        return $this->blockers === [];
    }

    /**
     * Plain data for a Livewire property.
     *
     * @return array{state: string, blockers: list<array{message: string, url: ?string}>, warnings: list<string>}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'blockers' => array_map(fn (DeletionBlocker $b): array => $b->toArray(), $this->blockers),
            'warnings' => $this->warnings,
        ];
    }
}
