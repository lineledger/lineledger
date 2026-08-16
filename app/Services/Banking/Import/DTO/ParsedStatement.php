<?php

namespace App\Services\Banking\Import\DTO;

use Carbon\CarbonImmutable;

/**
 * The result of parsing a statement file: the normalized transactions plus any
 * statement-level metadata the format provided (period, opening/closing balance).
 */
final readonly class ParsedStatement
{
    /**
     * @param  list<ParsedTransaction>  $transactions
     * @param  array<string, mixed>  $meta  parser diagnostics (date format, sign, ai_used, skipped rows…)
     */
    public function __construct(
        public array $transactions,
        public ?CarbonImmutable $beginDate = null,
        public ?CarbonImmutable $endDate = null,
        public ?int $beginBalanceCents = null,
        public ?int $endBalanceCents = null,
        public ?string $currency = null,
        public array $meta = [],
    ) {}

    public function count(): int
    {
        return count($this->transactions);
    }
}
