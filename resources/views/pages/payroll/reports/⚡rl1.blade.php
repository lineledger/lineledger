<?php

use App\Actions\Payroll\FinalizeSlipFiling;
use App\Actions\Payroll\UnlockSlipFiling;
use App\Enums\SlipType;
use App\Models\Company;
use App\Models\PayrollSlipFiling;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\Rl1SlipCalculator;
use App\Services\Reporting\Rl1XmlGenerator;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('RL-1 slips')] class extends Component {
    public Company $company;

    #[Url(as: 'year')]
    public int $year = 0;

    public function mount(Company $company): void
    {
        abort_unless($company->usesPayroll(), 404);

        $this->company = $company;

        if ($this->year === 0) {
            $this->year = (int) $company->currentDateTime()->subYear()->year;
        }
    }

    /**
     * The finalized filing for this year, if any. Its existence is the lock:
     * when present, the table, summary cards and exports all read the snapshot.
     */
    #[Computed]
    public function filing(): ?PayrollSlipFiling
    {
        return PayrollSlipFiling::query()
            ->where('company_id', $this->company->id)
            ->where('slip_type', SlipType::Rl1->value)
            ->where('year', $this->year)
            ->first();
    }

    #[Computed]
    public function slips(): array
    {
        if ($this->filing !== null) {
            return $this->filing->lines->map(fn ($line) => $line->data)->sortBy('name')->values()->all();
        }

        return app(Rl1SlipCalculator::class)->slipsForYear($this->company, $this->year);
    }

    #[Computed]
    public function summary(): array
    {
        return $this->filing?->summary
            ?? app(Rl1SlipCalculator::class)->summary($this->company, $this->year);
    }

    public function finalize(): void
    {
        app(FinalizeSlipFiling::class)->handle($this->company, SlipType::Rl1, $this->year);

        $this->resetSnapshot();
    }

    public function unlock(): void
    {
        $filing = $this->filing;

        if ($filing !== null) {
            app(UnlockSlipFiling::class)->handle($filing);
        }

        $this->resetSnapshot();
    }

    private function resetSnapshot(): void
    {
        unset($this->filing, $this->slips, $this->summary);
    }

    public function money(int $cents): string
    {
        return Money::fromCents($cents)->format();
    }

    public function printSlip(int $contactId)
    {
        $slip = collect($this->slips)->firstWhere('contact_id', $contactId);

        abort_if($slip === null, 404);

        return app(PdfExporter::class)->download('pdf.reports.rl1-slip', [
            'company' => $this->company,
            'slip' => $slip,
            'year' => $this->year,
        ], 'rl1-'.$contactId.'-'.$this->year.'.pdf');
    }

    public function exportSummaryPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.rl1-summary', [
            'company' => $this->company,
            'summary' => $this->summary,
            'slips' => $this->slips,
            'year' => $this->year,
        ], 'rl1-summary-'.$this->year.'.pdf');
    }

    public function exportXml()
    {
        $xml = app(Rl1XmlGenerator::class)->generate($this->company, $this->year, $this->slips, $this->summary);

        return response()->streamDownload(
            fn () => print($xml),
            'rl1-'.$this->year.'.xml',
            ['Content-Type' => 'application/xml'],
        );
    }

    public function exportCsv()
    {
        $rows = collect($this->slips)->map(fn ($s) => [
            $s['name'], $s['sin_last4'] ? '•••• '.$s['sin_last4'] : '',
            CsvExporter::cents($s['boxA']), CsvExporter::cents($s['boxB']), CsvExporter::cents($s['boxE']),
            CsvExporter::cents($s['boxG']), CsvExporter::cents($s['boxH']), CsvExporter::cents($s['boxI']),
        ]);

        return app(CsvExporter::class)->stream(
            'rl1-slips-'.$this->year.'.csv',
            ['Employee', 'SIN', 'Box A', 'Box B', 'Box E', 'Box G', 'Box H', 'Box I'],
            $rows,
        );
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('RL-1 slips') }}</flux:heading>
            <flux:subheading>{{ __('Year-end Quebec employment income and deductions (Relevé 1), one slip per Quebec employee.') }}</flux:subheading>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <flux:input type="number" wire:model.live="year" :label="__('Tax year')" class="max-w-[120px]" />
            @if ($this->filing)
                <flux:badge color="green" data-test="rl1-finalized-badge">{{ __('Finalized') }}</flux:badge>
                <flux:button
                    variant="ghost"
                    icon="lock-open"
                    wire:click="unlock"
                    wire:confirm="{{ __('Unlock the :year RL-1 slips? They will revert to draft, disappear from the employee portal, and recompute from posted payroll.', ['year' => $this->year]) }}"
                    data-test="rl1-unlock-button"
                >
                    {{ __('Unlock') }}
                </flux:button>
            @else
                <flux:badge color="zinc" data-test="rl1-draft-badge">{{ __('Draft') }}</flux:badge>
                <flux:button
                    variant="ghost"
                    icon="lock-closed"
                    wire:click="finalize"
                    wire:confirm="{{ __('Finalize the :year RL-1 slips? This locks the amounts as issued and publishes the slips to the employee portal. You can unlock later to amend.', ['year' => $this->year]) }}"
                    data-test="rl1-finalize-button"
                >
                    {{ __('Finalize :year', ['year' => $this->year]) }}
                </flux:button>
            @endif
            <flux:dropdown align="end">
                <flux:button variant="primary" icon="arrow-down-tray" icon:trailing="chevron-down" :disabled="empty($this->slips)">{{ __('Download') }}</flux:button>
                <flux:menu>
                    <flux:menu.item icon="document-text" wire:click="exportCsv">{{ __('Slips CSV') }}</flux:menu.item>
                    <flux:menu.item icon="document" wire:click="exportSummaryPdf">{{ __('RL-1 Summary PDF') }}</flux:menu.item>
                    <flux:menu.item icon="code-bracket" wire:click="exportXml">{{ __('Revenu Québec XML (e-file)') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    @if ($this->filing)
        <div class="mb-4 rounded-lg border border-border bg-muted/50 px-4 py-3 text-sm text-muted-foreground" data-test="rl1-finalized-note">
            {{ __('Finalized on :date by :user. Amounts below are the locked snapshot as issued; unlock to amend.', [
                'date' => $this->filing->finalized_at->toDateString(),
                'user' => $this->filing->finalizedBy?->name ?? __('unknown'),
            ]) }}
        </div>
    @endif

    @php($s = $this->summary)
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-lg border border-border p-4">
            <div class="text-sm text-muted-foreground">{{ __('Slips') }}</div>
            <div class="text-xl font-semibold">{{ $s['slip_count'] }}</div>
        </div>
        <div class="rounded-lg border border-border p-4">
            <div class="text-sm text-muted-foreground">{{ __('Box A income') }}</div>
            <div class="font-mono text-lg font-semibold">{{ $this->money($s['boxA']) }}</div>
        </div>
        <div class="rounded-lg border border-border p-4">
            <div class="text-sm text-muted-foreground">{{ __('Box E Quebec tax') }}</div>
            <div class="font-mono text-lg font-semibold">{{ $this->money($s['boxE']) }}</div>
        </div>
        <div class="rounded-lg border border-border p-4">
            <div class="text-sm text-muted-foreground">{{ __('QPP + QPIP (Box B/H)') }}</div>
            <div class="font-mono text-lg font-semibold">{{ $this->money($s['boxB'] + $s['boxH']) }}</div>
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-3 py-2 text-left">{{ __('Employee') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('A Income') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('B QPP') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('E Quebec tax') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('G QPP pens.') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('H QPIP') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('I QPIP ins.') }}</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->slips as $slip)
                    <tr>
                        <td class="px-3 py-2 font-medium">{{ $slip['name'] }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($slip['boxA']) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($slip['boxB']) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($slip['boxE']) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($slip['boxG']) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($slip['boxH']) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($slip['boxI']) }}</td>
                        <td class="px-3 py-2 text-right">
                            <flux:button size="xs" variant="ghost" icon="printer" wire:click="printSlip({{ $slip['contact_id'] }})">{{ __('RL-1 PDF') }}</flux:button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-3 py-8 text-center text-muted-foreground">{{ __('No posted Quebec payroll for :year.', ['year' => $this->year]) }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($s['wsdrf_applicable'])
        <div class="mt-4 rounded-lg border border-primary bg-primary/5 p-5">
            <div class="text-sm font-semibold">{{ __('Workforce skills development levy (1%)') }}</div>
            <div class="mt-1 text-sm text-muted-foreground">
                {{ __('Quebec payroll :payroll · eligible training :training · contribution due to Revenu Québec', [
                    'payroll' => $this->money($s['wsdrf_payroll_cents']),
                    'training' => $this->money($s['wsdrf_training_cents']),
                ]) }}
                <span class="font-mono font-semibold text-primary">{{ $this->money($s['wsdrf_levy_cents']) }}</span>
            </div>
        </div>
    @endif

    <div class="mt-4 text-sm text-muted-foreground">
        <p>{{ __('Box totals come from posted Quebec pay runs in the calendar year. File the slips and RL-1 Summary with Revenu Québec. Validate the XML against the current Revenu Québec schema and set your transmitter number before filing.') }}</p>
    </div>
</section>
