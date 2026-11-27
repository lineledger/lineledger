<?php

namespace App\Services\Inbox\OCR;

use App\Providers\InboxServiceProvider;
use App\Services\Banking\Import\AI\ClaudeStatementIntelligence;
use App\Services\Inbox\OCR\Contracts\ReceiptIntelligence;
use App\Support\Anthropic\StructuredOutputRequest;

/**
 * Claude-backed receipt/bill OCR over the Anthropic Messages API, using
 * structured outputs for validated JSON (see {@see StructuredOutputRequest}). Mirrors
 * {@see ClaudeStatementIntelligence}: every call
 * is defensively wrapped so any failure (network, auth, malformed output)
 * returns null and the caller leaves the item for manual entry. Bound only when
 * OCR is enabled and keyed (see {@see InboxServiceProvider}).
 */
final class ClaudeReceiptIntelligence implements ReceiptIntelligence
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

    public function extract(string $absolutePath, string $mime): ?array
    {
        $bytes = @file_get_contents($absolutePath);

        if ($bytes === false) {
            return null;
        }

        $source = $this->documentBlock($mime, $bytes);

        if ($source === null) {
            return null;
        }

        $input = $this->request(
            system: self::EXTRACTION_SYSTEM,
            user: [
                $source,
                ['type' => 'text', 'text' => 'Extract the vendor, pre-tax subtotal, each tax line (e.g. GST, PST), '
                    .'grand total, currency, transaction date and line items from this receipt or bill. Money is in '
                    .'major units (e.g. dollars).'],
            ],
            schema: self::RECEIPT_SCHEMA,
        );

        return $this->toReceipt($input);
    }

    /**
     * Build the Anthropic content block for this document: a `document` block
     * for PDFs, an `image` block for supported raster types. Returns null for
     * an unsupported MIME so the caller declines cleanly.
     *
     * @return array<string, mixed>|null
     */
    private function documentBlock(string $mime, string $bytes): ?array
    {
        $mime = strtolower(trim($mime));

        if ($mime === 'application/pdf') {
            return ['type' => 'document', 'source' => [
                'type' => 'base64',
                'media_type' => 'application/pdf',
                'data' => base64_encode($bytes),
            ]];
        }

        if (in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            return ['type' => 'image', 'source' => [
                'type' => 'base64',
                'media_type' => $mime,
                'data' => base64_encode($bytes),
            ]];
        }

        return null;
    }

    /**
     * Normalise the validated tool input into the contract's array shape, or
     * null when the model returned nothing usable.
     *
     * @param  array<string, mixed>|null  $input
     * @return array{vendor: ?string, subtotal_cents: ?int, amount_cents: ?int, currency: ?string, date: ?string, taxes: list<array{label: ?string, rate_bp: ?int, amount_cents: ?int}>, line_items: list<array{description: ?string, amount_cents: ?int}>}|null
     */
    private function toReceipt(?array $input): ?array
    {
        if ($input === null) {
            return null;
        }

        $lineItems = [];
        foreach ((array) ($input['line_items'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $lineItems[] = [
                'description' => isset($row['description']) ? (string) $row['description'] : null,
                'amount_cents' => isset($row['amount'])
                    ? (int) round(((float) $row['amount']) * 100)
                    : null,
            ];
        }

        $taxes = [];
        foreach ((array) ($input['taxes'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $amountCents = isset($row['amount']) ? (int) round(((float) $row['amount']) * 100) : null;

            // A zero/empty tax line carries no information — drop it.
            if ($amountCents === null || $amountCents === 0) {
                continue;
            }

            $taxes[] = [
                'label' => isset($row['label']) ? (string) $row['label'] : null,
                'rate_bp' => isset($row['rate']) ? (int) round(((float) $row['rate']) * 100) : null,
                'amount_cents' => $amountCents,
            ];
        }

        return [
            'vendor' => isset($input['vendor']) ? (string) $input['vendor'] : null,
            'subtotal_cents' => isset($input['subtotal'])
                ? (int) round(((float) $input['subtotal']) * 100)
                : null,
            'amount_cents' => isset($input['total'])
                ? (int) round(((float) $input['total']) * 100)
                : null,
            'currency' => isset($input['currency'])
                ? strtoupper((string) $input['currency'])
                : null,
            'date' => isset($input['date']) ? (string) $input['date'] : null,
            'taxes' => $taxes,
            'line_items' => $lineItems,
        ];
    }

    /**
     * Ask for one JSON object matching the schema and return it, or null on any
     * failure so the caller can fall back. Records a transient failure in
     * lastError().
     *
     * @param  string|array<int, array<string, mixed>>  $user  a plain string, or Anthropic content blocks
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    private function request(string $system, string|array $user, array $schema): ?array
    {
        $result = (new StructuredOutputRequest($this->apiKey, $this->baseUrl, $this->model, $this->timeout, $this->effort))
            ->send($system, $user, $schema, maxTokens: 16000, logLabel: 'Receipt OCR');

        $this->lastError = $result->error;

        return $result->data;
    }

    private const EXTRACTION_SYSTEM = 'You read a single receipt or vendor bill and return its structured fields. '
        .'vendor is the merchant/supplier name. subtotal is the pre-tax subtotal and total is the grand total actually '
        .'charged (tax included), both in the document\'s major currency units. taxes lists each distinct tax or levy '
        .'line printed on the document (e.g. GST, HST, PST, QST) with its label as printed, its percent rate (e.g. 5 '
        .'for 5%) and its amount in major units. currency is the ISO 4217 code (e.g. CAD, USD). date is the transaction '
        .'date as ISO YYYY-MM-DD. line_items lists the individual purchased lines with a short description and the '
        .'line amount in major units. Do not invent values: omit any field you cannot read from the document.';

    /** @var array<string, mixed> */
    private const RECEIPT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'vendor' => ['type' => ['string', 'null']],
            'subtotal' => ['type' => ['number', 'null'], 'description' => 'pre-tax subtotal, major units'],
            'total' => ['type' => ['number', 'null'], 'description' => 'grand total, tax included, major units'],
            'currency' => ['type' => ['string', 'null'], 'description' => 'ISO 4217, e.g. CAD'],
            'date' => ['type' => ['string', 'null'], 'description' => 'ISO YYYY-MM-DD'],
            'taxes' => [
                'type' => 'array',
                'description' => 'each distinct tax/levy line on the document (GST, HST, PST, QST, …)',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'label' => ['type' => ['string', 'null'], 'description' => 'tax name as printed, e.g. GST'],
                        'rate' => ['type' => ['number', 'null'], 'description' => 'percent rate, e.g. 5 for 5%'],
                        'amount' => ['type' => ['number', 'null'], 'description' => 'tax amount, major units'],
                    ],
                    'required' => [],
                    'additionalProperties' => false,
                ],
            ],
            'line_items' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'description' => ['type' => 'string'],
                        'amount' => ['type' => ['number', 'null'], 'description' => 'line amount, major units'],
                    ],
                    'required' => ['description'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => [],
        'additionalProperties' => false,
    ];
}
