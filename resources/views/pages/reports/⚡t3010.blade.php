<?php

use App\Models\Company;
use App\Services\Charity\T3010Calculator;
use App\Services\Reporting\CsvExporter;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('T3010 Summary')] class extends Component
{
    public Company $company;

    #[Url]
    public int $year = 0;

    public function mount(Company $company): void
    {
        $this->company = $company;

        abort_unless($company->isRegisteredCharity(), 403);

        if ($this->year === 0) {
            $this->year = $company->currentDateTime()->subYear()->year;
        }
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function summary(): array
    {
        return app(T3010Calculator::class)->summary(
            $this->company,
            CarbonImmutable::create($this->year, 1, 1),
            CarbonImmutable::create($this->year, 12, 31),
        );
    }

    /**
     * @return array<string, int>
     */
    public function lines(): array
    {
        $s = $this->summary;

        return [
            __('Eligible receipted donations') => $s['total_eligible_receipted_cents'],
            __('Total revenue') => $s['total_revenue_cents'],
            __('Other revenue') => $s['other_revenue_cents'],
            __('Charitable program expenditures') => $s['charitable_program_cents'],
            __('Management & administration') => $s['management_admin_cents'],
            __('Fundraising') => $s['fundraising_cents'],
            __('Total expenditures') => $s['total_expenditures_cents'],
            __('Total assets') => $s['total_assets_cents'],
            __('Total liabilities') => $s['total_liabilities_cents'],
            __('Net assets') => $s['net_assets_cents'],
        ];
    }

    public function exportCsv()
    {
        $rows = collect($this->lines())->map(fn (int $v, string $label) => [$label, CsvExporter::cents($v)])->values();

        return app(CsvExporter::class)->stream("t3010-{$this->year}.csv", ['Line', 'Amount'], $rows);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" data-test="report-title">{{ __('T3010 Summary') }}</flux:heading>
            <flux:subheading>{{ $company->name }} · {{ __('Registered charity information return figures') }} · {{ $year }}</flux:subheading>
        </div>
        <div class="flex items-end gap-3">
            <flux:input type="number" wire:model.live="year" :label="__('Year')" class="w-28" data-test="t3010-year" />
            <flux:button variant="ghost" icon="arrow-down-tray" wire:click="exportCsv">{{ __('Export CSV') }}</flux:button>
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <tbody class="divide-y divide-border">
                @foreach ($this->lines() as $label => $value)
                    <tr>
                        <td class="px-4 py-2">{{ $label }}</td>
                        <td class="px-4 py-2 text-right font-mono" data-test="t3010-amount">{{ number_format($value / 100, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <flux:text class="mt-3 text-muted-foreground">{{ __('Receipted donations are summed from issued official receipts; revenue, expenditures, and balance-sheet totals are computed from the general ledger for the calendar year.') }}</flux:text>
</section>
