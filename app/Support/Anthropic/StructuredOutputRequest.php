<?php

namespace App\Support\Anthropic;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One Anthropic Messages API request whose answer must be JSON matching a
 * schema, sent with structured outputs (`output_config.format`). The AI
 * features (receipt OCR, statement import, transaction classification, daily
 * insights) all ask for exactly one JSON object and fall back to a manual or
 * template path when they don't get it, so this never throws: every outcome
 * is a {@see StructuredOutputResult}.
 *
 * Error codes on the result mean "transient — try again" to the callers
 * ('request_failed' for a network error or timeout, 'http_NNN' for an API
 * error). A refusal (`stop_reason: refusal`), a truncated answer
 * (`stop_reason: max_tokens`) or unparseable output is logged but leaves the
 * error null: retrying would not change it.
 */
final class StructuredOutputRequest
{
    private readonly ?string $effort;

    /**
     * @param  string|null  $effort  output_config.effort (low | medium | high | xhigh | max); null or '' omits it,
     *                               for a model that doesn't support effort
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeout,
        ?string $effort,
    ) {
        $this->effort = $effort === null || trim($effort) === '' ? null : trim($effort);
    }

    /**
     * @param  string|array<int, array<string, mixed>>  $user  a plain string, or Anthropic content blocks
     * @param  array<string, mixed>  $schema  a JSON Schema; every object must set additionalProperties: false
     * @param  string  $logLabel  prefix for log lines, e.g. "Receipt OCR"
     * @param  array<string, mixed>  $logContext  added to every log line, e.g. which task this was
     */
    public function send(string $system, string|array $user, array $schema, int $maxTokens, string $logLabel, array $logContext = []): StructuredOutputResult
    {
        $outputConfig = ['format' => ['type' => 'json_schema', 'schema' => $schema]];

        if ($this->effort !== null) {
            $outputConfig['effort'] = $this->effort;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout($this->timeout)->post($this->baseUrl.'/v1/messages', [
                'model' => $this->model,
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]],
                'output_config' => $outputConfig,
            ]);
        } catch (Throwable $e) {
            Log::warning($logLabel.' request failed', [...$logContext, 'error' => $e->getMessage()]);

            return new StructuredOutputResult(null, 'request_failed');
        }

        if (! $response->successful()) {
            Log::warning($logLabel.' request returned an error', [...$logContext, 'status' => $response->status()]);

            return new StructuredOutputResult(null, 'http_'.$response->status());
        }

        $stopReason = $response->json('stop_reason');

        if ($stopReason === 'refusal') {
            Log::warning($logLabel.' request was declined by the model', [
                ...$logContext,
                'category' => $response->json('stop_details.category'),
            ]);

            return new StructuredOutputResult(null, null);
        }

        if ($stopReason === 'max_tokens') {
            Log::warning($logLabel.' response was cut off at max_tokens', [...$logContext, 'max_tokens' => $maxTokens]);

            return new StructuredOutputResult(null, null);
        }

        // Read by type: with thinking on, the answer can follow a `thinking` block.
        foreach ((array) $response->json('content', []) as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $data = json_decode($block['text'], true);

                if (is_array($data)) {
                    /** @var array<string, mixed> $data */
                    return new StructuredOutputResult($data, null);
                }

                Log::warning($logLabel.' response was not a JSON object', $logContext);

                return new StructuredOutputResult(null, null);
            }
        }

        Log::warning($logLabel.' response had no text block', $logContext);

        return new StructuredOutputResult(null, null);
    }
}
