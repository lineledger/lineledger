<?php

use App\Concerns\ManagesReportGroupSections;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ReportStatement;
use App\Models\ReportGroup;
use App\Models\ReportGroupLine;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Combined Balance Sheet sections')] class extends Component {
    use ManagesReportGroupSections;

    public ReportGroup $reportGroup;

    public function mount(ReportGroup $reportGroup): void
    {
        Gate::authorize('update', $reportGroup);

        $this->reportGroup = $reportGroup;
    }

    protected function statement(): ReportStatement
    {
        return ReportStatement::BalanceSheet;
    }

    /**
     * Anchor groups (subtype value, or type value for lines without a subtype)
     * present among the group's balance-sheet lines, nested under their type
     * heading in Asset → Liability → Equity order.
     *
     * @return array<string, array<string, string>>
     */
    public function anchorGroupsByType(): array
    {
        $bsTypes = [AccountType::Asset, AccountType::Liability, AccountType::Equity];

        $present = [];
        $typeOf = [];

        foreach ($this->reportGroup->lines as $line) {
            if (! in_array($line->type, $bsTypes, true)) {
                continue;
            }

            $key = $line->subtype?->value ?? $line->type->value;
            $present[$key] = $line->subtype?->label() ?? $line->type->label();
            $typeOf[$key] = $line->type;
        }

        $byType = [];

        foreach ($bsTypes as $type) {
            foreach ($present as $key => $label) {
                if ($typeOf[$key] === $type) {
                    $byType[$type->label()][$key] = $label;
                }
            }
        }

        return $byType;
    }

    /**
     * @return array<string, string>
     */
    public function anchorLabels(): array
    {
        $flat = [];

        foreach ($this->anchorGroupsByType() as $groups) {
            $flat += $groups;
        }

        return $flat;
    }

    protected function anchorFor(ReportGroupLine $line): ?string
    {
        return in_array($line->type, [AccountType::Asset, AccountType::Liability, AccountType::Equity], true)
            ? ($line->subtype?->value ?? $line->type->value)
            : null;
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Combined Balance Sheet sections') }}</flux:heading>
            <flux:subheading>{{ $reportGroup->name }} &middot; {{ __('Group combined lines under a subtype into custom sections with their own subtotal.') }}</flux:subheading>
        </div>
        <flux:button variant="ghost" icon="arrow-left" :href="route('report-groups.balance-sheet', $reportGroup)" wire:navigate>{{ __('Back to report') }}</flux:button>
    </div>

    <div class="space-y-10">
        @foreach ($this->anchorGroupsByType() as $typeLabel => $groups)
            <div>
                <flux:heading size="lg" class="mb-4 border-b border-border pb-2">{{ __($typeLabel) }}</flux:heading>

                <div class="space-y-6">
                    @foreach ($groups as $groupKey => $groupLabel)
                        @php
                            $sections = $this->sections[$groupKey] ?? collect();
                            $lines = $this->linesByGroup[$groupKey] ?? collect();
                            $sectionIds = $sections->pluck('id');
                            $unassigned = $lines->filter(fn ($l) => ! $sectionIds->contains($l->report_group_section_id));
                        @endphp

                        <div data-test="anchor-group" data-group="{{ $groupKey }}">
                            <div class="mb-2 flex items-center justify-between">
                                <flux:heading>{{ __($groupLabel) }}</flux:heading>
                                <flux:button size="sm" icon="plus" wire:click="openNewSection('{{ $groupKey }}')" data-test="new-section-button">{{ __('New section') }}</flux:button>
                            </div>

                            @include('partials.reports.group-sections-group', ['groupKey' => $groupKey, 'sections' => $sections, 'unassigned' => $unassigned])
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    @include('partials.reports.section-form-modal')
</section>
