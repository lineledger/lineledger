<?php

use App\Models\Company;
use App\Services\Fundraising\FundraisingReportCalculator;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Grants Summary')] class extends Component {
    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company;

        abort_unless($company->tracksFundraising(), 403);
    }

    #[Computed]
    public function rows()
    {
        return app(FundraisingReportCalculator::class)->grantsSummary($this->company);
    }
}; ?>

<section class="w-full">
    <div class="mb-6">
        <flux:heading size="xl" level="1" data-test="report-title">{{ __('Grants Summary') }}</flux:heading>
        <flux:subheading>{{ $company->name }}</flux:subheading>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Grant #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Funder') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Award') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Recognized') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Deferred') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->rows as $row)
                    <tr data-test="grants-summary-row">
                        <td class="px-4 py-2">{{ $row['grant_no'] }}</td>
                        <td class="px-4 py-2">{{ $row['name'] }}</td>
                        <td class="px-4 py-2">{{ $row['funder'] }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ Money::fromCents($row['award_cents'], $company->currency_code) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ Money::fromCents($row['recognized_cents'], $company->currency_code) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ Money::fromCents($row['deferred_cents'], $company->currency_code) }}</td>
                        <td class="px-4 py-2">{{ $row['status'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-muted-foreground">{{ __('No grants yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
