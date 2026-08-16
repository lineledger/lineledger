<?php

namespace App\Services\Inventory;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class MovementContext
{
    public function __construct(
        public readonly CarbonInterface $movementDate,
        public readonly ?string $sourceType = null,
        public readonly ?int $sourceId = null,
        public readonly ?int $sourceLineId = null,
        public readonly ?int $journalEntryId = null,
        public readonly ?string $notes = null,
    ) {}

    public static function for(CarbonInterface|string $date, ?string $sourceType = null, ?int $sourceId = null, ?int $sourceLineId = null, ?int $journalEntryId = null, ?string $notes = null): self
    {
        return new self(
            movementDate: $date instanceof CarbonInterface ? $date : CarbonImmutable::parse($date),
            sourceType: $sourceType,
            sourceId: $sourceId,
            sourceLineId: $sourceLineId,
            journalEntryId: $journalEntryId,
            notes: $notes,
        );
    }
}
