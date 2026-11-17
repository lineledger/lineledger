<?php

namespace App\Services\Migration;

use App\Models\Account;

/**
 * Parses a QuickBooks account label. QuickBooks joins sub-accounts with a colon
 * ("Pass Through Expense:Hosting:Email Hosting") and, when account numbers are on,
 * prefixes each segment with its number and a middot ("6000 · Pass Through
 * Expense:6010 · Hosting"). The Chart of Accounts importer and the general-ledger
 * replay's AccountResolver both read labels through here, so they agree on what an
 * account's path is.
 */
final class QuickBooksAccountLabel
{
    /**
     * Every segment of the label, root first.
     *
     * "1000 · Bank:1010 · Chequing" → [[code 1000, name Bank], [code 1010, name Chequing]]
     *
     * @return list<array{code: ?string, name: string}>
     */
    public static function segments(string $label): array
    {
        return array_map(
            self::segment(...),
            explode(':', trim($label)),
        );
    }

    /**
     * The account itself: the last segment, split into its number and name.
     *
     * "1000 · Bank:1010 · Chequing" → code "1010", name "Chequing"
     * "Due to Owner"                → code null,   name "Due to Owner"
     *
     * @return array{code: ?string, name: string}
     */
    public static function leaf(string $label): array
    {
        $segments = self::segments($label);

        return $segments[array_key_last($segments)];
    }

    /**
     * The segment names joined with a colon, without account numbers.
     *
     * "6000 · Pass Through Expense:6010 · Hosting" → "Pass Through Expense:Hosting"
     */
    public static function displayPath(string $label): string
    {
        return self::join(self::segments($label));
    }

    /**
     * The parent's display path, or null for a top-level account.
     */
    public static function parentDisplayPath(string $label): ?string
    {
        $segments = self::segments($label);

        if (count($segments) < 2) {
            return null;
        }

        return self::join(array_slice($segments, 0, -1));
    }

    /**
     * The case-insensitive key two labels for the same account share, with or
     * without account numbers.
     */
    public static function pathKey(string $label): string
    {
        return self::keyFor(self::displayPath($label));
    }

    /**
     * The key for a path that is already joined from account names.
     */
    public static function keyFor(string $displayPath): string
    {
        return mb_strtolower($displayPath);
    }

    /**
     * Each account's path in the chart: its name and its ancestors', root first,
     * joined with a colon, as QuickBooks writes it. Stops at a parent that isn't
     * in the list and at a loop.
     *
     * @param  iterable<Account>  $accounts  with id, parent_id and name loaded
     * @return array<int, string> account id => display path
     */
    public static function pathsOf(iterable $accounts): array
    {
        $byId = [];

        foreach ($accounts as $account) {
            $byId[$account->id] = $account;
        }

        $paths = [];

        foreach ($byId as $id => $account) {
            $names = [$account->name];
            $seen = [$id => true];
            $parentId = $account->parent_id;

            while ($parentId !== null && ! isset($seen[$parentId]) && isset($byId[$parentId])) {
                $seen[$parentId] = true;
                array_unshift($names, $byId[$parentId]->name);
                $parentId = $byId[$parentId]->parent_id;
            }

            $paths[$id] = implode(':', $names);
        }

        return $paths;
    }

    /**
     * @return array{code: ?string, name: string}
     */
    private static function segment(string $segment): array
    {
        $segment = trim($segment);

        // QuickBooks separates the account number from the name with a middot.
        if (str_contains($segment, '·')) {
            [$code, $name] = array_pad(explode('·', $segment, 2), 2, '');
            $code = trim($code);
            $name = trim($name);

            if ($code !== '' && $name !== '') {
                return ['code' => $code, 'name' => $name];
            }
        }

        return ['code' => null, 'name' => $segment];
    }

    /**
     * @param  list<array{code: ?string, name: string}>  $segments
     */
    private static function join(array $segments): string
    {
        return implode(':', array_map(fn (array $s): string => $s['name'], $segments));
    }
}
