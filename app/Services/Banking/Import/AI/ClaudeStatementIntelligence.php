<?php

namespace App\Services\Banking\Import\AI;

use App\Providers\BankingServiceProvider;
use App\Services\Banking\Import\Contracts\StatementIntelligence;
use App\Services\Banking\Import\DTO\ColumnMapping;
use App\Services\Banking\Import\DTO\ParsedTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Claude-backed intelligence over the Anthropic Messages API using forced tool-use
 * for validated, structured output. Every call is defensively wrapped: any failure
 * (network, auth, malformed output) returns null/[] so the caller degrades to the
 * deterministic path rather than erroring. Bound only when AI is enabled and keyed
 * (see {@see BankingServiceProvider}).
 */
final class ClaudeStatementIntelligence implements StatementIntelligence
{
    private ?string $lastError = null;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeout = 60,
    ) {}

    public function isEnabled(): bool
    {
        return true;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function inferMapping(array $headers, array $sampleRows): ?ColumnMapping
    {
        $maxRows = (int) config('banking.statement_import.ai.max_sample_rows', 15);

        $input = $this->callTool(
            system: 'You map a bank statement export (CSV/Excel) to a transaction schema. '
                .'Use the EXACT header strings provided for column names. amountMode is "single" for one signed amount column, '
                .'or "debit_credit" for separate money-out (debit) and money-in (credit) columns. dateFormat is a PHP date() '
                .'format string matching the sample dates (e.g. Y-m-d, m/d/Y, d/m/Y). Set flipSign true only when a single '
                .'amount column is positive for withdrawals. Only map columns that exist.',
            user: 'Headers and sample rows (JSON):'.PHP_EOL.json_encode([
                'headers' => array_values($headers),
                'rows' => array_slice($sampleRows, 0, $maxRows),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            toolName: 'provide_column_mapping',
            schema: self::MAPPING_SCHEMA,
        );

        if ($input === null) {
            return null;
        }

        $mapping = ColumnMapping::fromArray($input);

        return $mapping->isComplete() ? $mapping : null;
    }

    public function extractTransactions(string $statementText): array
    {
        return $this->toTransactions($this->callTool(
            system: self::EXTRACTION_SYSTEM,
            user: $statementText,
            toolName: 'provide_transactions',
            schema: self::TRANSACTIONS_SCHEMA,
        ));
    }

    /**
     * Read the PDF itself (not extracted text). Claude renders the document natively,
     * so this works for owner-password "protected" PDFs and scanned/image PDFs that
     * have no extractable text layer — the cases the local extractor cannot handle.
     */
    public function extractTransactionsFromPdf(string $absolutePath): array
    {
        $bytes = @file_get_contents($absolutePath);

        if ($bytes === false) {
            return [];
        }

        return $this->toTransactions($this->callTool(
            system: self::EXTRACTION_SYSTEM,
            user: [
                ['type' => 'document', 'source' => [
                    'type' => 'base64',
                    'media_type' => 'application/pdf',
                    'data' => base64_encode($bytes),
                ]],
                ['type' => 'text', 'text' => 'Extract every transaction from this bank statement PDF. Read the columns to determine each amount and its sign, and include the running balance when shown.'],
            ],
            toolName: 'provide_transactions',
            schema: self::TRANSACTIONS_SCHEMA,
        ));
    }

    /**
     * @param  array<string, mixed>|null  $input
     * @return list<ParsedTransaction>
     */
    private function toTransactions(?array $input): array
    {
        if ($input === null || ! isset($input['transactions']) || ! is_array($input['transactions'])) {
            return [];
        }

        $transactions = [];
        foreach ($input['transactions'] as $row) {
            if (! is_array($row) || ! isset($row['date'], $row['amount'])) {
                continue;
            }

            // Safety net: drop any balance/total row the model mislabelled as a
            // transaction (its "amount" would otherwise be a running balance).
            if (preg_match('/\b(opening|closing)\s+balance\b|\bbalance\s+(brought|carried)\s+forward\b|^\s*totals?\b/i', (string) ($row['description'] ?? '')) === 1) {
                continue;
            }

            try {
                $date = CarbonImmutable::parse((string) $row['date']);
            } catch (Throwable) {
                continue;
            }

            $transactions[] = new ParsedTransaction(
                date: $date,
                amountCents: (int) round(((float) $row['amount']) * 100),
                description: (string) ($row['description'] ?? ''),
                balanceCents: isset($row['balance']) && $row['balance'] !== null
                    ? (int) round(((float) $row['balance']) * 100)
                    : null,
                raw: ['ai' => $row],
            );
        }

        return $transactions;
    }

    /**
     * Force a single tool call and return its validated input object, or null on any
     * failure so the caller can fall back.
     *
     * @param  string|array<int, array<string, mixed>>  $user  a plain string, or Anthropic content blocks
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    private function callTool(string $system, string|array $user, string $toolName, array $schema): ?array
    {
        $this->lastError = null;

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout($this->timeout)->post($this->baseUrl.'/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 8192,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]],
                'tools' => [[
                    'name' => $toolName,
                    'description' => 'Return the structured result.',
                    'input_schema' => $schema,
                ]],
                'tool_choice' => ['type' => 'tool', 'name' => $toolName],
            ]);
        } catch (Throwable $e) {
            // Network error / timeout — the service is unreachable. Flag it so the
            // caller can tell the user it's temporary, and log it for operators.
            $this->lastError = 'request_failed';
            Log::warning('Bank statement AI request failed', ['tool' => $toolName, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            $this->lastError = 'http_'.$response->status();
            Log::warning('Bank statement AI request returned an error', ['tool' => $toolName, 'status' => $response->status()]);

            return null;
        }

        foreach ((array) $response->json('content', []) as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === $toolName) {
                return is_array($block['input'] ?? null) ? $block['input'] : null;
            }
        }

        return null;
    }

    private const EXTRACTION_SYSTEM = 'You extract real transactions from this bank statement. Exclude opening-balance, '
        .'closing-balance, brought/carried-forward, and total rows — those are not transactions. date is ISO YYYY-MM-DD. '
        .'Always include the running balance shown on each row. Decide each amount and its sign from that running balance: a '
        .'transaction amount equals its own row balance minus the previous row balance, so the signed amounts walk the balance '
        .'from the opening figure to the closing figure. Positive = money into the account, negative = money out. When a '
        .'statement uses separate debit/credit columns, the balance is the source of truth for the sign — not the column. '
        .'Do not invent transactions.';

    /** @var array<string, mixed> */
    private const MAPPING_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'amountMode' => ['type' => 'string', 'enum' => ['single', 'debit_credit']],
            'dateColumn' => ['type' => 'string'],
            'descriptionColumns' => ['type' => 'array', 'items' => ['type' => 'string']],
            'amountColumn' => ['type' => ['string', 'null']],
            'debitColumn' => ['type' => ['string', 'null']],
            'creditColumn' => ['type' => ['string', 'null']],
            'balanceColumn' => ['type' => ['string', 'null']],
            'dateFormat' => ['type' => 'string'],
            'decimalSeparator' => ['type' => 'string', 'enum' => ['.', ',']],
            'flipSign' => ['type' => 'boolean'],
        ],
        'required' => ['amountMode', 'dateColumn', 'dateFormat'],
    ];

    /** @var array<string, mixed> */
    private const TRANSACTIONS_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'transactions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string', 'description' => 'ISO YYYY-MM-DD'],
                        'amount' => ['type' => 'number', 'description' => 'signed; positive = money in'],
                        'description' => ['type' => 'string'],
                        'balance' => ['type' => ['number', 'null']],
                    ],
                    'required' => ['date', 'amount', 'description'],
                ],
            ],
        ],
        'required' => ['transactions'],
    ];
}
