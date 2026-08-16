<?php

namespace App\Support;

final readonly class GlobalSearchResult
{
    public function __construct(
        public string $type,
        public string $label,
        public ?string $secondary,
        public ?string $meta,
        public ?int $amountCents,
        public string $url,
    ) {}
}
