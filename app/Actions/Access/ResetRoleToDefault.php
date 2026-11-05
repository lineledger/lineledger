<?php

namespace App\Actions\Access;

use App\Enums\SecurityEvent;
use App\Exceptions\Access\RoleNarrowingNeedsConfirmation;
use App\Models\Company;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Audit\SecurityLogRecorder;
use App\Support\Access\PermissionSet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Put an edited predefined role back to the catalogue's permissions (and its
 * default description). From then on it follows the catalogue again, picking
 * up new activities in later releases.
 *
 * Authorization (Roles: Modify) is the caller's job.
 */
class ResetRoleToDefault
{
    public function __construct(
        private readonly SecurityLogRecorder $recorder,
        private readonly RoleChangeEffects $effects,
    ) {}

    /**
     * @throws RoleNarrowingNeedsConfirmation
     */
    public function handle(Company $company, User $actor, Role $role, bool $confirmed = false): Role
    {
        abort_if($role->company_id !== $company->id, 404);

        if ($role->system_key === null) {
            throw ValidationException::withMessages(['role' => __('Only a predefined role has defaults to go back to.')]);
        }

        $default = PermissionSet::fromGrants($role->system_key->grants());
        $mine = $actor->permissions($company);

        if (! $mine->isOwner() && ! $default->isSubsetOf($mine)) {
            throw ValidationException::withMessages(['role' => __('You can only give a role permissions you hold yourself.')]);
        }

        $holders = $this->effects->capture($company, $role->id);
        $before = $role->permissionSet();

        if (! $confirmed && $before->lostIn($default) !== [] && $holders->isNotEmpty()) {
            throw new RoleNarrowingNeedsConfirmation($holders->map(fn (array $holder): string => $holder['user']->name)->all());
        }

        DB::transaction(function () use ($role, $actor): void {
            RolePermission::query()->withoutGlobalScopes()->where('role_id', $role->id)->delete();

            $role->forceFill([
                'customized_at' => null,
                'description' => $role->system_key->defaultDescription(),
                'updated_by_user_id' => $actor->id,
            ])->save();
        });

        $signedOut = $this->effects->apply($company, $holders);

        $this->recorder->record(SecurityEvent::RoleUpdated, $actor, metadata: [
            'company_id' => $company->id,
            'role_id' => $role->id,
            'role_name' => $role->name,
            'reset_to_default' => true,
            'lost' => array_map('strval', $before->lostIn($default)),
            'holders_signed_out' => $signedOut,
        ]);

        return $role->refresh();
    }
}
