<?php

use App\Models\Company;
use App\Models\ReportFavorite;
use App\Support\Reporting\ReportCatalog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public ?Company $company = null;

    public function mount(): void
    {
        if (app()->bound('current_company')) {
            $this->company = app('current_company');

            return;
        }

        $this->company = Auth::user()?->currentCompany;
    }

    #[On('reports-favorites-updated')]
    public function refresh(): void
    {
        unset($this->favorites);
    }

    /**
     * The user's favorited reports as catalog entries, dropping any no longer
     * visible to them.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function favorites(): array
    {
        if ($this->company === null) {
            return [];
        }

        $keys = ReportFavorite::query()
            ->where('user_id', Auth::id())
            ->pluck('report_key')
            ->all();

        if ($keys === []) {
            return [];
        }

        $flat = ReportCatalog::flatten($this->company, Auth::user());

        return collect($keys)
            ->map(fn (string $key) => $flat[$key] ?? null)
            ->filter()
            ->values()
            ->all();
    }
}; ?>

<div class="contents">
    @foreach ($this->favorites as $report)
        <flux:sidebar.item
            :icon="$report['icon']"
            :href="$report['url']"
            :current="request()->routeIs($report['route'])"
            wire:navigate
            wire:key="fav-{{ $report['key'] }}"
        >
            {{ __($report['label']) }}
        </flux:sidebar.item>
    @endforeach
</div>
