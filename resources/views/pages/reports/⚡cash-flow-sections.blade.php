<?php

use App\Concerns\ManagesReportSections;
use App\Enums\CashFlowActivity;
use App\Enums\ReportStatement;
use App\Models\Account;
use App\Models\Company;
use App\Support\Reporting\CashFlowBucket;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cash Flow sections')] class extends Component {
    use ManagesReportSections;

    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company;
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

    protected function anchorFor(Account $account): ?string
    {
        return CashFlowBucket::for($account);
    }

    /**
     * Re-route an account to a different cash-flow activity via its per-account
     * override. Cash-flow-specific (the balance sheet and income statement have
     * no cross-anchor moves), so it lives here rather than in the shared trait.
     */
    public function moveAccountToActivity(int $accountId, string $activity): void
    {
        $account = Account::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->findOrFail($accountId);

        // Only accounts that are already their own activity line may be re-routed;
        // Bank / P&L accounts have no activity and must stay excluded.
        if (CashFlowBucket::forValues($account->type, $account->subtype) === null) {
            return;
        }

        if (CashFlowActivity::tryFrom($activity) === null) {
            return;
        }

        $account->update([
            'cash_flow_activity' => $activity,
            'report_section_id' => null, // its old custom section belonged to the old activity
        ]);

        unset($this->sections, $this->accountsByGroup);
    }

    /**
     * Activity options for the per-account move dropdown on each row.
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
            <flux:heading size="xl" level="1">{{ __('Cash Flow sections') }}</flux:heading>
            <flux:subheading>{{ __('Group accounts into custom sections with their own subtotal.') }}</flux:subheading>
        </div>
        <flux:button variant="ghost" icon="arrow-left" :href="route('reports.cash-flow', ['company' => $company->slug])" wire:navigate>{{ __('Back to report') }}</flux:button>
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

                @include('partials.reports.sections-group', ['groupKey' => $groupKey, 'sections' => $sections, 'unassigned' => $unassigned, 'activities' => $this->activityOptions()])
            </div>
        @endforeach
    </div>

    @include('partials.reports.section-form-modal')
</section>
