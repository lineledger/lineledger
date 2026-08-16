<?php

namespace App\Enums;

/**
 * How a bank rule's pattern is tested against an imported statement line's
 * description. All comparisons are case-insensitive.
 */
enum BankRuleMatchType: string
{
    case Contains = 'contains';
    case StartsWith = 'starts_with';
    case Equals = 'equals';
    case Regex = 'regex';

    public function label(): string
    {
        return match ($this) {
            self::Contains => __('Contains'),
            self::StartsWith => __('Starts with'),
            self::Equals => __('Equals'),
            self::Regex => __('Matches regex'),
        };
    }

    public function matches(string $haystack, string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }

        $h = mb_strtolower($haystack);
        $p = mb_strtolower($pattern);

        return match ($this) {
            self::Contains => str_contains($h, $p),
            self::StartsWith => str_starts_with($h, $p),
            self::Equals => $h === $p,
            // Delimited, case-insensitive; a malformed pattern simply never matches.
            self::Regex => @preg_match('/'.str_replace('/', '\/', $pattern).'/i', $haystack) === 1,
        };
    }
}
