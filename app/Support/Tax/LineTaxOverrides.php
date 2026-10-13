<?php

namespace App\Support\Tax;

use App\Support\Money;

/**
 * A transaction line's manual tax amounts, as the purchase forms hold them: a
 * map of TAX CODE ID => the decimal string typed into that code's override
 * input (e.g. [12 => '4.00', 15 => '6.75']). Blank or missing means "use the
 * code's calculated amount".
 *
 * Keyed by code rather than by slot so an amount stays with the tax it was
 * typed against: unticking GST moves PST up into the primary slot, and PST's
 * override moves with it instead of being read as GST's.
 *
 * The database still stores two slots per line — `tax_override_cents` for the
 * primary code and `secondary_tax_override_cents` for the secondary — so this
 * class converts between the two shapes. Pure and static: no queries.
 *
 * Ids arrive as ints or numeric strings (Livewire round-trips the map through
 * JSON, and PHP turns a numeric string key into an int), so every lookup
 * normalises the id first.
 */
final class LineTaxOverrides
{
    /**
     * Rebuild the map from a saved line's two slots. A slot whose code is gone
     * has nothing to override and is dropped.
     *
     * @return array<array-key, string>
     */
    public static function fromStored(int|string|null $primaryId, int|string|null $secondaryId, ?int $overrideCents, ?int $secondaryOverrideCents): array
    {
        $overrides = [];

        foreach ([[$primaryId, $overrideCents], [$secondaryId, $secondaryOverrideCents]] as [$codeId, $cents]) {
            $key = self::key($codeId);

            if ($key !== null && $cents !== null) {
                $overrides[$key] = Money::fromCents($cents)->toDecimalString();
            }
        }

        return $overrides;
    }

    /**
     * Keep only the overrides for codes still selected on the line.
     *
     * @param  array<array-key, mixed>  $overrides
     * @param  array<int, int|string|null>  $codeIds
     * @return array<array-key, string>
     */
    public static function prune(array $overrides, array $codeIds): array
    {
        $keep = array_filter(array_map(self::key(...), $codeIds), fn (?string $key): bool => $key !== null);
        $pruned = [];

        foreach ($overrides as $key => $value) {
            if (in_array((string) $key, $keep, true)) {
                $pruned[(string) $key] = self::text($value);
            }
        }

        return $pruned;
    }

    /**
     * The override for one code, in cents. Null when there is no code, no
     * override for it, or the typed value is blank or not (yet) an amount — a
     * half-typed "6." falls back to the calculated tax rather than erroring.
     *
     * @param  array<array-key, mixed>  $overrides
     */
    public static function cents(array $overrides, int|string|null $codeId): ?int
    {
        $key = self::key($codeId);

        if ($key === null || ! array_key_exists($key, $overrides)) {
            return null;
        }

        $value = self::text($overrides[$key]);

        return $value === '' ? null : Money::tryFromString($value)?->cents;
    }

    /**
     * The two stored slots for a line whose primary and secondary codes are
     * the given ids — the shape SaveCheque / SaveExpense / SaveBill take.
     *
     * @param  array<array-key, mixed>  $overrides
     * @return array{tax_override_cents: ?int, secondary_tax_override_cents: ?int}
     */
    public static function toSlots(array $overrides, int|string|null $primaryId, int|string|null $secondaryId): array
    {
        return [
            'tax_override_cents' => self::cents($overrides, $primaryId),
            'secondary_tax_override_cents' => self::cents($overrides, $secondaryId),
        ];
    }

    /**
     * Normalise a code id to the map's key. An unset picker arrives as null,
     * '' or 0.
     */
    private static function key(int|string|null $codeId): ?string
    {
        $codeId = trim((string) $codeId);

        return $codeId === '' || $codeId === '0' ? null : $codeId;
    }

    /**
     * A typed value as a trimmed string; anything that is not a scalar (a
     * tampered request) reads as blank.
     */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
