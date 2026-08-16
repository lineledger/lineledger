<?php

namespace App\Services\Banking\Import\Contracts;

use App\Services\Banking\Import\AI\NullStatementIntelligence;
use App\Services\Banking\Import\DTO\ColumnMapping;
use App\Services\Banking\Import\DTO\ParsedTransaction;

/**
 * Optional AI assist for statement import. The deterministic pipeline never depends
 * on it: when disabled (the default — see {@see NullStatementIntelligence})
 * CSV mapping falls back to the wizard and PDF parsing to the heuristic structurer.
 *
 * The design keeps token cost tiny and data egress minimal: for CSV it infers only
 * the *column mapping* from a small sample of rows (the full file is then parsed
 * deterministically in PHP), and for PDF it sends the already-extracted text.
 */
interface StatementIntelligence
{
    public function isEnabled(): bool;

    /**
     * Infer a column mapping from a sample. Returns null if the model declines or
     * the result is unusable, so the caller falls back to the manual wizard.
     *
     * @param  list<string>  $headers
     * @param  list<array<string, ?string>>  $sampleRows
     */
    public function inferMapping(array $headers, array $sampleRows): ?ColumnMapping;

    /**
     * Extract structured transactions from already-extracted statement text (PDF).
     *
     * @return list<ParsedTransaction>
     */
    public function extractTransactions(string $statementText): array;

    /**
     * Extract transactions from the PDF file itself — the fallback when local text
     * extraction fails (owner-password "protected" or scanned/image PDFs). The model
     * reads the rendered document directly.
     *
     * @return list<ParsedTransaction>
     */
    public function extractTransactionsFromPdf(string $absolutePath): array;

    /**
     * Why the most recent call returned nothing usable, when the cause was the AI
     * service itself being unavailable (network error, timeout, 5xx) rather than the
     * model legitimately declining. Null when the last call succeeded, was never
     * attempted, or the model simply returned no result. Lets callers show a
     * "temporarily unavailable — try again" message instead of a generic failure.
     */
    public function lastError(): ?string;
}
