<?php

use App\Models\Company;
use App\Services\Fundraising\FundraisingReportCalculator;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Donations by Fund')] class extends Component {
    public Company $company;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(Company $company): void
    {
        $this->company = $company;

        abort_unless($company->tracksFundraising() && $company->tracksFunds(), 403);

        $now = $company->currentDateTime();
        $this->from = $this->from ?: $now->startOfYear()->toDateString();
        $this->to = $this->to ?: $now->endOfYear()->toDateString();
    }

    #[Computed]
    public function rows()
    {
        return app(FundraisingReportCalculator::class)->donationsByFund(
            $this->company,
            CarbonImmutable::parse($this->from),
            CarbonImmutable::parse($this->to),
        );
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" data-test="report-title">{{ __('Donations by Fund') }}</flux:heading>
            <flux:subheading>{{ $company->name }} · {{ __('Posted restricted donations') }}</flux:subheading>
        </div>
        <div class="flex items-end gap-3">
            <flux:input type="date" wire:model.live="from" :label="__('From')" data-test="donations-fund-from" />
            <flux:input type="date" wire:model.live="to" :label="__('To')" data-test="donations-fund-to" />
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Fund') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->rows as $row)
                    <tr data-test="donations-by-fund-row">
                        <td class="px-4 py-2">{{ $row['fund'] }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ Money::fromCents($row['total_cents'], $company->currency_code) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="px-4 py-6 text-center text-muted-foreground">{{ __('No restricted donations in this period.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
