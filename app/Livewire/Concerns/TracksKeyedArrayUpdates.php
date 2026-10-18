<?php

namespace App\Livewire\Concerns;

/**
 * For a public array keyed by record id and bound per row
 * (`wire:model.live="lineTax.{{ $line->id }}"`).
 *
 * Livewire usually sends one entry ("lineTax.42"), and the updated hook gets
 * its key. But the browser sends the WHOLE array whenever it changes shape —
 * the first entry on an empty array (a JSON list becoming an object), or keys
 * arriving in a different order — and then the hook's key is null. Treating
 * that as "every row was picked" would confirm rows nobody touched, so the
 * updating hook records which entries actually differ from the server's copy
 * and the updated hook applies only those.
 *
 * Hosts call rememberChangedKeys() from updating{Property}($value, $key) when
 * $key is null, then loop over changedKeys() in updated{Property}().
 */
trait TracksKeyedArrayUpdates
{
    /** @var array<string, list<int|string>> property => keys whose value changed in a whole-array update */
    private array $changedArrayKeys = [];

    protected function rememberChangedKeys(string $property, mixed $new): void
    {
        $old = (array) $this->{$property};
        $changed = [];

        foreach ((array) $new as $key => $value) {
            // A sparse JS array pads the ids it skipped with nulls.
            if ($value === null) {
                continue;
            }

            // Loose on purpose: the browser sends "5" where the server holds 5.
            if (! array_key_exists($key, $old) || $old[$key] != $value) {
                $changed[] = $key;
            }
        }

        $this->changedArrayKeys[$property] = $changed;
    }

    /**
     * The row keys an update touched: the one in $key, or — for a whole-array
     * update — the ones rememberChangedKeys() found. Drops the null padding
     * a whole-array update leaves behind.
     *
     * @return list<int|string>
     */
    protected function updatedKeys(string $property, int|string|null $key): array
    {
        if ($key !== null) {
            return [explode('.', (string) $key)[0]];
        }

        $this->{$property} = array_filter((array) $this->{$property}, static fn ($value): bool => $value !== null);

        return $this->changedArrayKeys[$property] ?? [];
    }
}
