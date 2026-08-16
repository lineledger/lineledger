<?php

use App\Actions\Reporting\SeedReportGroupMappings;
use App\Models\Company;
use App\Models\ReportGroup;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Combined reports')] class extends Component {
    public string $f_name = '';

    /** @var array<int, int> */
    public array $f_companies = [];

    public function openCreate(): void
    {
        $this->reset(['f_name', 'f_companies']);
        Flux::modal('report-group-form')->show();
    }

    public function create(SeedReportGroupMappings $seed): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'f_name' => ['required', 'string', 'max:255'],
            'f_companies' => ['required', 'array', 'min:2', 'max:10'],
            'f_companies.*' => ['integer'],
        ], [
            'f_companies.min' => __('Pick at least two companies to combine.'),
            'f_companies.max' => __('A group can combine at most ten companies.'),
        ]);

        $companies = $this->ownedCompanyOptions()->whereIn('id', $validated['f_companies'])->values();

        // Single-currency groups only: every member must share one currency.
        if ($companies->pluck('currency_code')->unique()->count() > 1) {
            $this->addError('f_companies', __('All companies in a group must use the same currency.'));

            return;
        }

        $group = ReportGroup::create([
            'user_id' => $user->id,
            'name' => $validated['f_name'],
            'currency_code' => $companies->first()->currency_code,
        ]);

        $group->companies()->attach($companies->pluck('id')->all());

        $seed->handle($group);

        Flux::modal('report-group-form')->close();
        Flux::toast(variant: 'success', text: __('Report group created.'));

        $this->redirectRoute('report-groups.edit', ['reportGroup' => $group->id], navigate: true);
    }

    /**
     * Companies the user belongs to (candidates for a group).
     *
     * @return Collection<int, Company>
     */
    #[Computed]
    public function ownedCompanyOptions(): Collection
    {
        return Auth::user()->companies()->orderByRaw('LOWER(companies.name)')->get();
    }

    /**
     * Groups the user created, plus groups shared with them (member of every company).
     *
     * @return Collection<int, ReportGroup>
     */
    #[Computed]
    public function groups(): Collection
    {
        $user = Auth::user();
        $companyIds = $user->companies()->pluck('companies.id');

        return ReportGroup::query()
            ->where('user_id', $user->id)
            ->orWhereHas('companies', fn ($q) => $q->whereIn('companies.id', $companyIds))
            ->with('companies')
            ->get()
            ->filter(fn (ReportGroup $g) => $g->user_id === $user->id || $g->isVisibleTo($user))
            ->unique('id')
            ->sortBy('name')
            ->values();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Combined reports')" :subheading="__('Combine reports across companies you belong to.')">
        <div class="mb-4 flex justify-end">
            <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-report-group-button">{{ __('New group') }}</flux:button>
        </div>

        <div class="space-y-3">
            @forelse ($this->groups as $group)
                <div class="flex items-center justify-between rounded-lg border border-border bg-card p-4" data-test="report-group-row">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ $group->name }}</span>
                            <flux:badge size="sm" color="zinc">{{ $group->currency_code }}</flux:badge>
                            @if ($group->user_id !== auth()->id())
                                <flux:badge size="sm" color="blue">{{ __('Shared') }}</flux:badge>
                            @endif
                        </div>
                        <flux:text class="text-sm text-muted-foreground">
                            {{ $group->companies->pluck('name')->join(', ') }}
                        </flux:text>
                    </div>

                    <div class="flex items-center gap-1">
                        <flux:button variant="ghost" size="sm" icon="chart-bar" :href="route('report-groups.balance-sheet', $group)" wire:navigate :tooltip="__('Reports')" />
                        @if ($group->user_id === auth()->id())
                            <flux:button variant="ghost" size="sm" icon="pencil" :href="route('report-groups.edit', $group)" wire:navigate :tooltip="__('Edit')" data-test="report-group-edit-button" />
                        @endif
                    </div>
                </div>
            @empty
                <flux:text class="py-8 text-center text-muted-foreground">
                    {{ __('No combined report groups yet.') }}
                </flux:text>
            @endforelse
        </div>
    </x-pages::settings.layout>

    <flux:modal name="report-group-form" class="max-w-lg">
        <form wire:submit="create" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('New combined report group') }}</flux:heading>
                <flux:subheading>{{ __('Pick two or more companies that share a currency.') }}</flux:subheading>
            </div>

            <flux:input wire:model="f_name" :label="__('Group name')" required data-test="report-group-name" />

            <flux:checkbox.group :label="__('Companies')" wire:model="f_companies">
                @foreach ($this->ownedCompanyOptions as $company)
                    <flux:checkbox
                        :value="$company->id"
                        :label="$company->name.' — '.$company->currency_code"
                        data-test="report-group-company-option"
                    />
                @endforeach
            </flux:checkbox.group>

            @error('f_companies') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="report-group-save-button">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
