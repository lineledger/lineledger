<?php

use App\Models\Company;
use App\Models\ReportFavorite;
use App\Support\Reporting\ReportCatalog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Reports')] class extends Component {
    public Company $company;

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    /**
     * The catalog filtered by the search term (matched against label and
     * description). Empty categories are dropped.
     *
     * @return list<array{label: string, reports: list<array<string, mixed>>}>
     */
    #[Computed]
    public function categories(): array
    {
        $catalog = ReportCatalog::for($this->company, Auth::user());
        $term = mb_strtolower(trim($this->search));

        if ($term === '') {
            return $catalog;
        }

        return collect($catalog)
            ->map(function (array $category) use ($term): array {
                $category['reports'] = collect($category['reports'])
                    ->filter(fn (array $report): bool => str_contains(mb_strtolower($report['label']), $term)
                        || str_contains(mb_strtolower($report['description']), $term))
                    ->values()
                    ->all();

                return $category;
            })
            ->filter(fn (array $category): bool => $category['reports'] !== [])
            ->values()
            ->all();
    }

    /**
     * Route keys the current user has favorited (already company-scoped).
     *
     * @return list<string>
     */
    #[Computed]
    public function favoriteKeys(): array
    {
        return ReportFavorite::query()
            ->where('user_id', Auth::id())
            ->pluck('report_key')
            ->all();
    }

    /**
     * Favorited reports as full catalog entries, dropping any that are no longer
     * visible to the user.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function favorites(): array
    {
        if ($this->favoriteKeys === []) {
            return [];
        }

        $flat = ReportCatalog::flatten($this->company, Auth::user());

        return collect($this->favoriteKeys)
            ->map(fn (string $key) => $flat[$key] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    public function toggleFavorite(string $key): void
    {
        if (! array_key_exists($key, ReportCatalog::flatten($this->company, Auth::user()))) {
            return;
        }

        $existing = ReportFavorite::query()
            ->where('user_id', Auth::id())
            ->where('report_key', $key)
            ->first();

        if ($existing !== null) {
            $existing->delete();
        } else {
            ReportFavorite::create([
                'user_id' => Auth::id(),
                'report_key' => $key,
            ]);
        }

        unset($this->favoriteKeys, $this->favorites);

        $this->dispatch('reports-favorites-updated');
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Reports') }}</flux:heading>
            <flux:subheading>{{ __('Browse and run reports. Star a report to pin it to the sidebar.') }}</flux:subheading>
        </div>

        <div class="flex items-end gap-2">
            <flux:input
                type="search"
                wire:model.live.debounce.250ms="search"
                icon="magnifying-glass"
                :placeholder="__('Search reports…')"
                class="max-w-xs"
                data-test="report-search"
            />
            <flux:button :href="route('reports.memorized', ['company' => $company->slug])" wire:navigate variant="ghost" icon="bookmark" data-test="memorized-link">{{ __('Memorized') }}</flux:button>
        </div>
    </div>

    @php $favoriteKeys = $this->favoriteKeys; @endphp

    @if ($this->favorites !== [] && trim($this->search) === '')
        <div class="mb-8" data-test="report-favorites-section">
            <flux:heading size="lg" class="mb-3">{{ __('Favorites') }}</flux:heading>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->favorites as $report)
                    @include('pages.reports.partials.report-card', ['report' => $report, 'isFavorite' => true])
                @endforeach
            </div>
        </div>
    @endif

    @forelse ($this->categories as $category)
        <div class="mb-8" wire:key="cat-{{ \Illuminate\Support\Str::slug($category['label']) }}">
            <flux:heading size="lg" class="mb-3">{{ __($category['label']) }}</flux:heading>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($category['reports'] as $report)
                    @include('pages.reports.partials.report-card', ['report' => $report, 'isFavorite' => in_array($report['key'], $favoriteKeys, true)])
                @endforeach
            </div>
        </div>
    @empty
        <flux:callout icon="magnifying-glass" data-test="report-empty">
            <flux:callout.heading>{{ __('No reports match your search.') }}</flux:callout.heading>
        </flux:callout>
    @endforelse
</section>
