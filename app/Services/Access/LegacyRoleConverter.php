<?php

namespace App\Services\Access;

use App\Enums\Activity;
use App\Enums\CompanyRole;
use App\Enums\PredefinedRole;
use App\Enums\Section;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\Membership;
use App\Models\RolePermission;
use App\Support\Access\LegacyRoleGrants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns the original role + section ticks into role assignments, without
 * changing anyone's access:
 *
 *  - Admin → Full Access, Accountant → Accountant (predefined roles with
 *    exactly those permissions);
 *  - Custom → a role generated per distinct set of sections ("Custom:
 *    Customers, Reports"), shared by every member with the same ticks;
 *  - Owner → no role (the Owner has everything).
 *
 * Writes go straight to the tables with the company's id, so it is safe from
 * the console, a restore, or inside another company's request.
 */
class LegacyRoleConverter
{
    public function __construct(private readonly PredefinedRoleSeeder $seeder) {}

    /**
     * Give every membership and invitation that holds no role the roles its
     * original role converts to.
     *
     * @return array{members: int, invitations: int, roles_created: int}
     */
    public function convertCompany(Company $company, bool $dryRun = false): array
    {
        $companyId = (int) $company->getKey();
        $result = ['members' => 0, 'invitations' => 0, 'roles_created' => 0];
        $planned = [];

        $memberships = Membership::query()
            ->where('company_id', $companyId)
            ->whereNotIn('id', DB::table('company_member_roles')->where('company_id', $companyId)->select('company_member_id'))
            ->orderBy('id')
            ->get();

        foreach ($memberships as $membership) {
            if ($membership->role === CompanyRole::Owner) {
                continue;
            }

            $result['members']++;

            if ($dryRun) {
                $planned[self::legacyKey($membership->role, $membership->sections)] = true;

                continue;
            }

            $result['roles_created'] += $this->assignLegacy($membership);
        }

        $invitations = CompanyInvitation::query()
            ->where('company_id', $companyId)
            ->whereNotIn('id', DB::table('company_invitation_roles')->where('company_id', $companyId)->select('company_invitation_id'))
            ->orderBy('id')
            ->get();

        foreach ($invitations as $invitation) {
            $result['invitations']++;

            if ($dryRun) {
                $planned[self::legacyKey($invitation->role, $invitation->sections)] = true;

                continue;
            }

            $result['roles_created'] += $this->assignLegacyToInvitation($invitation);
        }

        if ($dryRun) {
            $result['roles_created'] = count(array_filter(
                array_keys($planned),
                fn (string $key): bool => str_starts_with($key, 'custom:')
                    && ! DB::table('roles')->where('company_id', $companyId)->where('legacy_key', $key)->exists(),
            ));
        }

        return $result;
    }

    /**
     * Replace the membership's roles with the ones its original role
     * converts to (none for the Owner).
     *
     * @return int roles created along the way (a new Custom role)
     */
    public function assignLegacy(Membership $membership, ?int $assignedBy = null): int
    {
        $companyId = (int) $membership->company_id;
        [$roleIds, $created] = $this->rolesFor($companyId, $membership->role, $membership->sections);

        DB::table('company_member_roles')->where('company_member_id', $membership->getKey())->delete();

        $now = now();
        DB::table('company_member_roles')->insert(array_map(fn (int $roleId): array => [
            'company_id' => $companyId,
            'company_member_id' => $membership->getKey(),
            'role_id' => $roleId,
            'assigned_by_user_id' => $assignedBy,
            'created_at' => $now,
            'updated_at' => $now,
        ], $roleIds));

        app(PermissionResolver::class)->flush();

        return $created;
    }

    /**
     * @return int roles created along the way
     */
    public function assignLegacyToInvitation(CompanyInvitation $invitation): int
    {
        $companyId = (int) $invitation->company_id;
        [$roleIds, $created] = $this->rolesFor($companyId, $invitation->role, $invitation->sections);

        DB::table('company_invitation_roles')->where('company_invitation_id', $invitation->getKey())->delete();

        $now = now();
        DB::table('company_invitation_roles')->insert(array_map(fn (int $roleId): array => [
            'company_id' => $companyId,
            'company_invitation_id' => $invitation->getKey(),
            'role_id' => $roleId,
            'created_at' => $now,
            'updated_at' => $now,
        ], $roleIds));

        return $created;
    }

    /**
     * The role ids an original role converts to, creating the predefined
     * roles or a Custom role as needed.
     *
     * @param  list<string>|null  $sections
     * @return array{0: list<int>, 1: int} role ids, roles created
     */
    public function rolesFor(int $companyId, CompanyRole $role, ?array $sections): array
    {
        if ($role === CompanyRole::Owner) {
            return [[], 0];
        }

        $predefined = PredefinedRole::forLegacy($role);

        if ($predefined !== null) {
            $this->seeder->seed($companyId);

            return [[(int) DB::table('roles')->where('company_id', $companyId)->where('system_key', $predefined->value)->value('id')], 0];
        }

        $key = self::legacyKey($role, $sections);
        $existing = DB::table('roles')->where('company_id', $companyId)->where('legacy_key', $key)->value('id');

        if ($existing !== null) {
            return [[(int) $existing], 0];
        }

        return [[$this->createCustomRole($companyId, $key, self::sortedSections($sections))], 1];
    }

    /**
     * The key identifying a membership's original access, shared by every
     * member with the same role and ticks: "custom:customers,reports".
     *
     * @param  list<string>|null  $sections
     */
    public static function legacyKey(CompanyRole $role, ?array $sections): string
    {
        return $role === CompanyRole::Custom
            ? 'custom:'.implode(',', array_map(fn (Section $s): string => $s->value, self::sortedSections($sections)))
            : $role->value;
    }

    /**
     * @param  list<Section>  $sections
     */
    private function createCustomRole(int $companyId, string $key, array $sections): int
    {
        $labels = array_map(fn (Section $section): string => Str::ucfirst($section->value), $sections);
        $base = match (true) {
            $labels === [] => 'Custom: no sections',
            strlen('Custom: '.implode(', ', $labels)) > 100 => 'Custom: '.count($labels).' sections',
            default => 'Custom: '.implode(', ', $labels),
        };

        $taken = DB::table('roles')->where('company_id', $companyId)->pluck('name')
            ->map(fn (string $name): string => Str::lower($name))->all();
        $now = now();

        $roleId = (int) DB::table('roles')->insertGetId([
            'company_id' => $companyId,
            'name' => PredefinedRoleSeeder::uniqueName($base, $taken),
            'description' => $labels === []
                ? 'Created from a member who had the Custom role with no sections ticked.'
                : 'Created from the sections members had before roles: '.implode(', ', $labels).'.',
            'legacy_key' => $key,
            'catalog_version' => Activity::CATALOG_VERSION,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissions = [];

        foreach (LegacyRoleGrants::for(CompanyRole::Custom, array_map(fn (Section $s): string => $s->value, $sections))->grants() as $activity => $grant) {
            $permissions[] = [
                'company_id' => $companyId,
                'role_id' => $roleId,
                ...RolePermission::attributesFor(Activity::from($activity), $grant),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($permissions !== []) {
            DB::table('role_permissions')->insert($permissions);
        }

        return $roleId;
    }

    /**
     * Known sections, in the order the sidebar lists them.
     *
     * @param  list<string>|null  $sections
     * @return list<Section>
     */
    private static function sortedSections(?array $sections): array
    {
        return array_values(array_filter(Section::cases(), fn (Section $s): bool => in_array($s->value, $sections ?? [], true)));
    }
}
