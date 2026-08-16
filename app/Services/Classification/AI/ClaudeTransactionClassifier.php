<?php

namespace App\Services\Classification\AI;

use App\Providers\ClassificationServiceProvider;
use App\Services\Classification\Contracts\TransactionClassifier;
use App\Services\Inbox\OCR\ClaudeReceiptIntelligence;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Claude-backed transaction categorization over the Anthropic Messages API using
 * forced tool-use for validated, structured output. Mirrors
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

            $input = $this->callTool(
                system: self::CLASSIFY_SYSTEM,
                user: "Accounts (choose one by its code):\n".$accountList
                    ."\n\nTransactions to categorize (return the row number and the chosen account code):\n"
                    .implode("\n", $numbered),
                toolName: 'classify_transactions',
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
     * Force a single tool call and return its validated input object, or null on
     * any failure so the caller can fall back.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    private function callTool(string $system, string $user, string $toolName, array $schema): ?array
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
            $this->lastError = 'request_failed';
            Log::warning('Transaction classification request failed', ['tool' => $toolName, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            $this->lastError = 'http_'.$response->status();
            Log::warning('Transaction classification request returned an error', ['tool' => $toolName, 'status' => $response->status()]);

            return null;
        }

        foreach ((array) $response->json('content', []) as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === $toolName) {
                return is_array($block['input'] ?? null) ? $block['input'] : null;
            }
        }

        return null;
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
                ],
            ],
        ],
        'required' => ['classifications'],
    ];
}
