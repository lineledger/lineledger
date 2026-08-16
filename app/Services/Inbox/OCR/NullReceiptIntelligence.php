<?php

namespace App\Services\Inbox\OCR;

use App\Services\Inbox\OCR\Contracts\ReceiptIntelligence;

/**
 * The default, no-op OCR: extraction is unavailable, so an uploaded document
 * goes straight to manual review and no file ever leaves the server. Bound
 * whenever OCR is disabled or unkeyed.
 */
final class NullReceiptIntelligence implements ReceiptIntelligence
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function extract(string $absolutePath, string $mime): ?array
    {
        return null;
    }

    public function lastError(): ?string
    {
        return null;
    }
}
