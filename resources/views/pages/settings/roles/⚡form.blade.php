<?php

use App\Actions\Access\ResetRoleToDefault;
use App\Actions\Access\SaveRole;
use App\Enums\Activity;
use App\Enums\PermissionAction;
use App\Enums\PermissionLevel;
use App\Enums\Section;
use App\Exceptions\Access\RoleNarrowingNeedsConfirmation;
use App\Livewire\Attributes\Requires;
use App\Livewire\Attributes\RequiresSave;
use App\Livewire\Concerns\GuardsEditLockedForm;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\Security\StepUp;
use App\Support\Access\Grant;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The role editor (QuickBooks Desktop Enterprise's New / Edit Role): name,
 * description, and for every area and activity None, Full or Partial with the
 * actions ticked. Editing a predefined role changes it for everyone who holds
 * it; taking access away asks first, then signs those people out.
 */
new #[Title('Role')] class extends Component {
    use GuardsEditLockedForm;

    public Company $company;

    public ?Role $role = null;

    public string $name = '';

    public string $description = '';

    /** @var array<string, string> activity value => none|partial|full */
    public array $levels = [];

    /** @var array<string, array<string, bool>> activity value => action value => ticked */
    public array $ticks = [];

    /** @var list<string> people who would lose access, awaiting confirmation */
    #[Locked]
    public array $affected = [];

    #[Locked]
    public string $pendingAction = '';

    protected function editLockRecord(): ?Model
    {
        return $this->role;
    }

    public function mount(Company $company, ?Role $role = null): void
    {
        $this->company = $company;
        $this->role = $role;

        $source = $role;

        if ($role === null && ($from = request()->integer('from')) > 0) {
            $source = Role::query()->find($from);
            $this->name = $source !== null ? __('Copy of :name', ['name' => $source->displayName()]) : '';
            $this->description = (string) $source?->displayDescription();
        } elseif ($role !== null) {
            $this->name = $role->name;
            $this->description = (string) $role->description;
        }

        $this->loadGrants($source?->grants() ?? []);
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

    /**
     * Load grants into the editor's state.
     *
     * @param  array<string, Grant>  $grants
     */
    private function loadGrants(array $grants): void
    {
        foreach (Activity::cases() as $activity) {
            $grant = $grants[$activity->value] ?? Grant::none();
            $this->levels[$activity->value] = $grant->level->value;
            $this->ticks[$activity->value] = [];

            foreach ($activity->actions() as $action) {
                $this->ticks[$activity->value][$action->value] = $grant->allows($activity, $action);
            }
        }
    }

    /**
     * Set every activity in an area at once.
     */
    public function setArea(string $area, string $level): void
    {
        $area = Section::tryFrom($area);
        $level = PermissionLevel::tryFrom($level);

        if ($area === null || $level === null) {
            return;
        }

        foreach (Activity::forArea($area) as $activity) {
            $this->levels[$activity->value] = $level->value;

            foreach ($activity->actions() as $action) {
                $this->ticks[$activity->value][$action->value] = $level === PermissionLevel::Full;
            }
        }
    }

    /**
     * The editor's state as grants.
     *
     * @return array<string, Grant>
     */
    private function grants(): array
    {
        $grants = [];

        foreach (Activity::cases() as $activity) {
            $level = PermissionLevel::tryFrom($this->levels[$activity->value] ?? 'none') ?? PermissionLevel::None;

            $grants[$activity->value] = match ($level) {
                PermissionLevel::None => Grant::none(),
                PermissionLevel::Full => Grant::full(),
                PermissionLevel::Partial => Grant::partial(...array_values(array_filter(
                    $activity->actions(),
                    fn (PermissionAction $action): bool => (bool) ($this->ticks[$activity->value][$action->value] ?? false),
                ))),
            };
        }

        return $grants;
    }

    /**
     * None, Partial or Full for a whole area, as the tree shows it.
     */
    public function areaLevel(Section $area): PermissionLevel
    {
        $levels = array_map(fn (Activity $a): string => $this->levels[$a->value] ?? 'none', Activity::forArea($area));

        return match (true) {
            array_unique($levels) === ['none'] => PermissionLevel::None,
            array_unique($levels) === ['full'] => PermissionLevel::Full,
            default => PermissionLevel::Partial,
        };
    }

    #[RequiresSave('role', Activity::Roles)]
    public function save(SaveRole $saveRole, bool $confirmed = false): void
    {
        if (! $this->steppedUp()) {
            return;
        }

        try {
            $saved = $saveRole->handle($this->company, Auth::user(), $this->role, $this->name, $this->description, $this->grants(), $confirmed);
        } catch (RoleNarrowingNeedsConfirmation $e) {
            $this->affected = $e->affected;
            $this->pendingAction = 'save';
            Flux::modal('confirm-narrowing')->show();

            return;
        }

        $this->affected = [];
        Flux::modal('confirm-narrowing')->close();
        Flux::toast(variant: 'success', text: __('Role saved.'));

        $this->redirectRoute('settings.users-and-roles', ['company' => $this->company->slug, 'tab' => 'roles'], navigate: true);
    }

    #[Requires(Activity::Roles, PermissionAction::Modify)]
    public function resetToDefault(ResetRoleToDefault $reset, bool $confirmed = false): void
    {
        if (! $this->steppedUp() || $this->role === null) {
            return;
        }

        try {
            $role = $reset->handle($this->company, Auth::user(), $this->role, $confirmed);
        } catch (RoleNarrowingNeedsConfirmation $e) {
            $this->affected = $e->affected;
            $this->pendingAction = 'reset';
            Flux::modal('confirm-narrowing')->show();

            return;
        }

        $this->role = $role;
        $this->description = (string) $role->description;
        $this->loadGrants($role->grants());
        $this->affected = [];
        Flux::modal('confirm-narrowing')->close();
        Flux::toast(variant: 'success', text: __('Role reset to its default permissions.'));
    }

    #[Requires(Activity::Roles, [PermissionAction::Create, PermissionAction::Modify])]
    public function confirmPending(SaveRole $saveRole, ResetRoleToDefault $reset): void
    {
        $this->pendingAction === 'reset'
            ? $this->resetToDefault($reset, confirmed: true)
            : $this->save($saveRole, confirmed: true);
    }
}; ?>

<section class="w-full">
    @if ($editLockBlocked)
        <x-edit-lock.blocked :lock="$this->editLockView" />
    @else
        <x-edit-lock.status :lock="$this->editLockView" />

        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">{{ $role ? $role->displayName() : __('New role') }}</flux:heading>
                <flux:subheading>{{ __('Choose what this role lets people do in each area: None, Full, or Partial with the actions ticked.') }}</flux:subheading>
            </div>
            <flux:button variant="ghost" icon="arrow-left" :href="route('settings.users-and-roles', ['company' => $company->slug, 'tab' => 'roles'])" wire:navigate>{{ __('Back to roles') }}</flux:button>
        </div>

        @if ($role?->system_key !== null && $role->memberships()->count() > 0)
            <flux:callout class="mb-6" icon="information-circle" :heading="__('Changes apply to everyone who holds this role (:count).', ['count' => $role->memberships()->count()])" />
        @endif

        <form wire:submit="save" class="space-y-6">
            <div class="grid gap-4 md:grid-cols-2">
                <flux:input wire:model="name" :label="__('Role name')" :disabled="$role?->system_key !== null" maxlength="100" data-test="role-name" />
                <flux:textarea wire:model="description" :label="__('Description')" rows="2" maxlength="500" />
            </div>
            <flux:error name="name" />
            <flux:error name="grants" />

            <div class="space-y-3" data-test="role-access">
                <flux:heading>{{ __('Role access') }}</flux:heading>
                @foreach (Section::cases() as $area)
                    @php($areaLevel = $this->areaLevel($area))
                    <details class="rounded-lg border border-border" wire:key="area-{{ $area->value }}" @if ($areaLevel !== PermissionLevel::None) open @endif>
                        <summary class="flex cursor-pointer items-center justify-between gap-3 px-4 py-2">
                            <span class="font-medium">{{ $area->label() }}</span>
                            <flux:badge size="sm" :color="match ($areaLevel) { PermissionLevel::Full => 'green', PermissionLevel::Partial => 'amber', default => 'zinc' }" data-test="area-level-{{ $area->value }}">{{ $areaLevel->label() }}</flux:badge>
                        </summary>
                        <div class="overflow-x-auto px-4 pb-4">
                            <div class="mb-2 flex gap-2">
                                <flux:button size="xs" variant="ghost" wire:click="setArea('{{ $area->value }}', 'none')">{{ __('None for all') }}</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="setArea('{{ $area->value }}', 'full')">{{ __('Full for all') }}</flux:button>
                            </div>
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-muted-foreground">
                                        <th class="py-1 pe-3 font-medium">{{ __('Activity') }}</th>
                                        <th class="py-1 pe-3 font-medium">{{ __('Access') }}</th>
                                        @foreach (PermissionAction::cases() as $action)
                                            <th class="px-2 py-1 text-center font-medium">{{ $action->label() }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @foreach (Activity::forArea($area) as $activity)
                                        @php($level = $levels[$activity->value] ?? 'none')
                                        <tr wire:key="activity-{{ $activity->value }}">
                                            <td class="py-1.5 pe-3">
                                                {{ $activity->label() }}
                                                @if ($activity->isReserved())
                                                    <flux:badge size="sm" class="ms-1">{{ __('Owner-only by default') }}</flux:badge>
                                                @endif
                                            </td>
                                            <td class="py-1.5 pe-3">
                                                <select wire:model.live="levels.{{ $activity->value }}" class="rounded-md border border-input bg-background px-2 py-1 text-sm" aria-label="{{ __('Access to :activity', ['activity' => $activity->label()]) }}" data-test="level-{{ $activity->value }}">
                                                    @foreach (PermissionLevel::cases() as $option)
                                                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            @foreach (PermissionAction::cases() as $action)
                                                <td class="px-2 py-1.5 text-center">
                                                    @if ($activity->supports($action))
                                                        <input type="checkbox"
                                                            wire:model="ticks.{{ $activity->value }}.{{ $action->value }}"
                                                            @disabled($level !== 'partial')
                                                            @if ($level === 'full') checked @endif
                                                            aria-label="{{ $action->label() }} — {{ $activity->label() }}"
                                                            data-test="tick-{{ $activity->value }}-{{ $action->value }}"
                                                            class="size-4 rounded border-input" />
                                                    @else
                                                        <span class="text-muted-foreground" title="{{ __('Not applicable to :activity', ['activity' => $activity->label()]) }}">—</span>
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                @endforeach
            </div>

            <div class="sticky bottom-0 flex justify-end gap-2 border-t border-border bg-background py-3">
                @if ($role?->system_key !== null && ! $role->followsCatalogue())
                    <flux:button variant="ghost" wire:click="resetToDefault" data-test="reset-role">{{ __('Reset to default') }}</flux:button>
                @endif
                <flux:button :href="route('settings.users-and-roles', ['company' => $company->slug, 'tab' => 'roles'])" wire:navigate>{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" type="submit" data-test="save-role">{{ __('Save role') }}</flux:button>
            </div>
        </form>

        <flux:modal name="confirm-narrowing" class="max-w-md">
            <div class="space-y-6">
                <flux:heading size="lg">{{ __('Take access away?') }}</flux:heading>
                <flux:text>{{ __('These people will lose some access and be signed out, and the API keys they made here will stop working: :names', ['names' => implode(', ', $affected)]) }}</flux:text>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" wire:click="confirmPending" data-test="confirm-narrowing">{{ __('Save and sign them out') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</section>
