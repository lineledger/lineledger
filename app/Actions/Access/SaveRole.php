<?php

namespace App\Actions\Access;

use App\Enums\Activity;
use App\Enums\PermissionLevel;
use App\Enums\SecurityEvent;
use App\Exceptions\Access\RoleNarrowingNeedsConfirmation;
use App\Models\Company;
use App\Models\MembershipRole;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Audit\SecurityLogRecorder;
use App\Support\Access\Grant;
use App\Support\Access\PermissionSet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Create a role or change one: its name, description and the permissions it
 * grants per activity. Editing a role changes access for everyone who holds
 * it; when that takes anything away, the save waits for $confirmed and then
 * signs those people out (RoleChangeEffects).
 *
 * Rules:
 *  - names are unique in the organization, ignoring case;
 *  - a predefined role keeps its name (its permissions can change, and
 *    ResetRoleToDefault puts them back);
 *  - only actions an activity offers are kept;
 *  - someone other than the Owner may only grant permissions they hold
 *    themselves, and may not edit a role they hold.
 *
 * Authorization (Roles: Create or Modify) is the caller's job.
 */
class SaveRole
{
    public function __construct(
        private readonly SecurityLogRecorder $recorder,
        private readonly RoleChangeEffects $effects,
    ) {}

    /**
     * @param  array<string, Grant>  $grants  activity value => grant
     *
     * @throws RoleNarrowingNeedsConfirmation when holders would lose access and $confirmed is false
     */
    public function handle(Company $company, User $actor, ?Role $role, string $name, ?string $description, array $grants, bool $confirmed = false): Role
    {
        abort_if($role !== null && $role->company_id !== $company->id, 404);

        $name = trim($name);
        $description = trim((string) $description) === '' ? null : trim((string) $description);
        $clean = self::cleanGrants($grants);
        $proposed = PermissionSet::fromGrants($clean);

        $this->validate($company, $actor, $role, $name, $proposed);

        $holders = $role !== null ? $this->effects->capture($company, $role->id) : collect();

        if (! $confirmed && $role !== null) {
            $losers = $this->effects->losers($holders, fn (User $holder): PermissionSet => $this->permissionsWith($company, $holder, $role, $proposed));

            if ($losers !== []) {
                throw new RoleNarrowingNeedsConfirmation($losers);
            }
        }

        $before = $role?->permissionSet();

        /** @var Role $saved */
        $saved = DB::transaction(function () use ($company, $actor, $role, $name, $description, $clean): Role {
            $role ??= new Role(['company_id' => $company->id, 'created_by_user_id' => $actor->id]);
            $role->forceFill([
                'company_id' => $company->id,
                'name' => $name,
                'description' => $description,
                'updated_by_user_id' => $actor->id,
                'customized_at' => $role->system_key !== null ? now() : $role->customized_at,
            ])->save();

            RolePermission::query()->withoutGlobalScopes()->where('role_id', $role->id)->delete();

            $now = now();
            $rows = [];

            foreach ($clean as $activity => $grant) {
                $rows[] = [
                    'company_id' => $company->id,
                    'role_id' => $role->id,
                    ...RolePermission::attributesFor(Activity::from($activity), $grant),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                RolePermission::query()->insert($rows);
            }

            return $role->refresh();
        });

        $signedOut = $this->effects->apply($company, $holders);

        $this->recorder->record($before === null ? SecurityEvent::RoleCreated : SecurityEvent::RoleUpdated, $actor, metadata: [
            'company_id' => $company->id,
            'role_id' => $saved->id,
            'role_name' => $saved->name,
            'lost' => $before === null ? [] : array_map('strval', $before->lostIn($saved->permissionSet())),
            'gained' => $before === null ? [] : array_map('strval', $saved->permissionSet()->lostIn($before)),
            'holders_signed_out' => $signedOut,
        ]);

        return $saved;
    }

    /**
     * Keep only real activities and the actions each one offers.
     *
     * @param  array<string, Grant>  $grants
     * @return array<string, Grant>
     */
    public static function cleanGrants(array $grants): array
    {
        $clean = [];

        foreach ($grants as $activity => $grant) {
            $activity = Activity::tryFrom((string) $activity);

            if ($activity === null || $grant->isNone()) {
                continue;
            }

            $clean[$activity->value] = $grant->level === PermissionLevel::Full
                ? Grant::full()
                : Grant::partial(...$grant->actionsFor($activity));
        }

        return array_filter($clean, fn (Grant $grant): bool => ! $grant->isNone());
    }

    private function validate(Company $company, User $actor, ?Role $role, string $name, PermissionSet $proposed): void
    {
        if ($name === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['name' => __('Give the role a name of up to 100 characters.')]);
        }

        if ($role?->system_key !== null && $name !== $role->name) {
            throw ValidationException::withMessages(['name' => __('A predefined role keeps its name. Duplicate it to make a role with a name of your own.')]);
        }

        $taken = Role::query()->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->when($role !== null, fn ($query) => $query->whereKeyNot($role->id))
            ->pluck('name')
            ->contains(fn (string $other): bool => Str::lower($other) === Str::lower($name));

        if ($taken) {
            throw ValidationException::withMessages(['name' => __('Another role already has that name.')]);
        }

        $mine = $actor->permissions($company);

        if ($mine->isOwner()) {
            return;
        }

        if ($role !== null && $actor->companyMembership($company) !== null
            && MembershipRole::query()->withoutGlobalScopes()
                ->where('company_member_id', $actor->companyMembership($company)->id)
                ->where('role_id', $role->id)
                ->exists()) {
            throw ValidationException::withMessages(['name' => __('You can’t change a role you hold. Ask the owner or someone else who manages roles.')]);
        }

        if (! $proposed->isSubsetOf($mine)) {
            throw ValidationException::withMessages(['grants' => __('You can only give a role permissions you hold yourself.')]);
        }
    }

    /**
     * What a holder could do if the role carried $proposed instead.
     */
    private function permissionsWith(Company $company, User $holder, Role $role, PermissionSet $proposed): PermissionSet
    {
        $membership = $holder->companyMembership($company);

        $others = Role::query()->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereKeyNot($role->id)
            ->whereIn('id', MembershipRole::query()->withoutGlobalScopes()
                ->where('company_member_id', $membership?->id)
                ->select('role_id'))
            ->get();

        return PermissionSet::union($proposed, ...$others->map(fn (Role $other): PermissionSet => $other->permissionSet())->all());
    }
}
