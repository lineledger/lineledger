<?php

namespace App\Services\Inbox\OCR\Contracts;

/**
 * Extracts structured receipt/bill data from an uploaded document (PDF or
 * image) using OCR. Implementations degrade defensively: any failure (disabled,
 * unkeyed, network, malformed output) returns null so the caller can leave the
 * inbox item for manual entry rather than erroring.
 */
interface ReceiptIntelligence
{
    /**
     * Whether a real (non-null) intelligence is wired up.
     */
    public function isEnabled(): bool;

    /**
     * Read the document at $absolutePath and return its structured fields, or
     * null on decline/failure.
     *
     * @return array{
     *     vendor?: ?string,
     *     subtotal_cents?: ?int,
     *     amount_cents?: ?int,
     *     currency?: ?string,
     *     date?: ?string,
     *     taxes?: list<array{label?: ?string, rate_bp?: ?int, amount_cents?: ?int}>,
     *     line_items?: list<array{description?: ?string, amount_cents?: ?int}>
     * }|null
     */
    public function extract(string $absolutePath, string $mime): ?array;

    /**
     * The last failure mode: 'request_failed' | 'http_NNN' | null (no failure /
     * legit model decline).
     */
    public function lastError(): ?string;
}
