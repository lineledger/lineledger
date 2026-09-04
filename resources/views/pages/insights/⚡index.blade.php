<?php

use App\Enums\InsightSource;
use App\Models\Company;
use App\Models\DailyInsight;
use App\Services\Insights\InsightDetectorRegistry;
use App\Services\Reporting\FinancialMetrics;
use App\Services\Reporting\FinancialRatios;
use App\Services\Reporting\ReportCalculator;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Daily insights')] class extends Component {
    use WithPagination;

    public Company $company;

    /** Per-user: show the daily insight card on the dashboard. */
    public bool $showOnDashboard = true;

    public function mount(Company $company): void
    {
        $this->company = $company;
        $this->showOnDashboard = Auth::user()?->show_daily_insights !== false;
    }

    public function updatedShowOnDashboard(bool $value): void
    {
        $user = Auth::user();
        $user->show_daily_insights = $value;
        $user->save();

        Flux::toast(variant: 'success', text: $value
            ? __('New insights will show on your dashboard.')
            : __('Insights are hidden from your dashboard. You can still read them here.'));
    }

    /**
     * localStorage keys the dashboard card uses to remember a same-day dismissal
     * (today's and yesterday's insight, mirroring the card's lookup window), so
     * "Show on dashboard again" can clear them.
     *
     * @return list<string>
     */
    #[Computed]
    public function dismissKeys(): array
    {
        $today = $this->company->currentDateTime();

        return [
            'insight-dismissed-'.$this->company->id.'-'.$today->toDateString(),
            'insight-dismissed-'.$this->company->id.'-'.$today->subDay()->toDateString(),
        ];
    }

    #[Computed]
    public function hasCurrentInsight(): bool
    {
        $today = $this->company->currentDateTime();

        return DailyInsight::query()
            ->whereIn('insight_date', [$today->toDateString(), $today->subDay()->toDateString()])
            ->exists();
    }

    #[Computed]
    public function insights()
    {
        return DailyInsight::query()
            ->orderByDesc('insight_date')
            ->paginate(30);
    }

    /**
     * Fiscal-year-to-date figures + ratios for the "Financial health" panel.
     * Deterministic — every number is recomputed from the GL via the shared
     * {@see FinancialMetrics} / {@see FinancialRatios} services, so the panel
     * agrees with the dashboard and reports to the cent.
     *
     * @return array{period: array<string, int|string>, ratios: array<string, array<string, float|int|string|null>>}
     */
    #[Computed]
    public function health(): array
    {
        $today = $this->company->currentDateTime();
        $start = app(ReportCalculator::class)->fiscalYearStart($this->company, $today);

        $metrics = app(FinancialMetrics::class);
        $period = $metrics->period($this->company, $start, $today);
        $ratios = app(FinancialRatios::class)->compute($period, $metrics->monthlySeries($this->company, 3));

        return ['period' => $period, 'ratios' => $ratios];
    }

    /**
     * key → detector instance for the badge + CTA, resolved once per render.
     * Rows whose key has been retired from the catalogue simply render
     * without a badge or CTA.
     *
     * @return array<string, \App\Services\Insights\Contracts\InsightDetector>
     */
    #[Computed]
    public function detectors(): array
    {
        return InsightDetectorRegistry::instances();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" data-test="page-title">{{ __('Daily insights') }}</flux:heading>
            <flux:subheading>{{ __('A short note about your books, every day.') }}</flux:subheading>
        </div>

        <div class="max-w-md space-y-2 rounded-lg border border-border p-3" data-test="insight-dashboard-preference">
            <flux:switch
                wire:model.live="showOnDashboard"
                :label="__('Show daily insights on my dashboard')"
                :description="__('Closing an insight on the dashboard hides it for that day only. The next new insight appears automatically while this is on.')"
                data-test="insights-dashboard-switch"
            />
            @if ($showOnDashboard && $this->hasCurrentInsight)
                <div x-data="{ keys: @js($this->dismissKeys), dashboard: @js(route('dashboard', ['company' => $company->slug])) }" data-test="insight-show-again">
                    <flux:button
                        size="sm"
                        variant="ghost"
                        icon="arrow-uturn-left"
                        x-on:click="keys.forEach((key) => localStorage.removeItem(key)); Livewire.navigate(dashboard)"
                    >
                        {{ __('Show today\'s insight on the dashboard again') }}
                    </flux:button>
                </div>
            @endif
        </div>
    </div>

    @php
        $period = $this->health['period'];
        $ratios = $this->health['ratios'];
        $hasFinancialData = $period['revenue_cents'] !== 0
            || $period['cash_cents'] !== 0
            || $period['current_assets_cents'] !== 0
            || $period['current_liabilities_cents'] !== 0;
        $healthCards = [
            ['label' => __('Revenue'), 'value' => $period['revenue_display']],
            ['label' => __('Net income'), 'value' => $period['net_income_display']],
            ['label' => __('Cash on hand'), 'value' => $period['cash_display']],
            ['label' => __('Gross margin'), 'value' => $ratios['gross_margin']['display']],
            ['label' => __('Net margin'), 'value' => $ratios['net_margin']['display']],
            ['label' => __('Current ratio'), 'value' => $ratios['current_ratio']['display']],
            ['label' => __('Cash runway'), 'value' => $ratios['cash_runway_months']['display']],
            ['label' => __('Days to collect'), 'value' => $ratios['dso_days']['display']],
        ];
    @endphp

    @if ($hasFinancialData)
        <div class="mb-8" data-test="financial-health">
            <div class="mb-3 flex items-baseline justify-between">
                <flux:heading size="lg">{{ __('Financial health') }}</flux:heading>
                <span class="text-xs text-muted-foreground">{{ __('Fiscal year to date') }}</span>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
                @foreach ($healthCards as $card)
                    <div class="rounded-md border border-border px-3 py-2.5">
                        <p class="text-xs text-muted-foreground">{{ $card['label'] }}</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums">{{ $card['value'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @php $lastMonth = null; @endphp

    <div class="space-y-2" data-test="insight-history">
        @forelse ($this->insights as $insight)
            @php
                $month = $insight->insight_date->format('F Y');
                $detector = $this->detectors[$insight->type] ?? null;
                $cta = $detector?->cta($this->company);
            @endphp

            @if ($month !== $lastMonth)
                @php $lastMonth = $month; @endphp
                <flux:heading size="sm" class="{{ $loop->first ? '' : 'pt-4' }}">{{ $month }}</flux:heading>
            @endif

            <div class="flex items-start gap-3 rounded-md border border-border px-3 py-2.5" data-test="insight-row">
                <span class="w-14 shrink-0 pt-0.5 text-xs tabular-nums text-muted-foreground">{{ $insight->insight_date->format('M j') }}</span>
                <div class="flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-medium">{{ $insight->headline }}</p>
                        @if ($detector !== null)
                            <flux:badge size="sm" color="zinc">{{ $detector->category()->label() }}</flux:badge>
                        @endif
                        @if ($insight->source === InsightSource::Ai)
                            <flux:icon name="sparkles" variant="micro" class="text-muted-foreground" title="{{ __('Written with AI') }}" data-test="insight-ai-marker" />
                        @endif
                    </div>
                    <p class="mt-0.5 text-sm text-muted-foreground">{{ $insight->body }}</p>
                </div>
                @if ($cta !== null)
                    <flux:button size="sm" variant="ghost" :href="route($cta['route'], ['company' => $company->slug])" wire:navigate class="shrink-0">
                        {{ $cta['label'] }}
                    </flux:button>
                @endif
            </div>
        @empty
            <p class="py-12 text-center text-sm text-muted-foreground" data-test="insight-empty">
                {{ __('Insights will appear here once your books have a day of activity.') }}
            </p>
        @endforelse
    </div>

    <div class="mt-4">
        {{ $this->insights->links() }}
    </div>
</section>
