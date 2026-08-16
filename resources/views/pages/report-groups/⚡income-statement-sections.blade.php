<?php

use App\Concerns\ManagesReportGroupSections;
use App\Enums\ReportStatement;
use App\Models\ReportGroup;
use App\Models\ReportGroupLine;
use App\Support\Reporting\IncomeStatementBucket;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Combined Income Statement sections')] class extends Component {
    use ManagesReportGroupSections;

    public ReportGroup $reportGroup;

    public function mount(ReportGroup $reportGroup): void
    {
        Gate::authorize('update', $reportGroup);

        $this->reportGroup = $reportGroup;
    }

    protected function statement(): ReportStatement
    {
        return ReportStatement::IncomeStatement;
    }

    /**
     * @return array<string, string>
     */
    public function anchorLabels(): array
    {
        return IncomeStatementBucket::labels();
    }

    protected function anchorFor(ReportGroupLine $line): ?string
    {
        return IncomeStatementBucket::forValues($line->type, $line->subtype);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Combined Income Statement sections') }}</flux:heading>
            <flux:subheading>{{ $reportGroup->name }} &middot; {{ __('Group combined lines into custom sections with their own subtotal.') }}</flux:subheading>
        </div>
        <flux:button variant="ghost" icon="arrow-left" :href="route('report-groups.income-statement', $reportGroup)" wire:navigate>{{ __('Back to report') }}</flux:button>
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

                @include('partials.reports.group-sections-group', ['groupKey' => $groupKey, 'sections' => $sections, 'unassigned' => $unassigned])
            </div>
        @endforeach
    </div>

    @include('partials.reports.section-form-modal')
</section>
