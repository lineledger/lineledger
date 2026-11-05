<?php

use App\Actions\Access\AssignMemberRoles;
use App\Actions\Access\DeleteRole;
use App\Actions\Companies\Members\CancelInvitation;
use App\Actions\Companies\Members\InviteMember;
use App\Actions\Companies\Members\RemoveMember;
use App\Enums\Activity;
use App\Enums\CompanyRole;
use App\Enums\PermissionAction;
use App\Enums\Section;
use App\Exceptions\Access\RoleNarrowingNeedsConfirmation;
use App\Livewire\Attributes\Requires;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\Role;
use App\Models\User;
use App\Rules\UniqueCompanyInvitation;
use App\Support\Security\StepUp;
use App\Support\Access\PermissionSet;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Users and roles (QuickBooks Desktop Enterprise's "Set Up Users and Roles"):
 * the User List — who belongs to the organization, which roles each holds,
 * pending invitations — and the Role List, with a link to each role's editor
 * and to the Permission Access by Roles report.
 */
new #[Title('Users and roles')] class extends Component {

    public Company $company;

    #[Url(as: 'tab')]
    public string $tab = 'users';

    public string $inviteEmail = '';

    /** @var list<int|string> */
    public array $inviteRoleIds = [];

    #[Locked]
    public ?int $editingUserId = null;

    /** @var list<int|string> */
    public array $editRoleIds = [];

    #[Locked]
    public ?int $removingUserId = null;

    #[Locked]
    public ?int $deletingRoleId = null;

    public ?int $reassignRoleId = null;

    /** @var list<string> */
    #[Locked]
    public array $affected = [];

    public function mount(Company $company): void
    {
        $this->company = $company;

        if (! in_array($this->tab, ['users', 'roles'], true) || ($this->tab === 'roles' && ! $this->can(Activity::Roles))) {
            $this->tab = 'users';
        }
    }

    /**
     * A privileged change needs a fresh step-up confirmation (StepUp); when it
     * has lapsed, send the person back through the page, which asks again.
     */
    protected function steppedUp(): bool
    {
        $user = Auth::user();

        if (StepUp::isFresh($user instanceof User ? $user : null)) {
            return true;
        }

        Flux::toast(variant: 'warning', text: __('Confirm it’s you to keep managing users and roles.'));
        $this->redirect(request()->header('Referer') ?: url()->current());

        return false;
    }

    #[Computed]
    public function permissions(): PermissionSet
    {
        return Auth::user()->permissions($this->company);
    }

    public function can(Activity $activity, PermissionAction $action = PermissionAction::View): bool
    {
        return $this->permissions->allows($activity, $action);
    }

    /**
     * Every member, the Owner first, with the roles each holds.
     *
     * @return list<array{id: int, name: string, email: string, owner: bool, you: bool, roles: list<string>, legacy: ?string}>
     */
    #[Computed]
    public function users(): array
    {
        $memberships = Membership::query()
            ->where('company_id', $this->company->id)
            ->with('user')
            ->get()
            ->filter(fn (Membership $m): bool => $m->user instanceof User);

        $assignments = MembershipRole::query()
            ->where('company_id', $this->company->id)
            ->whereIn('company_member_id', $memberships->pluck('id'))
            ->get()
            ->groupBy('company_member_id');

        $roles = $this->roles->keyBy('id');

        return $memberships
            ->map(fn (Membership $m): array => [
                'id' => (int) $m->user_id,
                'name' => $m->user->name,
                'email' => $m->user->email,
                'owner' => $m->role === CompanyRole::Owner,
                'you' => (int) $m->user_id === (int) Auth::id(),
                'roles' => ($assignments[$m->id] ?? collect())
                    ->map(fn (MembershipRole $a): ?string => $roles->get($a->role_id)?->displayName())
                    ->filter()->sort()->values()->all(),
                'legacy' => $m->role !== CompanyRole::Owner && ! isset($assignments[$m->id]) ? $m->role->label() : null,
            ])
            ->sortBy(fn (array $u): string => ($u['owner'] ? '0' : '1').mb_strtolower($u['name']))
            ->values()
            ->all();
    }

    /**
     * Invitations not yet accepted, with the roles each grants.
     *
     * @return list<array{code: string, email: string, roles: list<string>, expired: bool, expires: string}>
     */
    #[Computed]
    public function invitations(): array
    {
        $roles = $this->roles->keyBy('id');

        return CompanyInvitation::query()
            ->where('company_id', $this->company->id)
            ->whereNull('accepted_at')
            ->orderBy('email')
            ->get()
            ->map(fn (CompanyInvitation $i): array => [
                'code' => $i->code,
                'email' => $i->email,
                'roles' => DB::table('company_invitation_roles')->where('company_invitation_id', $i->id)->pluck('role_id')
                    ->map(fn ($id): ?string => $roles->get((int) $id)?->displayName())->filter()->sort()->values()->all(),
                'expired' => $i->isExpired(),
                'expires' => (string) $i->expires_at?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * The organization's roles, predefined ones first.
     *
     * @return Collection<int, Role>
     */
    #[Computed]
    public function roles(): Collection
    {
        return Role::query()
            ->with('permissions')
            ->withCount('memberships')
            ->get()
            ->sortBy(fn (Role $role): string => ($role->system_key !== null ? '0' : '1').mb_strtolower($role->displayName()))
            ->values();
    }

    /**
     * QuickBooks' asterisk: the role can open every working activity,
     * payroll included.
     */
    public function viewsEverything(Role $role): bool
    {
        $set = $role->permissionSet();

        foreach (Activity::cases() as $activity) {
            if (! $activity->isAdministrative() && ! $activity->isReserved() && ! $set->canView($activity)) {
                return false;
            }
        }

        return true;
    }

    public function openInvite(): void
    {
        $this->resetErrorBag();
        $this->inviteEmail = '';
        $this->inviteRoleIds = [];
        Flux::modal('invite-user')->show();
    }

    #[Requires(Activity::Invitations, PermissionAction::Create)]
    public function invite(InviteMember $invite): void
    {
        if (! $this->steppedUp()) {
            return;
        }

        $this->validate([
            'inviteEmail' => ['required', 'string', 'email', 'max:255', new UniqueCompanyInvitation($this->company)],
            'inviteRoleIds' => ['required', 'array', 'min:1'],
        ], [], ['inviteEmail' => __('email address'), 'inviteRoleIds' => __('roles')]);

        $invite->handle($this->company, Auth::user(), $this->inviteEmail, CompanyRole::Custom, null, array_map('intval', $this->inviteRoleIds));

        Flux::modal('invite-user')->close();
        Flux::toast(variant: 'success', text: __('Invitation sent.'));
        unset($this->invitations);
    }

    #[Requires(Activity::Invitations, PermissionAction::Delete)]
    public function cancelInvitation(string $code, CancelInvitation $cancel): void
    {
        if (! $this->steppedUp()) {
            return;
        }

        $invitation = CompanyInvitation::query()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
        $cancel->handle($this->company, Auth::user(), $invitation);

        Flux::toast(variant: 'success', text: __('Invitation cancelled.'));
        unset($this->invitations);
    }

    public function editRoles(int $userId): void
    {
        $membership = Membership::query()->where('company_id', $this->company->id)->where('user_id', $userId)->firstOrFail();
        abort_if($membership->role === CompanyRole::Owner, 403);

        $this->editingUserId = $userId;
        $roleIds = MembershipRole::query()
            ->where('company_id', $this->company->id)
            ->where('company_member_id', $membership->id)
            ->pluck('role_id');
        $this->editRoleIds = $roleIds->map(fn ($id): int => (int) $id)->all();
        $this->resetErrorBag();
        Flux::modal('edit-user-roles')->show();
    }

    #[Requires(Activity::Users, PermissionAction::Modify)]
    public function saveRoles(AssignMemberRoles $assign): void
    {
        if (! $this->steppedUp() || $this->editingUserId === null) {
            return;
        }

        $member = User::query()->findOrFail($this->editingUserId);
        $assign->handle($this->company, Auth::user(), $member, array_map('intval', $this->editRoleIds));

        Flux::modal('edit-user-roles')->close();
        Flux::toast(variant: 'success', text: __('Roles updated.'));
        unset($this->users);
    }

    /**
     * The combined access the roles being edited would give, area by area.
     *
     * @return list<array{label: string, level: string}>
     */
    #[Computed]
    public function editSummary(): array
    {
        $roles = $this->roles->whereIn('id', array_map('intval', $this->editRoleIds));
        $set = PermissionSet::union(...$roles->map(fn (Role $role): PermissionSet => $role->permissionSet())->all());

        $summary = [];

        foreach (Section::cases() as $area) {
            $activities = Activity::forArea($area);
            $full = array_filter($activities, fn (Activity $a): bool => $set->grant($a)->effectiveMask($a) === $a->fullMask());
            $any = array_filter($activities, fn (Activity $a): bool => $set->hasAnyIn($a));

            if ($any !== []) {
                $summary[] = ['label' => $area->label(), 'level' => count($full) === count($activities) ? __('Full') : __('Partial')];
            }
        }

        return $summary;
    }

    public function confirmRemove(int $userId): void
    {
        $this->removingUserId = $userId;
        Flux::modal('remove-user')->show();
    }

    #[Requires(Activity::Users, PermissionAction::Delete)]
    public function removeUser(RemoveMember $remove): void
    {
        if (! $this->steppedUp() || $this->removingUserId === null) {
            return;
        }

        $remove->handle($this->company, Auth::user(), User::query()->findOrFail($this->removingUserId));

        $this->removingUserId = null;
        Flux::modal('remove-user')->close();
        Flux::toast(variant: 'success', text: __('User removed.'));
        unset($this->users);
    }

    public function confirmDeleteRole(int $roleId): void
    {
        $this->deletingRoleId = $roleId;
        $this->reassignRoleId = null;
        $this->affected = [];
        $this->resetErrorBag();
        Flux::modal('delete-role')->show();
    }

    #[Requires(Activity::Roles, PermissionAction::Delete)]
    public function deleteRole(DeleteRole $delete, bool $confirmed = false): void
    {
        if (! $this->steppedUp() || $this->deletingRoleId === null) {
            return;
        }

        $role = Role::query()->findOrFail($this->deletingRoleId);
        $reassign = $this->reassignRoleId ? Role::query()->find($this->reassignRoleId) : null;

        try {
            $delete->handle($this->company, Auth::user(), $role, $reassign, $confirmed);
        } catch (RoleNarrowingNeedsConfirmation $e) {
            $this->affected = $e->affected;

            return;
        }

        $this->deletingRoleId = null;
        $this->affected = [];
        Flux::modal('delete-role')->close();
        Flux::toast(variant: 'success', text: __('Role deleted.'));
        unset($this->roles, $this->users);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Users and roles')" :subheading="__('Who belongs to this organization, and what each role lets them do.')" contentClass="max-w-4xl">
        <div class="mb-6 inline-flex rounded-lg border border-border p-1" role="tablist">
            <button type="button" role="tab" wire:click="$set('tab', 'users')" aria-selected="{{ $tab === 'users' ? 'true' : 'false' }}"
                @class(['rounded-md px-3 py-1.5 text-sm', 'bg-muted font-medium' => $tab === 'users']) data-test="tab-users">{{ __('Users') }}</button>
            @if ($this->can(Activity::Roles))
                <button type="button" role="tab" wire:click="$set('tab', 'roles')" aria-selected="{{ $tab === 'roles' ? 'true' : 'false' }}"
                    @class(['rounded-md px-3 py-1.5 text-sm', 'bg-muted font-medium' => $tab === 'roles']) data-test="tab-roles">{{ __('Roles') }}</button>
            @endif
        </div>

        @if ($tab === 'users')
            <div class="mb-4 flex items-center justify-between gap-4">
                <flux:subheading>{{ __('Each user holds one or more roles; together they decide what the user can do.') }}</flux:subheading>
                @if ($this->can(Activity::Invitations, PermissionAction::Create))
                    <flux:button variant="primary" icon="user-plus" wire:click="openInvite" data-test="invite-user">{{ __('Invite user') }}</flux:button>
                @endif
            </div>

            <flux:table data-test="user-list">
                <flux:table.columns>
                    <flux:table.column>{{ __('User') }}</flux:table.column>
                    <flux:table.column>{{ __('Roles') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->users as $user)
                        <flux:table.row :key="'user-'.$user['id']">
                            <flux:table.cell>
                                <div class="font-medium">{{ $user['name'] }} @if ($user['you'])<flux:badge size="sm" class="ms-1">{{ __('You') }}</flux:badge>@endif</div>
                                <div class="text-sm text-muted-foreground">{{ $user['email'] }}</div>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($user['owner'])
                                    <flux:badge color="amber">{{ __('Owner · Full access') }}</flux:badge>
                                @elseif ($user['legacy'] !== null)
                                    <flux:badge>{{ $user['legacy'] }}</flux:badge>
                                @else
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($user['roles'] as $roleName)
                                            <flux:badge size="sm">{{ $roleName }}</flux:badge>
                                        @endforeach
                                    </div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-end">
                                @unless ($user['owner'])
                                    @if ($this->can(Activity::Users, PermissionAction::Modify) && (! $user['you'] || $this->permissions->isOwner()))
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editRoles({{ $user['id'] }})" data-test="edit-user-roles">{{ __('Roles') }}</flux:button>
                                    @endif
                                    @if ($this->can(Activity::Users, PermissionAction::Delete))
                                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="confirmRemove({{ $user['id'] }})" :aria-label="__('Remove user')" data-test="remove-user" />
                                    @endif
                                @endunless
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            @if ($this->invitations !== [] && $this->can(Activity::Invitations))
                <flux:heading class="mt-8 mb-2">{{ __('Pending invitations') }}</flux:heading>
                <flux:table data-test="invitation-list">
                    <flux:table.rows>
                        @foreach ($this->invitations as $invitation)
                            <flux:table.row :key="'invitation-'.$invitation['code']">
                                <flux:table.cell>{{ $invitation['email'] }}</flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($invitation['roles'] as $roleName)
                                            <flux:badge size="sm">{{ $roleName }}</flux:badge>
                                        @endforeach
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell class="text-sm text-muted-foreground">
                                    {{ $invitation['expired'] ? __('Expired') : __('Expires :when', ['when' => $invitation['expires']]) }}
                                </flux:table.cell>
                                <flux:table.cell class="text-end">
                                    @if ($this->can(Activity::Invitations, PermissionAction::Delete))
                                        <flux:button size="sm" variant="ghost" wire:click="cancelInvitation('{{ $invitation['code'] }}')" wire:confirm="{{ __('Cancel this invitation?') }}" data-test="cancel-invitation">{{ __('Cancel') }}</flux:button>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        @else
            <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
                <flux:subheading>{{ __('* These roles can open every area, payroll included.') }}</flux:subheading>
                <div class="flex gap-2">
                    <flux:button :href="route('reports.role-permissions', ['company' => $company->slug])" wire:navigate icon="table-cells">{{ __('View permissions') }}</flux:button>
                    @if ($this->can(Activity::Roles, PermissionAction::Create))
                        <flux:button variant="primary" icon="plus" :href="route('settings.roles.create', ['company' => $company->slug])" wire:navigate data-test="new-role">{{ __('New role') }}</flux:button>
                    @endif
                </div>
            </div>

            <flux:table data-test="role-list">
                <flux:table.columns>
                    <flux:table.column>{{ __('Role') }}</flux:table.column>
                    <flux:table.column>{{ __('Users') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->roles as $role)
                        <flux:table.row :key="'role-'.$role->id">
                            <flux:table.cell class="max-w-md">
                                <div class="font-medium">
                                    {{ $role->displayName() }}{{ $this->viewsEverything($role) ? ' *' : '' }}
                                    @if ($role->system_key !== null)
                                        <flux:badge size="sm" class="ms-1">{{ $role->followsCatalogue() ? __('Predefined') : __('Predefined, edited') }}</flux:badge>
                                    @endif
                                </div>
                                <div class="text-sm whitespace-normal text-muted-foreground">{{ $role->displayDescription() }}</div>
                            </flux:table.cell>
                            <flux:table.cell>{{ $role->memberships_count }}</flux:table.cell>
                            <flux:table.cell class="text-end whitespace-nowrap">
                                @if ($this->can(Activity::Roles, PermissionAction::Modify))
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" :href="route('settings.roles.edit', ['company' => $company->slug, 'role' => $role->id])" wire:navigate data-test="edit-role">{{ __('Edit') }}</flux:button>
                                @endif
                                @if ($this->can(Activity::Roles, PermissionAction::Create))
                                    <flux:button size="sm" variant="ghost" icon="document-duplicate" :href="route('settings.roles.create', ['company' => $company->slug, 'from' => $role->id])" wire:navigate data-test="duplicate-role">{{ __('Duplicate') }}</flux:button>
                                @endif
                                @if ($role->system_key === null && $this->can(Activity::Roles, PermissionAction::Delete))
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDeleteRole({{ $role->id }})" :aria-label="__('Delete role')" data-test="delete-role" />
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </x-pages::settings.layout>

    <flux:modal name="invite-user" class="max-w-lg">
        <form wire:submit="invite" class="space-y-6">
            <flux:heading size="lg">{{ __('Invite a user') }}</flux:heading>
            <flux:input wire:model="inviteEmail" type="email" :label="__('Email address')" required data-test="invite-email" />
            <flux:checkbox.group wire:model="inviteRoleIds" :label="__('Roles')" :description="__('Choose one or more. The invitation lasts three days.')">
                @foreach ($this->roles as $role)
                    <flux:checkbox :value="$role->id" :label="$role->displayName()" :description="$role->displayDescription()" />
                @endforeach
            </flux:checkbox.group>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="invite-submit">{{ __('Send invitation') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="edit-user-roles" class="max-w-lg">
        <form wire:submit="saveRoles" class="space-y-6">
            <flux:heading size="lg">{{ __('Roles') }}</flux:heading>
            <flux:checkbox.group wire:model.live="editRoleIds" :label="__('Roles this user holds')" :description="__('Combined roles: the most permissive wins. Taking access away signs the user out.')">
                @foreach ($this->roles as $role)
                    <flux:checkbox :value="$role->id" :label="$role->displayName()" />
                @endforeach
            </flux:checkbox.group>
            @if ($this->editSummary !== [])
                <div class="rounded-lg border border-border p-3 text-sm" data-test="role-summary">
                    <div class="mb-1 font-medium">{{ __('What they can open') }}</div>
                    <ul class="grid grid-cols-2 gap-x-4">
                        @foreach ($this->editSummary as $line)
                            <li>{{ $line['label'] }} — {{ $line['level'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="save-user-roles">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="remove-user" class="max-w-md">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Remove this user?') }}</flux:heading>
            <flux:text>{{ __('They lose access to this organization at once: they are signed out, and the API keys they made here stop working.') }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="removeUser" data-test="remove-user-confirm">{{ __('Remove') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="delete-role" class="max-w-md">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Delete this role?') }}</flux:heading>
            <flux:select wire:model="reassignRoleId" :label="__('Move people who hold only this role to')" :placeholder="__('Choose a role…')">
                @foreach ($this->roles->where('id', '!=', $deletingRoleId) as $role)
                    <flux:select.option :value="$role->id">{{ $role->displayName() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="reassign" />
            <flux:error name="role" />
            @if ($affected !== [])
                <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('These people lose access and will be signed out: :names', ['names' => implode(', ', $affected)])" />
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                @if ($affected !== [])
                    <flux:button variant="danger" wire:click="deleteRole(true)" data-test="delete-role-confirm">{{ __('Delete and sign them out') }}</flux:button>
                @else
                    <flux:button variant="danger" wire:click="deleteRole" data-test="delete-role-submit">{{ __('Delete role') }}</flux:button>
                @endif
            </div>
        </div>
    </flux:modal>
</section>
