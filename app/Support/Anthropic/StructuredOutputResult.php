<?php

namespace App\Support\Anthropic;

/**
 * The outcome of a {@see StructuredOutputRequest}: the decoded JSON object, or
 * null with an error code when the failure is transient ('request_failed',
 * 'http_NNN') and null without one when it is not (a refusal, a truncated or
 * unparseable answer).
 */
final readonly class StructuredOutputResult
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public ?array $data,
        public ?string $error,
    ) {}
}
