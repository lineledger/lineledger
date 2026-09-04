<?php

namespace App\Support;

use InvalidArgumentException;
use NumberFormatter;
use Stringable;

/**
 * Immutable value object representing a monetary amount in integer minor units (cents).
 * No floats anywhere internally — addition, subtraction, and comparison are exact.
 */
final readonly class Money implements Stringable
{
    public function __construct(
        public int $cents,
        public string $currency = 'CAD',
    ) {
        //
    }

    public static function fromCents(int $cents, string $currency = 'CAD'): self
    {
        return new self($cents, $currency);
    }

    public static function zero(string $currency = 'CAD'): self
    {
        return new self(0, $currency);
    }

    /**
     * Parse a decimal string like "1,234.56" or "1234.56" into cents. A bare
     * fraction such as ".05" is accepted (people type cents that way), so the
     * only shapes rejected are non-numeric text, a trailing dot, and more than
     * two decimals.
     * Two-decimal currencies only (covers CAD/USD/EUR/GBP and most). For
     * zero- or three-decimal currencies, swap to a Money library later.
     */
    public static function fromString(string $value, string $currency = 'CAD'): self
    {
        return self::tryFromString($value, $currency)
            ?? throw new InvalidArgumentException("Invalid money string: [{$value}]");
    }

    /**
     * Lenient counterpart to {@see fromString}: returns null instead of
     * throwing on unparseable input. Use this for transient UI state such as
     * `wire:model.live` fields that recompute on every keystroke, where a
     * half-typed value like "6." should be ignored rather than crash.
     */
    public static function tryFromString(string $value, string $currency = 'CAD'): ?self
    {
        $clean = preg_replace('/[\s,]/', '', trim($value));

        if ($clean === null || $clean === '' || ! preg_match('/^-?(\d+(\.\d{1,2})?|\.\d{1,2})$/', $clean)) {
            return null;
        }

        $negative = str_starts_with($clean, '-');
        $clean = ltrim($clean, '-');

        [$whole, $fraction] = str_contains($clean, '.') ? explode('.', $clean, 2) : [$clean, ''];

        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);

        $cents = ((int) $whole) * 100 + (int) $fraction;

        return new self($negative ? -$cents : $cents, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents + $other->cents, $this->currency);
    }

    public function sub(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents - $other->cents, $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->cents, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents && $this->currency === $other->currency;
    }

    /**
     * "1234.56" — useful for form inputs.
     */
    public function toDecimalString(): string
    {
        $negative = $this->cents < 0;
        $abs = abs($this->cents);

        return ($negative ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Localized currency format (e.g. "$1,234.56").
     */
    public function format(?string $locale = null): string
    {
        $formatter = new NumberFormatter($locale ?? app()->getLocale(), NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($this->cents / 100, $this->currency);
    }

    /**
     * Spell the amount in words for cheque writing, e.g.
     * "One Hundred Fifty-Four and 63/100". Title-cased to match
     * QuickBooks convention (compound words like "Fifty-Four" are also
     * capitalized after the hyphen). Always returns the absolute value.
     */
    public function toWords(?string $locale = null): string
    {
        $abs = abs($this->cents);
        $whole = intdiv($abs, 100);
        $fraction = $abs % 100;

        $formatter = new NumberFormatter($locale ?? app()->getLocale(), NumberFormatter::SPELLOUT);
        $words = ucwords((string) $formatter->format($whole), ' -');

        return $words.' and '.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT).'/100';
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}");
        }
    }
}
