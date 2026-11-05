<?php

use App\Enums\Activity;
use App\Enums\PermissionAction;
use App\Enums\PermissionLevel;
use App\Enums\Section;
use App\Livewire\Attributes\Requires;
use App\Models\Company;
use App\Models\Role;
use App\Support\Access\Grant;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * Permission Access by Roles (QuickBooks Desktop Enterprise's View
 * Permissions): every activity, area by area, against the roles chosen — what
 * each role can do there.
 */
new #[Title('Permission access by roles')] class extends Component {
    public Company $company;

    /** @var list<int|string> chosen role ids; empty means every role */
    #[Url(as: 'roles')]
    public array $roleIds = [];

    public bool $differencesOnly = false;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    /**
     * @return Collection<int, Role>
     */
    #[Computed]
    public function allRoles(): Collection
    {
        return Role::query()->with('permissions')->get()
            ->sortBy(fn (Role $role): string => ($role->system_key !== null ? '0' : '1').mb_strtolower($role->displayName()))
            ->values();
    }

    /**
     * @return Collection<int, Role>
     */
    #[Computed]
    public function roles(): Collection
    {
        $ids = array_map('intval', $this->roleIds);

        return $ids === [] ? $this->allRoles : $this->allRoles->whereIn('id', $ids)->values();
    }

    /**
     * What a grant amounts to, in words.
     */
    public function describe(Grant $grant, Activity $activity): string
    {
        return match (true) {
            $grant->isNone() => __('None'),
            $grant->level === PermissionLevel::Full || $grant->effectiveMask($activity) === $activity->fullMask() => __('Full'),
            default => __('Partial').': '.implode(', ', array_map(fn (PermissionAction $a): string => $a->label(), $grant->actionsFor($activity))),
        };
    }

    /**
     * Rows: area => [activity, cell per role].
     *
     * @return array<string, list<array{activity: Activity, cells: list<string>}>>
     */
    #[Computed]
    public function matrix(): array
    {
        $grants = $this->roles->map(fn (Role $role): array => $role->grants())->all();
        $rows = [];

        foreach (Section::cases() as $area) {
            foreach (Activity::forArea($area) as $activity) {
                $cells = array_map(fn (array $g): string => $this->describe($g[$activity->value] ?? Grant::none(), $activity), $grants);

                if ($this->differencesOnly && count(array_unique($cells)) < 2) {
                    continue;
                }

                $rows[$area->label()][] = ['activity' => $activity, 'cells' => $cells];
            }
        }

        return $rows;
    }

    #[Requires(Activity::Roles, PermissionAction::View)]
    public function exportCsv(): StreamedResponse
    {
        $roles = $this->roles;
        $matrix = $this->matrix;

        return response()->streamDownload(function () use ($roles, $matrix): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [__('Area'), __('Activity'), ...$roles->map(fn (Role $role): string => $role->displayName())->all()]);

            foreach ($matrix as $area => $rows) {
                foreach ($rows as $row) {
                    fputcsv($out, [$area, $row['activity']->label(), ...$row['cells']]);
                }
            }

            fclose($out);
        }, 'role-permissions.csv', ['Content-Type' => 'text/csv']);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Permission access by roles') }}</flux:heading>
            <flux:subheading>{{ __('What each role can do in every area. Full means every action the activity offers.') }}</flux:subheading>
        </div>
        <div class="flex gap-2 print:hidden">
            @callable($this, 'exportCsv')
            <flux:button icon="arrow-down-tray" wire:click="exportCsv" data-test="export-csv">{{ __('Export CSV') }}</flux:button>
            @endcallable
            <flux:button icon="printer" x-on:click="window.print()">{{ __('Print') }}</flux:button>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-4 print:hidden">
        <flux:checkbox.group wire:model.live="roleIds" :label="__('Roles')" class="flex flex-wrap gap-x-4">
            @foreach ($this->allRoles as $role)
                <flux:checkbox :value="$role->id" :label="$role->displayName()" />
            @endforeach
        </flux:checkbox.group>
        <flux:switch wire:model.live="differencesOnly" :label="__('Only rows where the roles differ')" />
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm" data-test="permission-matrix">
            <thead class="bg-muted">
                <tr>
                    <th class="sticky left-0 bg-muted px-3 py-2 text-left font-medium">{{ __('Activity') }}</th>
                    @foreach ($this->roles as $role)
                        <th class="px-3 py-2 text-left font-medium">{{ $role->displayName() }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($this->matrix as $area => $rows)
                    <tr class="bg-muted/40"><th colspan="{{ $this->roles->count() + 1 }}" class="px-3 py-1.5 text-left">{{ $area }}</th></tr>
                    @foreach ($rows as $row)
                        <tr class="border-t border-border">
                            <td class="sticky left-0 bg-background px-3 py-1.5">{{ $row['activity']->label() }}</td>
                            @foreach ($row['cells'] as $cell)
                                <td class="px-3 py-1.5">{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>
</section>
