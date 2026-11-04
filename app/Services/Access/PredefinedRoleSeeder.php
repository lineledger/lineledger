<?php

namespace App\Services\Access;

use App\Enums\Activity;
use App\Enums\PredefinedRole;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gives a company the predefined roles it is missing — one row each, no
 * permission rows (a predefined role reads its permissions from the catalogue
 * until someone edits it). Idempotent; inserts directly with the company's id
 * so it never depends on (or disturbs) the bound tenant.
 */
class PredefinedRoleSeeder
{
    /**
     * @return int how many roles were added
     */
    public function seed(Company|int $company, bool $dryRun = false): int
    {
        $companyId = $company instanceof Company ? (int) $company->getKey() : $company;

        $existing = DB::table('roles')->where('company_id', $companyId)->get(['system_key', 'name']);
        $keys = $existing->pluck('system_key')->filter()->all();
        $names = $existing->pluck('name')->map(fn (string $name): string => Str::lower($name))->all();

        $now = now();
        $rows = [];

        foreach (PredefinedRole::cases() as $role) {
            if (in_array($role->value, $keys, true)) {
                continue;
            }

            $name = $role->defaultName();
            $names[] = Str::lower($name = self::uniqueName($name, $names));

            $rows[] = [
                'company_id' => $companyId,
                'system_key' => $role->value,
                'name' => $name,
                'description' => $role->defaultDescription(),
                'catalog_version' => Activity::CATALOG_VERSION,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== [] && ! $dryRun) {
            DB::table('roles')->insert($rows);
            app(PermissionResolver::class)->flush();
        }

        return count($rows);
    }

    /**
     * The name, or the name with " (2)", " (3)" … when the company already has
     * a role called that (compared without case).
     *
     * @param  list<string>  $taken  lower-cased names in use
     */
    public static function uniqueName(string $name, array $taken): string
    {
        $candidate = $name;
        $n = 2;

        while (in_array(Str::lower($candidate), $taken, true)) {
            $candidate = "{$name} ({$n})";
            $n++;
        }

        return $candidate;
    }
}
