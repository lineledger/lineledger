<?php

use App\Concerns\HasReportChart;
use App\Models\Company;
use App\Services\Reporting\CashflowForecaster;
use App\Support\Money;
use App\Support\Reporting\ChartContext;
use App\Support\Reporting\ReportChartBuilder;
use App\Support\Reporting\StatementLabels;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Cash flow forecast')] class extends Component {
    use HasReportChart;

    public Company $company;

    #[Url]
    public string $granularity = 'week';

    /** Cash floor to alarm on, as a money string (e.g. "5000" or "5,000.00"). */
    #[Url]
    public string $floor = '0';

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function forecast(): array
    {
        $floorCents = Money::tryFromString($this->floor === '' ? '0' : $this->floor)?->cents ?? 0;

        return app(CashflowForecaster::class)->forecast(
            $this->company,
            $this->granularity === 'month' ? 'month' : 'week',
            $this->granularity === 'month' ? 6 : 13,
            $floorCents,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function charts(): array
    {
        return ReportChartBuilder::cashflowForecast($this->forecast, new ChartContext(
            currency: $this->company->currency_code ?? 'USD',
            labels: StatementLabels::for($this->company),
        ));
    }

    public function setGranularity(string $granularity): void
    {
        $this->granularity = $granularity === 'month' ? 'month' : 'week';
    }
}; ?>

<section class="w-full">
    @php
        $forecast = $this->forecast;
        $currency = $this->company->currency_code ?? 'USD';
        $fmt = fn ($cents): string => \App\Support\Money::fromCents((int) $cents, $currency)->format();
        $horizonLabel = $forecast['granularity'] === 'month' ? __('Next 6 months') : __('Next 13 weeks');
        $runrateDaily = (int) $forecast['runrate_daily_cents'];
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" data-test="page-title">{{ __('Cash flow forecast') }}</flux:heading>
            <flux:subheading>{{ __('Where your cash is headed, from open invoices, bills, and your recent run-rate.') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <flux:button.group>
                <flux:button size="sm" :variant="$forecast['granularity'] === 'week' ? 'primary' : 'outline'" wire:click="setGranularity('week')" data-test="granularity-week">{{ __('Weekly') }}</flux:button>
                <flux:button size="sm" :variant="$forecast['granularity'] === 'month' ? 'primary' : 'outline'" wire:click="setGranularity('month')" data-test="granularity-month">{{ __('Monthly') }}</flux:button>
            </flux:button.group>

            <flux:input
                size="sm"
                type="text"
                wire:model.blur="floor"
                :label="__('Low-cash alert at')"
                :placeholder="$fmt(0)"
                class="w-32"
                data-test="floor-input"
            />
        </div>
    </div>

    @if ($forecast['breaches_floor'])
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6" data-test="forecast-alarm">
            <flux:callout.heading>{{ __('Cash is projected to run low') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('At today\'s open invoices and bills, your committed cash balance falls below :floor around :date. Collecting overdue invoices or deferring a bill would close the gap.', [
                    'floor' => $fmt($forecast['floor_cents']),
                    'date' => \Carbon\CarbonImmutable::parse($forecast['first_breach_date'])->isoFormat('MMM D, YYYY'),
                ]) }}
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-border px-4 py-3" data-test="forecast-opening">
            <p class="text-xs text-muted-foreground">{{ __('Cash on hand today') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $fmt($forecast['opening_cents']) }}</p>
        </div>
        <div class="rounded-xl border border-border px-4 py-3" data-test="forecast-low">
            <p class="text-xs text-muted-foreground">{{ __('Lowest projected balance') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums {{ $forecast['lowest_committed_cents'] < $forecast['floor_cents'] ? 'text-red-600 dark:text-red-400' : '' }}">{{ $fmt($forecast['lowest_committed_cents']) }}</p>
            <p class="mt-0.5 text-xs text-muted-foreground">{{ __('committed (open invoices & bills)') }}</p>
        </div>
        <div class="rounded-xl border border-border px-4 py-3" data-test="forecast-runrate">
            <p class="text-xs text-muted-foreground">{{ __('Recent run-rate') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $fmt(abs($runrateDaily) * 30) }}<span class="text-sm font-normal text-muted-foreground">/{{ __('mo') }}</span></p>
            <p class="mt-0.5 text-xs text-muted-foreground">{{ $runrateDaily >= 0 ? __('net cash generated') : __('net cash burned') }}</p>
        </div>
    </div>

    <x-reports.chart-panel
        :charts="$this->charts"
        :title="__('Cash flow forecast')"
        :period="$horizonLabel"
        :heading="__('Forecast chart')"
        :open="true"
        class="mb-6"
    />

    <div class="overflow-x-auto rounded-xl border border-border">
        <table class="w-full text-sm" data-test="forecast-table">
            <thead>
                <tr class="border-b border-border text-left text-xs text-muted-foreground">
                    <th class="px-4 py-2.5 font-medium">{{ __('Period') }}</th>
                    <th class="px-4 py-2.5 text-right font-medium">{{ __('Expected in') }}</th>
                    <th class="px-4 py-2.5 text-right font-medium">{{ __('Expected out') }}</th>
                    <th class="px-4 py-2.5 text-right font-medium">{{ __('Committed balance') }}</th>
                    <th class="px-4 py-2.5 text-right font-medium">{{ __('With run-rate') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($forecast['periods'] as $period)
                    <tr class="border-b border-border/60 last:border-0">
                        <td class="px-4 py-2.5">{{ $period['label'] }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-emerald-700 dark:text-emerald-400">{{ $period['scheduled_in_cents'] !== 0 ? $fmt($period['scheduled_in_cents']) : '—' }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ $period['scheduled_out_cents'] !== 0 ? '('.$fmt($period['scheduled_out_cents']).')' : '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-medium tabular-nums {{ $period['below_floor'] ? 'text-red-600 dark:text-red-400' : '' }}">{{ $fmt($period['committed_closing_cents']) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ $fmt($period['projected_closing_cents']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-xs text-muted-foreground">
        {{ __('“Committed balance” counts only open invoices and bills by their due dates — the high-confidence view that drives the low-cash alert. “With run-rate” adds an estimate of ongoing operations from your recent net operating cash, so it already reflects recurring activity.') }}
    </p>
</section>
