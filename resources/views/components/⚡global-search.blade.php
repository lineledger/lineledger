<?php

use App\Models\Company;
use App\Services\GlobalSearch;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public ?Company $company = null;

    public string $query = '';

    public function mount(): void
    {
        if (app()->bound('current_company')) {
            $this->company = app('current_company');

            return;
        }

        $this->company = Auth::user()?->currentCompany;
    }

    /**
     * @return array<string, \Illuminate\Support\Collection<int, \App\Support\GlobalSearchResult>>
     */
    #[Computed]
    public function results(): array
    {
        if ($this->company === null) {
            return [];
        }

        return app(GlobalSearch::class)->search($this->query);
    }

    public function clear(): void
    {
        $this->query = '';
    }
}; ?>

<div>
    @if ($this->company !== null)
        <div class="flex items-center gap-2 px-2 py-1 in-data-flux-sidebar-collapsed-desktop:hidden">
            <flux:modal.trigger name="global-search">
                <button
                    type="button"
                    class="flex flex-1 items-center justify-between gap-2 rounded-lg border border-border bg-card px-3 py-1.5 text-sm text-muted-foreground shadow-xs transition hover:bg-muted"
                    data-test="global-search-trigger"
                >
                    <span class="flex items-center gap-2">
                        <flux:icon name="magnifying-glass" class="size-4" />
                        {{ __('Search…') }}
                    </span>
                    <kbd class="rounded border border-border bg-muted px-1.5 py-0.5 font-mono text-[10px] leading-none text-muted-foreground">⌘K</kbd>
                </button>
            </flux:modal.trigger>

            <x-calculator />
        </div>

        <flux:modal
            name="global-search"
            class="max-w-4xl! w-screen"
            :closable="false"
            focusable
            x-on:keydown.window.meta.k.prevent="$flux.modal('global-search').show()"
            x-on:keydown.window.ctrl.k.prevent="$flux.modal('global-search').show()"
            x-on:close="$wire.clear()"
        >
            <div class="space-y-4">
                <flux:input
                    wire:model.live.debounce.250ms="query"
                    icon="magnifying-glass"
                    :placeholder="__('Search invoices, bills, contacts…')"
                    autofocus
                    data-test="global-search-input"
                />

                <div class="max-h-[60vh] overflow-y-auto" data-test="global-search-results">
                    @php $hasResults = collect($this->results)->some(fn ($rows) => $rows->isNotEmpty()); @endphp

                    @if ($hasResults)
                        @foreach ($this->results as $group => $rows)
                            @if ($rows->isNotEmpty())
                                <div class="mb-4">
                                    <flux:subheading class="px-2 text-xs uppercase tracking-wide">
                                        {{ __(ucwords(str_replace('_', ' ', $group))) }}
                                    </flux:subheading>

                                    <ul class="mt-1 divide-y divide-border">
                                        @foreach ($rows as $row)
                                            <li>
                                                <a
                                                    href="{{ $row->url }}"
                                                    wire:navigate
                                                    x-on:click="$flux.modal('global-search').close()"
                                                    class="flex items-center justify-between gap-3 rounded px-2 py-2 hover:bg-muted"
                                                    data-test="global-search-result"
                                                >
                                                    <div class="min-w-0">
                                                        <div class="truncate font-mono text-sm">{{ $row->label }}</div>
                                                        @if ($row->secondary)
                                                            <div class="truncate text-xs text-muted-foreground">{{ $row->secondary }}</div>
                                                        @endif
                                                    </div>

                                                    <div class="flex shrink-0 items-center gap-3 text-xs">
                                                        @if ($row->meta)
                                                            <flux:badge size="sm" color="zinc">{{ $row->meta }}</flux:badge>
                                                        @endif
                                                        @if ($row->amountCents !== null)
                                                            <span class="font-mono text-sm tabular-nums">{{ number_format($row->amountCents / 100, 2) }}</span>
                                                        @endif
                                                    </div>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        @endforeach
                    @else
                        <p class="px-2 py-6 text-center text-sm text-muted-foreground">
                            @if (mb_strlen(trim($query)) < 2)
                                {{ __('Type at least 2 characters to search.') }}
                            @else
                                {{ __('No matching records.') }}
                            @endif
                        </p>
                    @endif
                </div>
            </div>
        </flux:modal>
    @endif
</div>
