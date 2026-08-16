<?php

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Company;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Fixed assets')] class extends Component {
    use WithPagination;

    public Company $company;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = 'all';

    #[Url(as: 'cat')]
    public ?int $categoryId = null;

    #[Url(as: 'inactive')]
    public bool $showInactive = false;

    #[Url(as: 'sort')]
    public string $sortField = 'asset_no';

    #[Url(as: 'dir')]
    public string $sortDir = 'asc';

    /** @var array<int, string> */
    private const SORT_FIELDS = ['asset_no', 'name', 'acquired_date', 'cost_cents', 'status'];

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, self::SORT_FIELDS, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDir = 'asc';
        }

        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryId(): void
    {
        $this->resetPage();
    }

    public function updatedShowInactive(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function assets()
    {
        return Asset::query()
            ->with(['category', 'assetAccount'])
            ->when(! $this->showInactive, fn ($q) => $q->where('is_active', true))
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->categoryId, fn ($q) => $q->where('asset_category_id', $this->categoryId))
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $q->where('asset_no', 'like', '%'.$this->search.'%')
                    ->orWhere('name', 'like', '%'.$this->search.'%')
                    ->orWhere('serial_number', 'like', '%'.$this->search.'%');
            }))
            ->orderBy($this->sortField, $this->sortDir)
            ->orderBy('id', 'desc')
            ->paginate(25);
    }

    #[Computed]
    public function categoryOptions()
    {
        return AssetCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Fixed assets') }}</flux:heading>
            <flux:subheading>{{ __('Asset register.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('assets.create', ['company' => $company->slug])" wire:navigate data-test="new-asset-button">
            {{ __('New asset') }}
        </flux:button>
    </div>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search asset #, name, serial…') }}" icon="magnifying-glass" class="sm:max-w-md" data-test="asset-search" />

        <flux:select wire:model.live="statusFilter" class="max-w-[200px]" data-test="asset-status-filter">
            <flux:select.option value="all">{{ __('All statuses') }}</flux:select.option>
            @foreach (AssetStatus::cases() as $s)
                <flux:select.option :value="$s->value">{{ $s->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="categoryId" class="max-w-[220px]" data-test="asset-category-filter">
            <flux:select.option value="">{{ __('All categories') }}</flux:select.option>
            @foreach ($this->categoryOptions as $cat)
                <flux:select.option :value="$cat->id">{{ $cat->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:switch wire:model.live="showInactive" :label="__('Show inactive')" />
    </div>

    {{-- Mobile: stacked cards --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($this->assets as $asset)
            <a href="{{ route('assets.show', ['company' => $company->slug, 'asset' => $asset->id]) }}" wire:navigate class="block rounded-lg border border-border p-4 @if(! $asset->is_active) opacity-50 @endif" data-test="asset-card">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-medium">{{ $asset->name }}</span>
                    @switch($asset->status->value)
                        @case('in-service') <flux:badge color="green" size="sm">{{ $asset->status->label() }}</flux:badge> @break
                        @case('disposed') <flux:badge color="zinc" size="sm">{{ $asset->status->label() }}</flux:badge> @break
                        @case('sold') <flux:badge color="blue" size="sm">{{ $asset->status->label() }}</flux:badge> @break
                        @case('lost') <flux:badge color="red" size="sm">{{ $asset->status->label() }}</flux:badge> @break
                    @endswitch
                </div>
                <div class="mt-1 font-mono text-xs text-muted-foreground">{{ $asset->asset_no }}</div>
                <div class="mt-3 flex items-end justify-between gap-2">
                    <div class="text-sm text-muted-foreground">{{ optional($asset->category)->name }}</div>
                    <div class="text-right"><div class="font-mono font-semibold">{{ number_format($asset->cost_cents / 100, 2) }}</div></div>
                </div>
            </a>
        @empty
            <flux:text class="block py-8 text-center text-muted-foreground">{{ __('No assets yet.') }}</flux:text>
        @endforelse
    </div>

    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">
                        <x-sort-header field="asset_no" :current-field="$sortField" :current-dir="$sortDir" :label="__('Asset #')" />
                    </th>
                    <th class="px-4 py-2 text-left">
                        <x-sort-header field="name" :current-field="$sortField" :current-dir="$sortDir" :label="__('Name')" />
                    </th>
                    <th class="px-4 py-2 text-left">{{ __('Category') }}</th>
                    <th class="px-4 py-2 text-left">
                        <x-sort-header field="acquired_date" :current-field="$sortField" :current-dir="$sortDir" :label="__('Acquired')" />
                    </th>
                    <th class="px-4 py-2 text-right">
                        <x-sort-header field="cost_cents" :current-field="$sortField" :current-dir="$sortDir" :label="__('Cost')" align="right" />
                    </th>
                    <th class="px-4 py-2">
                        <x-sort-header field="status" :current-field="$sortField" :current-dir="$sortDir" :label="__('Status')" />
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->assets as $asset)
                    <tr data-test="asset-row" class="cursor-pointer hover:bg-muted @if(! $asset->is_active) opacity-50 @endif" wire:navigate.hover>
                        <td class="px-4 py-2 font-mono">
                            <a href="{{ route('assets.show', ['company' => $company->slug, 'asset' => $asset->id]) }}" wire:navigate class="underline">{{ $asset->asset_no }}</a>
                        </td>
                        <td class="px-4 py-2">{{ $asset->name }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ optional($asset->category)->name }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $asset->acquired_date->toDateString() }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($asset->cost_cents / 100, 2) }}</td>
                        <td class="px-4 py-2">
                            @switch($asset->status->value)
                                @case('in-service') <flux:badge color="green">{{ $asset->status->label() }}</flux:badge> @break
                                @case('disposed') <flux:badge color="zinc">{{ $asset->status->label() }}</flux:badge> @break
                                @case('sold') <flux:badge color="blue">{{ $asset->status->label() }}</flux:badge> @break
                                @case('lost') <flux:badge color="red">{{ $asset->status->label() }}</flux:badge> @break
                            @endswitch
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ __('No assets yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->assets->links() }}</div>
</section>
