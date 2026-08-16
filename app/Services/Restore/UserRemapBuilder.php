<?php

namespace App\Services\Restore;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the bundle's `users.json` against the target instance's `users`
 * table to produce the old → new user id map used during row transformation.
 *
 * Strategy (locked in the Phase 1 → Phase 2 contract):
 *
 *  1. Lower-case both sides and look up by email. Hits map to the matched
 *     target user's id.
 *  2. Misses fall back to `$importingUser->id`, so every old user id has a
 *     destination and no `created_by_user_id` ever ends up null after the
 *     row transformer applies the map.
 *
 * The returned `matches` list powers the UI preview ("3 of 5 users matched
 * by email; the other 2 will be attributed to you").
 */
final class UserRemapBuilder
{
    /**
     * @param  array<int, array{id:int,email:string,name:string}>  $bundleUsers  Decoded users.json payload.
     * @return array{
     *     map: array<int, int>,
     *     matches: array<int, array{old_id:int, email:string, name:string, target_user_id:int, match:'email'|'fallback'}>
     * }
     */
    public function build(array $bundleUsers, User $importingUser): array
    {
        if ($bundleUsers === []) {
            return ['map' => [], 'matches' => []];
        }

        $loweredEmails = [];
        foreach ($bundleUsers as $bundleUser) {
            $email = $bundleUser['email'] ?? null;

            if (! is_string($email) || $email === '') {
                continue;
            }

            $loweredEmails[] = strtolower($email);
        }

        $loweredEmails = array_values(array_unique($loweredEmails));

        // [lowercased email => user id]
        $lookup = [];

        if ($loweredEmails !== []) {
            $rows = User::query()
                ->whereIn(DB::raw('LOWER(email)'), $loweredEmails)
                ->get(['id', 'email']);

            foreach ($rows as $row) {
                $lookup[strtolower((string) $row->email)] = (int) $row->id;
            }
        }

        $map = [];
        $matches = [];

        foreach ($bundleUsers as $bundleUser) {
            $oldId = (int) ($bundleUser['id'] ?? 0);
            $email = (string) ($bundleUser['email'] ?? '');
            $name = (string) ($bundleUser['name'] ?? '');

            $hit = $lookup[strtolower($email)] ?? null;

            if ($hit !== null) {
                $map[$oldId] = $hit;
                $matches[] = [
                    'old_id' => $oldId,
                    'email' => $email,
                    'name' => $name,
                    'target_user_id' => $hit,
                    'match' => 'email',
                ];
            } else {
                $map[$oldId] = (int) $importingUser->id;
                $matches[] = [
                    'old_id' => $oldId,
                    'email' => $email,
                    'name' => $name,
                    'target_user_id' => (int) $importingUser->id,
                    'match' => 'fallback',
                ];
            }
        }

        return [
            'map' => $map,
            'matches' => $matches,
        ];
    }
}
