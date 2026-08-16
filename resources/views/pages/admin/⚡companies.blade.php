<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Site Admin — Companies')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    /** '' = all, 'active' = live only, 'deleted' = trashed only. */
    #[Url(as: 'status')]
    public string $statusFilter = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->site_admin, 404);
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function companies()
    {
        return Company::query()
            ->when($this->statusFilter === 'deleted', fn ($q) => $q->onlyTrashed())
            ->when($this->statusFilter !== 'deleted' && $this->statusFilter !== 'active', fn ($q) => $q->withTrashed())
            ->withCount('members')
            // owner() isn't an Eloquent relation; eager-load just the owner
            // member(s) so the list avoids an N+1 on the owner name.
            ->with(['members' => fn ($q) => $q->wherePivot('role', CompanyRole::Owner->value)])
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('slug', 'like', '%'.$this->search.'%')
                    ->orWhere('legal_name', 'like', '%'.$this->search.'%');
            }))
            ->orderBy('name')
            ->paginate(25);
    }
}; ?>

<x-pages::admin.layout
    :heading="__('Companies')"
    :subheading="__('Every company on the platform, including deleted ones.')"
    content-class="max-w-5xl"
>
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <flux:input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search name or slug…') }}"
            icon="magnifying-glass"
            class="sm:max-w-md"
            data-test="company-search"
        />

        <flux:select wire:model.live="statusFilter" class="max-w-[180px]" data-test="company-status-filter">
            <flux:select.option value="">{{ __('All') }}</flux:select.option>
            <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
            <flux:select.option value="deleted">{{ __('Deleted') }}</flux:select.option>
        </flux:select>
    </div>

    {{-- Mobile: stacked cards --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($this->companies as $company)
            <div class="rounded-lg border border-border p-4 @if($company->trashed()) opacity-60 @endif" wire:key="company-card-{{ $company->id }}">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="font-medium">{{ $company->name }}</div>
                        <div class="text-xs text-muted-foreground">{{ $company->slug }}</div>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        @if ($company->trashed())
                            <flux:badge color="red" size="sm">{{ __('Deleted') }}</flux:badge>
                        @endif
                        @if ($company->is_personal)
                            <flux:badge color="zinc" size="sm">{{ __('Personal') }}</flux:badge>
                        @endif
                    </div>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    <span>{{ __('Owner: :name', ['name' => $company->members->first()?->name ?? '—']) }}</span>
                    <span>{{ trans_choice(':count member|:count members', $company->members_count, ['count' => $company->members_count]) }}</span>
                    <span>{{ __('Created :date', ['date' => $company->created_at?->isoFormat('ll')]) }}</span>
                </div>
                <div class="mt-3">
                    <flux:link :href="route('admin.companies.show', $company)" wire:navigate>{{ __('Manage') }}</flux:link>
                </div>
            </div>
        @empty
            <flux:text class="block py-8 text-center text-muted-foreground">{{ __('No companies found.') }}</flux:text>
        @endforelse
    </div>

    {{-- Desktop: full table --}}
    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Name') }}</th>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Slug') }}</th>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Owner') }}</th>
                    <th class="px-4 py-2 text-right font-medium">{{ __('Members') }}</th>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Created') }}</th>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-right font-medium"><span class="sr-only">{{ __('Actions') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->companies as $company)
                    <tr wire:key="company-row-{{ $company->id }}" data-test="company-row" class="@if($company->trashed()) opacity-60 @endif">
                        <td class="px-4 py-2 font-medium">{{ $company->name }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $company->slug }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $company->members->first()?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($company->members_count) }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $company->created_at?->isoFormat('ll') }}</td>
                        <td class="px-4 py-2">
                            @if ($company->trashed())
                                <flux:badge color="red" size="sm">{{ __('Deleted') }}</flux:badge>
                            @elseif ($company->is_personal)
                                <flux:badge color="zinc" size="sm">{{ __('Personal') }}</flux:badge>
                            @else
                                <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            <flux:link :href="route('admin.companies.show', $company)" wire:navigate>{{ __('Manage') }}</flux:link>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-muted-foreground">{{ __('No companies found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->companies->links() }}</div>
</x-pages::admin.layout>
