<?php

namespace App\Enums;

/**
 * Which source pre-filled a statement line's suggested category / payee / bill.
 * Drives the "why" shown on review and gates "Always do this" (no point creating
 * a rule from a line a rule already covers).
 */
enum StatementSuggestionSource: string
{
    case Rule = 'rule';
    case History = 'history';
    case OpenBill = 'open_bill';
    case ContactDefault = 'contact_default';
    case Ai = 'ai';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::Rule => __('Bank rule'),
            self::History => __('Past categorization'),
            self::OpenBill => __('Open bill'),
            self::ContactDefault => __('Vendor default'),
            self::Ai => __('AI'),
            self::User => __('You'),
        };
    }
}
