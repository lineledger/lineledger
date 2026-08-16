<?php

use App\Concerns\ManagesReportSections;
use App\Enums\ReportStatement;
use App\Models\Account;
use App\Models\Company;
use App\Support\Reporting\IncomeStatementBucket;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Income Statement sections')] class extends Component {
    use ManagesReportSections;

    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company;
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

    protected function anchorFor(Account $account): ?string
    {
        return IncomeStatementBucket::for($account);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Income Statement sections') }}</flux:heading>
            <flux:subheading>{{ __('Group accounts into custom sections with their own subtotal.') }}</flux:subheading>
        </div>
        <flux:button variant="ghost" icon="arrow-left" :href="route('reports.income-statement', ['company' => $company->slug])" wire:navigate>{{ __('Back to report') }}</flux:button>
    </div>

    <div class="space-y-8">
        @foreach ($this->anchorLabels() as $groupKey => $groupLabel)
            @php
                $sections = $this->sections[$groupKey] ?? collect();
                $accounts = $this->accountsByGroup[$groupKey] ?? collect();
                $sectionIds = $sections->pluck('id');
                $unassigned = $accounts->filter(fn ($a) => ! $sectionIds->contains($a->report_section_id));
            @endphp

            <div data-test="anchor-group" data-group="{{ $groupKey }}">
                <div class="mb-3 flex items-center justify-between">
                    <flux:heading size="lg">{{ __($groupLabel) }}</flux:heading>
                    <flux:button size="sm" icon="plus" wire:click="openNewSection('{{ $groupKey }}')" data-test="new-section-button">{{ __('New section') }}</flux:button>
                </div>

                @include('partials.reports.sections-group', ['groupKey' => $groupKey, 'sections' => $sections, 'unassigned' => $unassigned])
            </div>
        @endforeach
    </div>

    @include('partials.reports.section-form-modal')
</section>
