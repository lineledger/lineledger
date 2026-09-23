<?php

namespace App\Support\Translation;

use App\Support\Locales;

/**
 * English source names plus every supported-locale translation. Used when a
 * lookup still keys on account name (opening-balance equity, Stripe clearing)
 * so a chart seeded in French still resolves.
 */
final class LocalizedNames
{
    /**
     * @return list<string>
     */
    public static function of(string $english): array
    {
        $names = [$english];

        foreach (Locales::codes() as $locale) {
            $names[] = trans($english, [], $locale);
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  list<string>  $english
     * @return list<string>
     */
    public static function any(array $english): array
    {
        $names = [];

        foreach ($english as $key) {
            foreach (self::of($key) as $name) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }
}
