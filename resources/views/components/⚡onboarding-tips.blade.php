<?php

use App\Models\Company;
use App\Support\Onboarding\OnboardingTips;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ?Company $company = null;

    /** Index of the tip currently on screen (one tip shown at a time). */
    public int $index = 0;

    public function mount(): void
    {
        if (app()->bound('current_company')) {
            $this->company = app('current_company');

            return;
        }

        $this->company = Auth::user()?->currentCompany;
    }

    /**
     * Every tip in the catalog, in order.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function tips(): array
    {
        return OnboardingTips::all($this->company);
    }

    /**
     * The tip keys this company has checked off.
     *
     * @return list<string>
     */
    #[Computed]
    public function completed(): array
    {
        return $this->company?->onboardingCompletedTips() ?? [];
    }

    /**
     * Whether the box should appear: enabled, not dismissed, and at least one
     * tip still unchecked. The "all checked → closes" behaviour falls out of
     * the last clause, and adding a new tip later re-opens the box for everyone.
     */
    #[Computed]
    public function visible(): bool
    {
        if ($this->company === null || ! $this->company->onboardingEnabled() || $this->company->onboardingDismissed()) {
            return false;
        }

        return array_diff(OnboardingTips::keys($this->company), $this->completed) !== [];
    }

    public function next(): void
    {
        $this->index = min($this->index + 1, count($this->tips) - 1);
    }

    public function prev(): void
    {
        $this->index = max($this->index - 1, 0);
    }

    /** Toggle a tip's completed state and persist it to the company settings. */
    public function toggleComplete(string $key): void
    {
        if ($this->company === null) {
            return;
        }

        $completed = $this->completed;
        $completed = in_array($key, $completed, true)
            ? array_values(array_diff($completed, [$key]))
            : [...$completed, $key];

        $this->company->setOnboardingState(['completed' => $completed]);

        unset($this->completed, $this->visible);
        $this->index = max(0, min($this->index, count($this->tips) - 1));
    }

    /** X-close the whole box. Re-openable from company settings. */
    public function dismiss(): void
    {
        $this->company?->setOnboardingState(['dismissed' => true]);

        unset($this->visible);
    }
}; ?>

<div>
    @if ($this->visible)
        @php
            $tip = $this->tips[$this->index];
            $count = count($this->tips);
            $isDone = in_array($tip['key'], $this->completed, true);
        @endphp
        <div class="mb-5 rounded-lg border border-sky-300 bg-sky-50 p-4 dark:border-sky-500/40 dark:bg-sky-500/10" data-test="onboarding-tips">
            <div class="flex items-start gap-3">
                <flux:icon name="light-bulb" class="mt-0.5 size-5 shrink-0 text-sky-600 dark:text-sky-400" />
                <div class="flex-1">
                    <div class="flex items-center gap-2">
                        <flux:icon :name="$tip['icon']" variant="micro" class="text-sky-700 dark:text-sky-300" />
                        <p class="font-medium text-sky-900 dark:text-sky-100">{{ $tip['title'] }}</p>
                    </div>
                    <p class="mt-1 text-sm text-sky-800/80 dark:text-sky-200/70">{{ $tip['body'] }}</p>

                    @isset($tip['cta'])
                        <div class="mt-3">
                            <flux:button size="sm" variant="primary" :href="route($tip['cta']['route'])" wire:navigate data-test="onboarding-cta">
                                {{ $tip['cta']['label'] }}
                            </flux:button>
                        </div>
                    @endisset

                    <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2">
                        <flux:checkbox
                            :checked="$isDone"
                            wire:click="toggleComplete('{{ $tip['key'] }}')"
                            :label="__('Mark as done')"
                            data-test="onboarding-check"
                        />

                        @if ($count > 1)
                            <div class="ms-auto flex items-center gap-2">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="chevron-left"
                                    wire:click="prev"
                                    :disabled="$this->index === 0"
                                    :aria-label="__('Previous tip')"
                                    data-test="onboarding-prev"
                                />
                                <span class="text-xs tabular-nums text-sky-800/70 dark:text-sky-200/60">
                                    {{ $this->index + 1 }} {{ __('of') }} {{ $count }}
                                </span>
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="chevron-right"
                                    wire:click="next"
                                    :disabled="$this->index === $count - 1"
                                    :aria-label="__('Next tip')"
                                    data-test="onboarding-next"
                                />
                            </div>
                        @endif
                    </div>
                </div>
                <flux:button
                    size="sm"
                    variant="ghost"
                    icon="x-mark"
                    wire:click="dismiss"
                    wire:confirm="{{ __('Close getting-started tips? You can bring them back from company settings.') }}"
                    :aria-label="__('Close')"
                    data-test="onboarding-dismiss"
                />
            </div>
        </div>
    @endif
</div>
