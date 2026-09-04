<?php

use App\Concerns\ManagesReportGroupSections;
use App\Enums\CashFlowActivity;
use App\Enums\ReportStatement;
use App\Models\ReportGroup;
use App\Models\ReportGroupLine;
use App\Support\Reporting\CashFlowBucket;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Combined Cash Flow sections')] class extends Component {
    use ManagesReportGroupSections;

    public ReportGroup $reportGroup;

    public function mount(ReportGroup $reportGroup): void
    {
        Gate::authorize('update', $reportGroup);

        $this->reportGroup = $reportGroup;
    }

    protected function statement(): ReportStatement
    {
        return ReportStatement::CashFlow;
    }

    /**
     * @return array<string, string>
     */
    public function anchorLabels(): array
    {
        return CashFlowBucket::labels();
    }

    protected function anchorFor(ReportGroupLine $line): ?string
    {
        return CashFlowBucket::forLine($line);
    }

    /**
     * Re-route a combined line to a different cash-flow activity via its per-line
     * override — the combined counterpart of the single-company page's
     * moveAccountToActivity(). Cash-flow-specific, so it lives here rather than in
     * the shared trait.
     */
    public function moveLineToActivity(int $lineId, string $activity): void
    {
        $line = $this->reportGroup->lines()->findOrFail($lineId);

        // Only lines that are already their own activity line may be re-routed;
        // Bank / P&L lines have no activity and must stay excluded.
        if (CashFlowBucket::forValues($line->type, $line->subtype) === null) {
            return;
        }

        if (CashFlowActivity::tryFrom($activity) === null) {
            return;
        }

        // Only the override changes here. ReportGroupLineObserver drops a custom
        // section that no longer accepts the line (its old activity's section)
        // while a balance-sheet / income-statement section is kept.
        $line->update([
            'cash_flow_activity' => CashFlowBucket::normalizeOverride($line->type, $line->subtype, $activity),
        ]);

        unset($this->sections, $this->linesByGroup);
    }

    /**
     * Activity options for the per-line move dropdown on each row.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function activityOptions(): array
    {
        return array_map(
            fn (CashFlowActivity $activity): array => ['value' => $activity->value, 'label' => __($activity->label())],
            CashFlowActivity::cases(),
        );
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Combined Cash Flow sections') }}</flux:heading>
            <flux:subheading>{{ $reportGroup->name }} &middot; {{ __('Group combined lines into custom sections with their own subtotal, or move a line to a different activity.') }}</flux:subheading>
        </div>
        <flux:button variant="ghost" icon="arrow-left" :href="route('report-groups.cash-flow', $reportGroup)" wire:navigate>{{ __('Back to report') }}</flux:button>
    </div>

    <div class="space-y-8">
        @foreach ($this->anchorLabels() as $groupKey => $groupLabel)
            @php
                $sections = $this->sections[$groupKey] ?? collect();
                $lines = $this->linesByGroup[$groupKey] ?? collect();
                $sectionIds = $sections->pluck('id');
                $unassigned = $lines->filter(fn ($l) => ! $sectionIds->contains($l->report_group_section_id));
            @endphp

            <div data-test="anchor-group" data-group="{{ $groupKey }}">
                <div class="mb-3 flex items-center justify-between">
                    <flux:heading size="lg">{{ __($groupLabel) }}</flux:heading>
                    <flux:button size="sm" icon="plus" wire:click="openNewSection('{{ $groupKey }}')" data-test="new-section-button">{{ __('New section') }}</flux:button>
                </div>

                @include('partials.reports.group-sections-group', ['groupKey' => $groupKey, 'sections' => $sections, 'unassigned' => $unassigned, 'activities' => $this->activityOptions()])
            </div>
        @endforeach
    </div>

    @include('partials.reports.section-form-modal')
</section>
