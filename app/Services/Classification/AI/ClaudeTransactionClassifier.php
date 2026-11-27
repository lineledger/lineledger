<?php

namespace App\Services\Classification\AI;

use App\Providers\ClassificationServiceProvider;
use App\Services\Classification\Contracts\TransactionClassifier;
use App\Services\Inbox\OCR\ClaudeReceiptIntelligence;
use App\Support\Anthropic\StructuredOutputRequest;

/**
 * Claude-backed transaction categorization over the Anthropic Messages API, using
 * structured outputs for validated JSON (see {@see StructuredOutputRequest}). Mirrors
 * {@see ClaudeReceiptIntelligence} — text-only here (no
 * document), every call defensively wrapped so any failure returns an empty map
 * and the caller leaves the line for manual entry. Bound only when the inbox AI
 * gate is open and keyed (see {@see ClassificationServiceProvider}).
 */
final class ClaudeTransactionClassifier implements TransactionClassifier
{
    private ?string $lastError = null;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeout = 60,
        private readonly ?string $effort = 'low',
    ) {}

    public function isEnabled(): bool
    {
        return true;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param  list<string>  $descriptions
     * @param  list<array{code: string, name: string}>  $accounts
     * @return array<string, string>
     */
    public function classify(array $descriptions, array $accounts): array
    {
        $descriptions = array_values(array_filter(array_map('trim', $descriptions), fn (string $d): bool => $d !== ''));

        if ($descriptions === [] || $accounts === []) {
            return [];
        }

        $validCodes = [];
        foreach ($accounts as $account) {
            $validCodes[(string) $account['code']] = true;
        }

        $accountList = implode("\n", array_map(
            fn (array $a): string => '- '.$a['code'].': '.$a['name'],
            $accounts,
        ));

        $chunkSize = max(1, (int) config('classification.ai.max_descriptions', 200));
        $result = [];

        foreach (array_chunk($descriptions, $chunkSize) as $chunk) {
            $numbered = [];
            foreach ($chunk as $i => $description) {
                $numbered[] = $i.'. '.$description;
            }

            $input = $this->request(
                system: self::CLASSIFY_SYSTEM,
                user: "Accounts (choose one by its code):\n".$accountList
                    ."\n\nTransactions to categorize (return the row number and the chosen account code):\n"
                    .implode("\n", $numbered),
                schema: self::CLASSIFY_SCHEMA,
            );

            foreach ((array) ($input['classifications'] ?? []) as $row) {
                if (! is_array($row) || ! isset($row['index'])) {
                    continue;
                }

                $index = (int) $row['index'];
                $code = isset($row['account_code']) ? trim((string) $row['account_code']) : '';

                // Drop nulls and any code the model invented that isn't in the chart.
                if ($code === '' || ! isset($validCodes[$code]) || ! isset($chunk[$index])) {
                    continue;
                }

                $result[$chunk[$index]] = $code;
            }
        }

        return $result;
    }

    /**
     * Ask for one JSON object matching the schema and return it, or null on any
     * failure so the caller can fall back. Records a transient failure in
     * lastError().
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    private function request(string $system, string $user, array $schema): ?array
    {
        $result = (new StructuredOutputRequest($this->apiKey, $this->baseUrl, $this->model, $this->timeout, $this->effort))
            ->send($system, $user, $schema, maxTokens: 16000, logLabel: 'Transaction classification');

        $this->lastError = $result->error;

        return $result->data;
    }

    private const CLASSIFY_SYSTEM = 'You categorize bank and credit-card transactions and receipts for a Canadian '
        .'small business. For each transaction, choose the single best-fitting account by its CODE from the provided '
        .'chart of accounts only. Use the merchant/description to infer the nature of the spend (e.g. fuel → vehicle '
        .'expense, software subscription → software, restaurant → meals). Return null for the account code when no '
        .'account is a reasonable fit. Never invent a code that is not in the provided list.';

    /** @var array<string, mixed> */
    private const CLASSIFY_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'classifications' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'index' => ['type' => 'integer', 'description' => 'the row number of the transaction'],
                        'account_code' => ['type' => ['string', 'null'], 'description' => 'the chosen account code, or null when none fits'],
                    ],
                    'required' => ['index'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['classifications'],
        'additionalProperties' => false,
    ];
}
