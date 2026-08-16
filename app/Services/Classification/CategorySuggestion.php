<?php

namespace App\Services\Classification;

/**
 * A single category suggestion for a transaction: which account to post it to,
 * an optional tax code, and where the suggestion came from. Suggest-only — the
 * caller pre-fills a review screen with this; nothing is posted on its strength.
 *
 * {@see $confidence} is an internal 0–100 score used only to order/compare
 * candidates while resolving; it is deliberately NOT persisted to a statement
 * line's `match_confidence`, which means *ledger-match* confidence.
 */
final readonly class CategorySuggestion
{
    public const SOURCE_CONTACT_DEFAULT = 'contact_default';

    public const SOURCE_HISTORY = 'history';

    public const SOURCE_AI = 'ai';

    public function __construct(
        public int $accountId,
        public ?int $taxCodeId,
        public int $confidence,
        public string $reason,
        public string $source,
    ) {}
}
