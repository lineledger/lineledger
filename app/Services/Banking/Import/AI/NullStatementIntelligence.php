<?php

namespace App\Services\Banking\Import\AI;

use App\Services\Banking\Import\Contracts\StatementIntelligence;
use App\Services\Banking\Import\DTO\ColumnMapping;

/**
 * The default, no-op intelligence: the importer is fully deterministic and no
 * statement data ever leaves the server. Bound whenever AI is disabled or unkeyed.
 */
final class NullStatementIntelligence implements StatementIntelligence
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function inferMapping(array $headers, array $sampleRows): ?ColumnMapping
    {
        return null;
    }

    public function extractTransactions(string $statementText): array
    {
        return [];
    }

    public function extractTransactionsFromPdf(string $absolutePath): array
    {
        return [];
    }

    public function lastError(): ?string
    {
        return null;
    }
}
