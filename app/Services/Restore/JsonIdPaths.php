<?php

namespace App\Services\Restore;

use Closure;

/**
 * Finds and rewrites the row ids held inside a JSON column, by path.
 *
 * A path is dot-separated segments, matched against the decoded value:
 *
 *  - a literal key (`accountId`) steps into that key;
 *  - `*` steps into every element of a list (or every value of an object);
 *  - `{key}` says the object's **keys** are the ids (`reconciliation.defaults`
 *    is keyed by bank account id) and must be the last segment;
 *  - the empty path is the value itself (a JSON column holding a bare id).
 *
 * Remapping drops what doesn't map rather than keep a source id: a list loses
 * the element, an object the key, and a single value becomes null. Copied
 * through, a source id names another organization's row on this instance.
 */
final class JsonIdPaths
{
    /**
     * `$value` with every id at `$path` passed through `$map` (old id => new id
     * or null).
     *
     * @param  Closure(int): ?int  $map
     */
    public static function remap(mixed $value, string $path, Closure $map): mixed
    {
        return self::remapSegments($value, $path === '' ? [] : explode('.', $path), $map);
    }

    /**
     * Every id at `$path` in `$value`.
     *
     * @return list<int>
     */
    public static function ids(mixed $value, string $path): array
    {
        $ids = [];

        self::remap($value, $path, function (int $id) use (&$ids): int {
            $ids[] = $id;

            return $id;
        });

        return $ids;
    }

    /**
     * @param  list<string>  $segments
     * @param  Closure(int): ?int  $map
     */
    private static function remapSegments(mixed $value, array $segments, Closure $map): mixed
    {
        if ($segments === []) {
            $id = self::asId($value);

            return $id === null ? $value : $map($id);
        }

        if (! is_array($value)) {
            return $value;
        }

        $segment = array_shift($segments);

        if ($segment === '{key}') {
            $remapped = [];

            foreach ($value as $key => $child) {
                $id = self::asId($key);
                $newId = $id === null ? null : $map($id);

                if ($newId !== null) {
                    $remapped[$newId] = $child;
                }
            }

            return $remapped;
        }

        if ($segment === '*') {
            $isList = array_is_list($value);
            $remapped = [];

            foreach ($value as $key => $child) {
                $newChild = self::remapSegments($child, $segments, $map);

                // An element that *was* an id and no longer maps is dropped,
                // rather than left as a null hole in the list.
                if ($segments === [] && $newChild === null && $child !== null) {
                    continue;
                }

                $remapped[$key] = $newChild;
            }

            return $isList ? array_values($remapped) : $remapped;
        }

        if (array_key_exists($segment, $value)) {
            $value[$segment] = self::remapSegments($value[$segment], $segments, $map);
        }

        return $value;
    }

    private static function asId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value > 0 ? (int) $value : null;
        }

        return null;
    }
}
