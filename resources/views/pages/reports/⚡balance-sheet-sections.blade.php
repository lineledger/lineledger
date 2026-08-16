<?php

use App\Concerns\ManagesReportSections;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ReportStatement;
use App\Models\Account;
use App\Models\Company;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Balance Sheet sections')] class extends Component {
    use ManagesReportSections;

    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    protected function statement(): ReportStatement
    {
        return ReportStatement::BalanceSheet;
    }

    /**
     * Balance-sheet subtypes (asset/liability/equity) that this company actually
     * uses, in enum order. Empty subtypes are omitted — there's nothing to group.
     *
     * @return array<string, string>
     */
    public function anchorLabels(): array
    {
        $present = Account::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->whereIn('type', $this->balanceSheetTypes())
            ->get(['subtype'])
            ->map(fn (Account $account): string => $account->subtype->value)
            ->unique();

        $labels = [];

        foreach (AccountSubtype::cases() as $subtype) {
            if (in_array($subtype->type()->value, $this->balanceSheetTypes(), true) && $present->contains($subtype->value)) {
                $labels[$subtype->value] = $subtype->label();
            }
        }

        return $labels;
    }

    /**
     * Anchor groups nested under their type heading for display.
     *
     * @return array<string, array<string, string>>
     */
    public function anchorGroupsByType(): array
    {
        $byType = [];

        foreach ($this->anchorLabels() as $groupKey => $label) {
            $type = AccountSubtype::from($groupKey)->type();
            $byType[$type->label()][$groupKey] = $label;
        }

        return $byType;
    }

    protected function anchorFor(Account $account): ?string
    {
        return in_array($account->type->value, $this->balanceSheetTypes(), true)
            ? $account->subtype->value
            : null;
    }

    /**
     * @return array<int, string>
     */
    protected function balanceSheetTypes(): array
    {
        return [AccountType::Asset->value, AccountType::Liability->value, AccountType::Equity->value];
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Balance Sheet sections') }}</flux:heading>
            <flux:subheading>{{ __('Group accounts under a subtype into custom sections with their own subtotal.') }}</flux:subheading>
        </div>
        <flux:button variant="ghost" icon="arrow-left" :href="route('reports.balance-sheet', ['company' => $company->slug])" wire:navigate>{{ __('Back to report') }}</flux:button>
    </div>

    <div class="space-y-10">
        @foreach ($this->anchorGroupsByType() as $typeLabel => $groups)
            <div>
                <flux:heading size="lg" class="mb-4 border-b border-border pb-2">{{ __($typeLabel) }}</flux:heading>

                <div class="space-y-6">
                    @foreach ($groups as $groupKey => $groupLabel)
                        @php
                            $sections = $this->sections[$groupKey] ?? collect();
                            $accounts = $this->accountsByGroup[$groupKey] ?? collect();
                            $sectionIds = $sections->pluck('id');
                            $unassigned = $accounts->filter(fn ($a) => ! $sectionIds->contains($a->report_section_id));
                        @endphp

                        <div data-test="anchor-group" data-group="{{ $groupKey }}">
                            <div class="mb-2 flex items-center justify-between">
                                <flux:heading>{{ __($groupLabel) }}</flux:heading>
                                <flux:button size="sm" icon="plus" wire:click="openNewSection('{{ $groupKey }}')" data-test="new-section-button">{{ __('New section') }}</flux:button>
                            </div>

                            @include('partials.reports.sections-group', ['groupKey' => $groupKey, 'sections' => $sections, 'unassigned' => $unassigned])
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    @include('partials.reports.section-form-modal')
</section>
