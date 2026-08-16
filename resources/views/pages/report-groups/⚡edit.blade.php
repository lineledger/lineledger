<?php

use App\Actions\Reporting\SeedReportGroupMappings;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Models\ReportGroup;
use App\Models\ReportGroupAccountMap;
use App\Models\ReportGroupLine;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit combined report group')] class extends Component {
    public ReportGroup $reportGroup;

    public string $f_name = '';

    public ?int $editingLineId = null;

    public string $f_line_name = '';

    public string $f_line_type = 'asset';

    public string $f_line_subtype = '';

    public ?int $addCompanyId = null;

    public function mount(ReportGroup $reportGroup): void
    {
        Gate::authorize('update', $reportGroup);

        $this->reportGroup = $reportGroup;
        $this->f_name = $reportGroup->name;
    }

    public function saveName(): void
    {
        $validated = $this->validate(['f_name' => ['required', 'string', 'max:255']]);
        $this->reportGroup->update(['name' => $validated['f_name']]);
        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function addCompany(SeedReportGroupMappings $seed): void
    {
        $company = $this->eligibleCompanies()->firstWhere('id', (int) $this->addCompanyId);

        if (! $company) {
            return;
        }

        abort_unless(Auth::user()->belongsToCompany($company), 403);

        if ($this->reportGroup->companies()->count() >= 10) {
            $this->addError('addCompanyId', __('A group can combine at most ten companies.'));

            return;
        }

        if ($company->currency_code !== $this->reportGroup->currency_code) {
            $this->addError('addCompanyId', __('That company uses a different currency.'));

            return;
        }

        $this->reportGroup->companies()->syncWithoutDetaching([$company->id]);
        $seed->handle($this->reportGroup);

        $this->reset('addCompanyId');
        Flux::toast(variant: 'success', text: __('Company added.'));
    }

    public function removeCompany(int $companyId): void
    {
        // Drop the company and any account maps it contributed; clean up emptied lines.
        $this->reportGroup->accountMaps()->where('company_id', $companyId)->delete();
        $this->reportGroup->companies()->detach($companyId);

        $this->reportGroup->lines()
            ->whereDoesntHave('accountMaps')
            ->delete();

        Flux::toast(variant: 'success', text: __('Company removed.'));
    }

    public function reseed(SeedReportGroupMappings $seed): void
    {
        $seed->handle($this->reportGroup);
        Flux::toast(variant: 'success', text: __('Mappings refreshed.'));
    }

    public function openNewLine(): void
    {
        $this->reset(['editingLineId', 'f_line_name', 'f_line_subtype']);
        $this->f_line_type = 'asset';
        Flux::modal('line-form')->show();
    }

    public function openEditLine(int $id): void
    {
        $line = $this->reportGroup->lines()->findOrFail($id);
        $this->editingLineId = $line->id;
        $this->f_line_name = $line->name;
        $this->f_line_type = $line->type->value;
        $this->f_line_subtype = $line->subtype?->value ?? '';
        Flux::modal('line-form')->show();
    }

    public function saveLine(): void
    {
        $validated = $this->validate([
            'f_line_name' => ['required', 'string', 'max:255'],
            'f_line_type' => ['required', \Illuminate\Validation\Rule::enum(AccountType::class)],
            'f_line_subtype' => ['nullable', \Illuminate\Validation\Rule::enum(AccountSubtype::class)],
        ]);

        $payload = [
            'name' => $validated['f_line_name'],
            'type' => $validated['f_line_type'],
            'subtype' => $validated['f_line_subtype'] ?: null,
            'is_passthrough' => false,
        ];

        if ($this->editingLineId) {
            $this->reportGroup->lines()->findOrFail($this->editingLineId)->update($payload);
        } else {
            $this->reportGroup->lines()->create([
                ...$payload,
                'sort_order' => (int) $this->reportGroup->lines()->max('sort_order') + 1,
            ]);
        }

        Flux::modal('line-form')->close();
        Flux::toast(variant: 'success', text: __('Line saved.'));
    }

    public function deleteLine(int $id): void
    {
        $line = $this->reportGroup->lines()->with('accountMaps.account')->findOrFail($id);

        // Each mapped account reverts to its own passthrough line.
        $sort = (int) $this->reportGroup->lines()->max('sort_order') + 1;

        foreach ($line->accountMaps as $map) {
            $account = $map->account;

            $passthrough = $this->reportGroup->lines()->create([
                'name' => $account?->name ?? __('Account'),
                'type' => $account?->type ?? $line->type,
                'subtype' => $account?->subtype,
                'sort_order' => $sort++,
                'is_passthrough' => true,
            ]);

            $map->update(['report_group_line_id' => $passthrough->id]);
        }

        $line->delete();
        Flux::toast(variant: 'success', text: __('Line removed; its accounts were split out.'));
    }

    public function moveAccount(int $mapId, int $lineId): void
    {
        $map = $this->reportGroup->accountMaps()->findOrFail($mapId);
        $line = $this->reportGroup->lines()->findOrFail($lineId);

        $map->update(['report_group_line_id' => $line->id]);

        $this->cleanupEmptyLines();
    }

    protected function cleanupEmptyLines(): void
    {
        $this->reportGroup->lines()->whereDoesntHave('accountMaps')->delete();
    }

    /**
     * Companies the user belongs to that aren't members yet (currency match flagged in the view).
     *
     * @return Collection<int, Company>
     */
    public function eligibleCompanies(): Collection
    {
        $memberIds = $this->reportGroup->companyIds();

        return Auth::user()->companies()
            ->whereNotIn('companies.id', $memberIds)
            ->orderByRaw('LOWER(companies.name)')
            ->get();
    }

    /**
     * @return Collection<int, Company>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->reportGroup->companies()->orderByRaw('LOWER(companies.name)')->get();
    }

    /**
     * Lines with their mapped accounts, ordered for presentation.
     *
     * @return Collection<int, ReportGroupLine>
     */
    #[Computed]
    public function lines(): Collection
    {
        return $this->reportGroup->lines()
            ->with(['accountMaps.account', 'accountMaps.company'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Combined report group') }}</flux:heading>
            <flux:subheading>{{ __('Map accounts across companies into combined lines.') }}</flux:subheading>
        </div>
        <div class="flex items-center gap-2">
            <flux:button icon="chart-bar" :href="route('report-groups.balance-sheet', $reportGroup)" wire:navigate>{{ __('View reports') }}</flux:button>
            <flux:button variant="ghost" icon="arrow-left" :href="route('report-groups.index')" wire:navigate>{{ __('Back') }}</flux:button>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Settings + companies --}}
        <div class="space-y-6">
            <div class="rounded-lg border border-border p-4">
                <form wire:submit="saveName" class="space-y-3">
                    <flux:input wire:model="f_name" :label="__('Group name')" required data-test="group-name-input" />
                    <flux:button variant="primary" size="sm" type="submit">{{ __('Save') }}</flux:button>
                </form>
            </div>

            <div class="rounded-lg border border-border p-4">
                <flux:heading size="sm" class="mb-3">{{ __('Companies') }} <flux:badge size="sm" color="zinc">{{ $reportGroup->currency_code }}</flux:badge></flux:heading>

                <div class="space-y-2">
                    @foreach ($this->members as $company)
                        <div class="flex items-center justify-between rounded-md bg-muted px-3 py-2 text-sm" data-test="member-company-row">
                            <span>{{ $company->name }}</span>
                            <flux:button
                                variant="ghost"
                                size="xs"
                                icon="x-mark"
                                wire:click="removeCompany({{ $company->id }})"
                                wire:confirm="{{ __('Remove this company and its mappings from the group?') }}"
                            />
                        </div>
                    @endforeach
                </div>

                @php($eligible = $this->eligibleCompanies())
                @if ($eligible->isNotEmpty())
                    <div class="mt-3 flex items-end gap-2">
                        <flux:select wire:model="addCompanyId" :label="__('Add company')" size="sm" class="flex-1">
                            <flux:select.option value="">{{ __('Select…') }}</flux:select.option>
                            @foreach ($eligible as $company)
                                <flux:select.option
                                    :value="$company->id"
                                    :disabled="$company->currency_code !== $reportGroup->currency_code"
                                >{{ $company->name }} — {{ $company->currency_code }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:button size="sm" icon="plus" wire:click="addCompany" data-test="add-company-button" />
                    </div>
                    @error('addCompanyId') <flux:text class="mt-1 text-red-600">{{ $message }}</flux:text> @enderror
                @endif
            </div>

            <div class="rounded-lg border border-border p-4">
                <flux:button size="sm" icon="arrow-path" wire:click="reseed" class="w-full" data-test="reseed-button">{{ __('Auto-map new accounts') }}</flux:button>
                <flux:text class="mt-2 text-xs text-muted-foreground">{{ __('Matches unmapped accounts by code. Existing lines are kept.') }}</flux:text>
            </div>
        </div>

        {{-- Combined lines --}}
        <div class="lg:col-span-2">
            <div class="mb-3 flex items-center justify-between">
                <flux:heading size="sm">{{ __('Combined lines') }}</flux:heading>
                <flux:button size="sm" icon="plus" wire:click="openNewLine" data-test="new-line-button">{{ __('New line') }}</flux:button>
            </div>

            <flux:text class="mb-3 text-xs text-muted-foreground">{{ __('Lines drive the Income Statement and Balance Sheet. The Trial Balance always lists individual accounts.') }}</flux:text>

            <div class="space-y-3">
                @forelse ($this->lines as $line)
                    @php($accountTypes = $line->accountMaps->map(fn ($m) => $m->account?->type)->filter()->unique())
                    <div class="rounded-lg border border-border" data-test="line-row">
                        <div class="flex items-center justify-between border-b border-border px-4 py-2">
                            <div class="flex items-center gap-2">
                                <span class="font-medium">{{ $line->name }}</span>
                                <flux:badge size="sm" color="zinc">{{ $line->type->label() }}</flux:badge>
                                @if ($line->subtype)
                                    <flux:badge size="sm" color="zinc">{{ $line->subtype->label() }}</flux:badge>
                                @endif
                                @if ($accountTypes->count() > 1)
                                    <flux:tooltip :content="__('Mapped accounts have mixed types — double-check the sign.')">
                                        <flux:badge size="sm" color="amber">{{ __('Mixed types') }}</flux:badge>
                                    </flux:tooltip>
                                @endif
                            </div>
                            <div class="flex items-center gap-1">
                                <flux:button variant="ghost" size="xs" icon="pencil" wire:click="openEditLine({{ $line->id }})" />
                                <flux:button variant="ghost" size="xs" icon="trash" wire:click="deleteLine({{ $line->id }})" wire:confirm="{{ __('Remove this line? Its accounts will be split into individual lines.') }}" />
                            </div>
                        </div>
                        <div class="divide-y divide-border">
                            @forelse ($line->accountMaps as $map)
                                <div class="flex items-center justify-between gap-2 px-4 py-2 text-sm">
                                    <span class="truncate">
                                        <span class="text-muted-foreground">{{ $map->company?->name }}</span>
                                        &middot; {{ $map->account?->code }} — {{ $map->account?->name }}
                                    </span>
                                    <select
                                        class="rounded-md border border-border bg-card px-2 py-1 text-xs"
                                        wire:change="moveAccount({{ $map->id }}, $event.target.value)"
                                        data-test="move-account-select"
                                    >
                                        @foreach ($this->lines as $target)
                                            <option value="{{ $target->id }}" @selected($target->id === $line->id)>{{ $target->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @empty
                                <div class="px-4 py-2 text-sm text-muted-foreground">{{ __('No accounts mapped.') }}</div>
                            @endforelse
                        </div>
                    </div>
                @empty
                    <flux:text class="py-8 text-center text-muted-foreground">{{ __('No lines yet. Add a company to auto-map its accounts.') }}</flux:text>
                @endforelse
            </div>
        </div>
    </div>

    <flux:modal name="line-form" class="max-w-lg">
        <form wire:submit="saveLine" class="space-y-6">
            <flux:heading size="lg">{{ $editingLineId ? __('Edit line') : __('New line') }}</flux:heading>
            <flux:input wire:model="f_line_name" :label="__('Name')" required data-test="line-name-input" />
            <flux:select wire:model="f_line_type" :label="__('Type')" required data-test="line-type-select">
                @foreach (AccountType::cases() as $type)
                    <flux:select.option :value="$type->value">{{ $type->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="f_line_subtype" :label="__('Subtype (optional)')">
                <flux:select.option value="">{{ __('—') }}</flux:select.option>
                @foreach (AccountSubtype::cases() as $subtype)
                    <flux:select.option :value="$subtype->value">{{ $subtype->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="line-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
