<?php

namespace App\Actions\Access;

use App\Enums\SecurityEvent;
use App\Exceptions\Access\RoleNarrowingNeedsConfirmation;
use App\Models\Company;
use App\Models\InvitationRole;
use App\Models\MembershipRole;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\SecurityLogRecorder;
use App\Support\Access\PermissionSet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delete a custom role. Everyone (and every pending invitation) holding it
 * keeps their other roles; anyone left with no role at all must be moved to
 * $reassignTo. Holders who lose access are signed out once $confirmed.
 * Predefined roles can't be deleted — reset them instead.
 *
 * Authorization (Roles: Delete) is the caller's job.
 */
class DeleteRole
{
    public function __construct(
        private readonly SecurityLogRecorder $recorder,
        private readonly RoleChangeEffects $effects,
    ) {}

    /**
     * @throws RoleNarrowingNeedsConfirmation when holders would lose access and $confirmed is false
     */
    public function handle(Company $company, User $actor, Role $role, ?Role $reassignTo = null, bool $confirmed = false): void
    {
        abort_if($role->company_id !== $company->id || ($reassignTo !== null && $reassignTo->company_id !== $company->id), 404);

        if ($role->system_key !== null) {
            throw ValidationException::withMessages(['role' => __('A predefined role can’t be deleted. Reset it to its default permissions instead.')]);
        }

        if ($reassignTo?->is($role)) {
            $reassignTo = null;
        }

        $mine = $actor->permissions($company);

        if (! $mine->isOwner() && $reassignTo !== null && ! $reassignTo->permissionSet()->isSubsetOf($mine)) {
            throw ValidationException::withMessages(['reassign' => __('You can only move people to a role whose permissions you hold yourself.')]);
        }

        $stranded = $this->stranded($company, $role);

        if ($stranded !== [] && $reassignTo === null) {
            throw ValidationException::withMessages(['reassign' => __('Choose a role for the people and invitations that hold only this one.')]);
        }

        $holders = $this->effects->capture($company, $role->id);

        if (! $confirmed) {
            $losers = $this->effects->losers($holders, function (User $holder) use ($company, $role, $reassignTo, $stranded): PermissionSet {
                $remaining = Role::query()->withoutGlobalScopes()
                    ->whereKeyNot($role->id)
                    ->whereIn('id', MembershipRole::query()->withoutGlobalScopes()
                        ->where('company_member_id', $holder->companyMembership($company)?->id)
                        ->select('role_id'))
                    ->get()
                    ->map(fn (Role $other): PermissionSet => $other->permissionSet())
                    ->all();

                if ($reassignTo !== null && in_array($holder->companyMembership($company)?->id, $stranded['members'] ?? [], true)) {
                    $remaining[] = $reassignTo->permissionSet();
                }

                return PermissionSet::union(...$remaining);
            });

            if ($losers !== []) {
                throw new RoleNarrowingNeedsConfirmation($losers);
            }
        }

        DB::transaction(function () use ($company, $actor, $role, $reassignTo, $stranded): void {
            $now = now();

            foreach ($stranded['members'] ?? [] as $memberId) {
                MembershipRole::query()->insert([
                    'company_id' => $company->id, 'company_member_id' => $memberId, 'role_id' => $reassignTo?->id,
                    'assigned_by_user_id' => $actor->id, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            foreach ($stranded['invitations'] ?? [] as $invitationId) {
                InvitationRole::query()->insert([
                    'company_id' => $company->id, 'company_invitation_id' => $invitationId, 'role_id' => $reassignTo?->id,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $role->delete();
        });

        $signedOut = $this->effects->apply($company, $holders);

        $this->recorder->record(SecurityEvent::RoleDeleted, $actor, metadata: [
            'company_id' => $company->id,
            'role_id' => $role->id,
            'role_name' => $role->name,
            'reassigned_to' => $reassignTo?->name,
            'holders_signed_out' => $signedOut,
        ]);
    }

    /**
     * Members and pending invitations that hold this role and no other.
     *
     * @return array{members?: list<int>, invitations?: list<int>}
     */
    private function stranded(Company $company, Role $role): array
    {
        $members = MembershipRole::query()->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('role_id', $role->id)
            ->pluck('company_member_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $memberId): bool => ! MembershipRole::query()->withoutGlobalScopes()
                ->where('company_member_id', $memberId)
                ->where('role_id', '!=', $role->id)
                ->exists())
            ->values()
            ->all();

        $invitations = InvitationRole::query()->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('role_id', $role->id)
            ->pluck('company_invitation_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $invitationId): bool => ! InvitationRole::query()->withoutGlobalScopes()
                ->where('company_invitation_id', $invitationId)
                ->where('role_id', '!=', $role->id)
                ->exists()
                && DB::table('company_invitations')->where('id', $invitationId)->whereNull('accepted_at')->exists())
            ->values()
            ->all();

        return array_filter(['members' => $members, 'invitations' => $invitations]);
    }
}
