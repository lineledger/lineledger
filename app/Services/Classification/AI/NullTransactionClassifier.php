<?php

namespace App\Services\Classification\AI;

use App\Providers\ClassificationServiceProvider;
use App\Services\Classification\Contracts\TransactionClassifier;

/**
 * No-op classifier bound whenever AI categorization is switched off or unkeyed
 * (see {@see ClassificationServiceProvider}). Every transaction
 * falls through to the deterministic history lookup, then to manual review.
 */
final class NullTransactionClassifier implements TransactionClassifier
{
    public function isEnabled(): bool
    {
        return false;
    }

    /**
     * @param  list<string>  $descriptions
     * @param  list<array{code: string, name: string}>  $accounts
     * @return array<string, string>
     */
    public function classify(array $descriptions, array $accounts): array
    {
        return [];
    }

    public function lastError(): ?string
    {
        return null;
    }
}
