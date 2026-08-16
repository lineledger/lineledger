<?php

namespace App\Services\Classification\Contracts;

/**
 * The AI fallback for transaction categorization: given a batch of merchant
 * descriptions and the company's selectable accounts, return the best-fitting
 * account CODE for each description it is confident about. Used only after the
 * deterministic history lookup comes up empty, and only when the inbox AI gate
 * is open. Implementations must be defensive — any failure returns an empty map
 * so the caller leaves those transactions for manual categorization.
 */
interface TransactionClassifier
{
    public function isEnabled(): bool;

    /**
     * @param  list<string>  $descriptions  merchant/transaction descriptions
     * @param  list<array{code: string, name: string}>  $accounts  the company's selectable accounts
     * @return array<string, string> description => chosen account code (confident picks only)
     */
    public function classify(array $descriptions, array $accounts): array;

    public function lastError(): ?string;
}
