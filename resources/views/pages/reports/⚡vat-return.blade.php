<?php

use App\Enums\JurisdictionCapability;
use App\Models\Company;
use App\Models\TaxAgency;
use App\Services\Reporting\CsvExporter;
use App\Services\Tax\VatReturnBoxes;
use App\Services\Tax\VatReturnCalculator;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('VAT Return')] class extends Component {
    public Company $company;

    #[Url(as: 'agency')]
    public ?int $agencyId = null;

    #[Url(as: 'start')]
    public string $startDate = '';

    #[Url(as: 'end')]
    public string $endDate = '';

    public function mount(Company $company): void
    {
        // The nine-box return is HMRC's — it means nothing to other jurisdictions.
        abort_unless($company->supports(JurisdictionCapability::VatReturn), 403, 'The VAT return is only available for UK companies.');

        $this->company = $company;

        // Default to the last complete VAT quarter: the one you'd be filing now.
        if ($this->startDate === '' || $this->endDate === '') {
            $previous = $this->company->currentDateTime()->startOfQuarter()->subQuarter();
            $this->startDate = $previous->toDateString();
            $this->endDate = $previous->endOfQuarter()->toDateString();
        }

        if ($this->agencyId === null || ! $this->agencies->contains('id', $this->agencyId)) {
            $this->agencyId = $this->agencies->first()?->id;
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, TaxAgency>
     */
    #[Computed]
    public function agencies(): \Illuminate\Support\Collection
    {
        return TaxAgency::query()->orderBy('name')->get();
    }

    #[Computed]
    public function agency(): ?TaxAgency
    {
        return $this->agencies->firstWhere('id', $this->agencyId);
    }

    #[Computed]
    public function boxes(): ?VatReturnBoxes
    {
        $start = $this->parsedDate($this->startDate);
        $end = $this->parsedDate($this->endDate);

        if (! $this->agency || ! $start || ! $end || $end->lt($start)) {
            return null;
        }

        return app(VatReturnCalculator::class)->forPeriod($this->agency, $start, $end);
    }

    /**
     * Snap the period to a VAT quarter ending in the given month offset from
     * the current quarter (0 = this quarter, -1 = the last one).
     */
    public function useQuarter(int $offset): void
    {
        $quarter = $this->company->currentDateTime()->startOfQuarter()->addQuarters($offset);

        $this->startDate = $quarter->toDateString();
        $this->endDate = $quarter->endOfQuarter()->toDateString();
        unset($this->boxes);
    }

    public function updated(): void
    {
        unset($this->boxes, $this->agency);
    }

    public function exportCsv()
    {
        $boxes = $this->boxes;

        if (! $boxes) {
            return null;
        }

        $rows = collect($boxes->rows())->map(fn (array $row) => [
            'Box '.$row['box'],
            $row['label'],
            $row['whole_pounds'] ? (string) intdiv($row['cents'], 100) : CsvExporter::cents($row['cents']),
        ]);

        return app(CsvExporter::class)->stream(
            "vat-return-{$this->startDate}-{$this->endDate}.csv",
            ['Box', 'Description', 'Amount ('.$this->company->currency_code.')'],
            $rows,
        );
    }

    private function parsedDate(string $value): ?CarbonImmutable
    {
        try {
            return $value !== '' ? CarbonImmutable::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}; ?>

<section class="w-full">
    @php($symbol = $company->currencySymbol())
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('VAT Return') }}</flux:heading>
            <flux:subheading>{{ __('The nine boxes of your HMRC VAT return, worked out from your books for the period.') }}</flux:subheading>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            @if ($this->agencies->count() > 1)
                <flux:select wire:model.live="agencyId" :label="__('Agency')" class="max-w-[260px]" data-test="vat-return-agency">
                    @foreach ($this->agencies as $agencyOption)
                        <flux:select.option :value="$agencyOption->id">{{ $agencyOption->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
            <flux:input type="date" wire:model.live="startDate" :label="__('Start')" class="max-w-[180px]" data-test="vat-return-start" />
            <flux:input type="date" wire:model.live="endDate" :label="__('End')" class="max-w-[180px]" data-test="vat-return-end" />
            <flux:dropdown align="end">
                <flux:button variant="filled" icon="calendar" icon:trailing="chevron-down">{{ __('Quarter') }}</flux:button>
                <flux:menu>
                    <flux:menu.item wire:click="useQuarter(-1)" data-test="vat-return-last-quarter">{{ __('Last quarter') }}</flux:menu.item>
                    <flux:menu.item wire:click="useQuarter(0)" data-test="vat-return-this-quarter">{{ __('This quarter') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
            <flux:button variant="primary" icon="arrow-down-tray" wire:click="exportCsv" data-test="vat-return-csv">{{ __('CSV') }}</flux:button>
        </div>
    </div>

    @if (! $this->agency)
        <flux:callout icon="information-circle" variant="secondary" data-test="vat-return-no-agency">
            <flux:callout.text>{{ __('Set up HM Revenue & Customs as a tax agency, with your VAT codes, to see your VAT return.') }}</flux:callout.text>
        </flux:callout>
    @elseif (! $this->boxes)
        <flux:callout icon="exclamation-triangle" variant="warning" data-test="vat-return-bad-period">
            <flux:callout.text>{{ __('Choose a period whose end is on or after its start.') }}</flux:callout.text>
        </flux:callout>
    @else
        @php($boxes = $this->boxes)
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="w-20 px-4 py-2 text-left">{{ __('Box') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Description') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($boxes->rows() as $row)
                        <tr @class(['bg-muted/40 font-semibold' => in_array($row['box'], [3, 5], true)]) data-test="vat-box-{{ $row['box'] }}">
                            <td class="px-4 py-2 font-mono">{{ $row['box'] }}</td>
                            <td class="px-4 py-2">{{ __($row['label']) }}</td>
                            <td class="px-4 py-2 text-right font-mono" data-test="vat-box-{{ $row['box'] }}-amount">
                                {{ $symbol }}{{ $row['whole_pounds'] ? number_format(intdiv($row['cents'], 100)) : number_format($row['cents'] / 100, 2) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4 space-y-2 text-sm text-muted-foreground">
            <p data-test="vat-return-summary">
                @if ($boxes->isRepayment())
                    {{ __('HMRC owes you :amount for this period.', ['amount' => $symbol.number_format($boxes->box5Absolute() / 100, 2)]) }}
                @else
                    {{ __('You owe HMRC :amount for this period.', ['amount' => $symbol.number_format($boxes->box5Absolute() / 100, 2)]) }}
                @endif
            </p>
            <p>{{ __('Boxes 6 and 7 count every sale and purchase coded to a VAT rate — standard, reduced, zero-rated or exempt — at its value before VAT, in whole pounds. Lines with no tax code are treated as outside the scope of VAT and left out.') }}</p>
            <p>{{ __('Boxes 2, 8 and 9 only apply to Northern Ireland businesses trading goods with the EU and are shown as zero; adjust them on HMRC’s form if they apply to you.') }}</p>
            <p>
                {{ __('To review the transactions behind Boxes 1 and 4, leave some off, or record adjustments and file, start a tax return for the period.') }}
                <flux:link :href="route('tax-returns.index', $company)" wire:navigate>{{ __('Tax returns') }}</flux:link>
                ·
                <flux:link :href="route('reports.sales-tax', ['company' => $company, 'start' => $startDate, 'end' => $endDate])" wire:navigate>{{ __('Sales Tax report') }}</flux:link>
            </p>
        </div>
    @endif
</section>
