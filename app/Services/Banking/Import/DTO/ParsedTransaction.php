<?php

namespace App\Services\Banking\Import\DTO;

use Carbon\CarbonImmutable;

/**
 * One normalized transaction, format-agnostic. {@see $amountCents} is a signed
 * book-delta: positive = a debit to the account (money into an asset bank), negative
 * = a credit. This matches OFX TRNAMT and the ledger's debit_cents - credit_cents.
 */
final readonly class ParsedTransaction
{
    /**
     * @param  array<string, mixed>  $raw  the original record, for audit / re-mapping
     */
    public function __construct(
        public CarbonImmutable $date,
        public int $amountCents,
        public string $description,
        public ?string $externalId = null,
        public ?string $checkNumber = null,
        public ?int $balanceCents = null,
        public array $raw = [],
    ) {}
}
