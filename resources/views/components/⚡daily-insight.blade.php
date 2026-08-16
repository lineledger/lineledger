<?php

use App\Enums\InsightSource;
use App\Models\Company;
use App\Models\DailyInsight;
use App\Services\Insights\InsightDetectorRegistry;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ?Company $company = null;

    public function mount(): void
    {
        if (app()->bound('current_company')) {
            $this->company = app('current_company');

            return;
        }

        $this->company = Auth::user()?->currentCompany;
    }

    /**
     * Today's insight — or yesterday's, covering the gap between the 05:00
     * server-time job and a company's local morning. No lazy generation: the
     * request path never computes detectors or calls the AI; missing row →
     * the card simply hides.
     */
    #[Computed]
    public function insight(): ?DailyInsight
    {
        if ($this->company === null) {
            return null;
        }

        $today = $this->company->currentDateTime();

        return DailyInsight::query()
            ->whereIn('insight_date', [$today->toDateString(), $today->subDay()->toDateString()])
            ->orderByDesc('insight_date')
            ->first();
    }

    /**
     * @return array{route: string, label: string}|null
     */
    #[Computed]
    public function cta(): ?array
    {
        if ($this->insight === null || $this->company === null) {
            return null;
        }

        return InsightDetectorRegistry::ctaFor($this->insight->type, $this->company);
    }
}; ?>

<div>
    @if ($this->insight !== null)
        @php
            // Dismissal is per-user/device on purpose (a bookkeeper closing the
            // card shouldn't hide it from the owner) and self-expires because the
            // insight date is part of the key — no server state, no TTL cleanup.
            $dismissKey = 'insight-dismissed-'.$this->company->id.'-'.$this->insight->insight_date->toDateString();
        @endphp
        <div
            class="mb-5 rounded-lg border border-indigo-300 bg-indigo-50 p-4 dark:border-indigo-500/40 dark:bg-indigo-500/10"
            x-data="{ dismissed: localStorage.getItem('{{ $dismissKey }}') === '1' }"
            x-show="! dismissed"
            x-cloak
            data-test="daily-insight-card"
        >
            <div class="flex items-start gap-3">
                <flux:icon name="sparkles" class="mt-0.5 size-5 shrink-0 text-indigo-600 dark:text-indigo-400" />
                <div class="flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-xs font-medium uppercase tracking-wide text-indigo-700 dark:text-indigo-300">{{ __('Daily insight') }}</p>
                        @if ($this->insight->source === InsightSource::Ai)
                            <flux:badge size="sm" color="indigo" title="{{ __('Phrased by AI from your aggregate totals') }}" data-test="daily-insight-ai-badge">{{ __('AI') }}</flux:badge>
                        @endif
                    </div>
                    <p class="mt-1 font-medium text-indigo-900 dark:text-indigo-100">{{ $this->insight->headline }}</p>
                    <p class="mt-1 text-sm text-indigo-800/80 dark:text-indigo-200/70">{{ $this->insight->body }}</p>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        @if ($this->cta !== null)
                            <flux:button size="sm" variant="primary" :href="route($this->cta['route'], ['company' => $this->company->slug])" wire:navigate data-test="daily-insight-cta">
                                {{ $this->cta['label'] }}
                            </flux:button>
                        @endif
                        <flux:button size="sm" variant="ghost" :href="route('insights.index', ['company' => $this->company->slug])" wire:navigate data-test="daily-insight-history">
                            {{ __('Past insights') }}
                        </flux:button>
                    </div>
                </div>
                <flux:button
                    size="sm"
                    variant="ghost"
                    icon="x-mark"
                    x-on:click="localStorage.setItem('{{ $dismissKey }}', '1'); dismissed = true"
                    :aria-label="__('Dismiss for today')"
                    data-test="daily-insight-dismiss"
                />
            </div>
        </div>
    @endif
</div>
