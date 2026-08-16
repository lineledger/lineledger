<?php

use App\Actions\Payroll\FinalizeSlipFiling;
use App\Actions\Payroll\UnlockSlipFiling;
use App\Enums\SlipType;
use App\Models\Company;
use App\Models\PayrollSlipFiling;
use App\Services\Pdf\PdfMerger;
use App\Services\Pdf\Slips\SlipFieldMaps;
use App\Services\Pdf\Slips\SlipTemplateRegistry;
use App\Services\Pdf\Slips\T4SlipPdfAdapter;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\T4SlipCalculator;
use App\Services\Reporting\T4XmlGenerator;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('T4 slips')] class extends Component {
    public Company $company;

    #[Url(as: 'year')]
    public int $year = 0;

    public function mount(Company $company): void
    {
        abort_unless($company->supports(\App\Enums\JurisdictionCapability::T4Slips), 404);

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
            ->where('slip_type', SlipType::T4->value)
            ->where('year', $this->year)
            ->first();
    }

    #[Computed]
    public function slips(): array
    {
        if ($this->filing !== null) {
            return $this->filing->lines->map(fn ($line) => $line->data)->sortBy('name')->values()->all();
        }

        return app(T4SlipCalculator::class)->slipsForYear($this->company, $this->year);
    }

    #[Computed]
    public function summary(): array
    {
        return $this->filing?->summary
            ?? app(T4SlipCalculator::class)->summary($this->company, $this->year);
    }

    public function finalize(): void
    {
        app(FinalizeSlipFiling::class)->handle($this->company, SlipType::T4, $this->year);

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

    /** Whether the official CRA template + field map are available this year. */
    #[Computed]
    public function officialTemplate(): bool
    {
        return app(SlipTemplateRegistry::class)->installed(SlipTemplateRegistry::T4, $this->year)
            && SlipFieldMaps::for(SlipTemplateRegistry::T4, $this->year) !== null;
    }

    public function printSlip(int $contactId)
    {
        $slip = collect($this->slips)->firstWhere('contact_id', $contactId);

        abort_if($slip === null, 404);

        // Official CRA template first; the labelled facsimile only when no
        // template/map is installed for the year.
        $official = app(T4SlipPdfAdapter::class)->render($this->company, $slip, $this->year);

        if ($official !== null) {
            return app(PdfExporter::class)->downloadRaw($official, 't4-'.$contactId.'-'.$this->year.'.pdf');
        }

        return app(PdfExporter::class)->download('pdf.reports.t4-slip', [
            'company' => $this->company,
            'slip' => $slip,
            'year' => $this->year,
            'facsimile' => true,
        ], 't4-'.$contactId.'-'.$this->year.'.pdf');
    }

    /** Every employee's slip in one PDF — official template when installed. */
    public function printAllSlips()
    {
        abort_if($this->slips === [], 404);

        $adapter = app(T4SlipPdfAdapter::class);
        $exporter = app(PdfExporter::class);

        $documents = collect($this->slips)->map(fn (array $slip): string => $adapter->render($this->company, $slip, $this->year)
            ?? $exporter->raw('pdf.reports.t4-slip', [
                'company' => $this->company,
                'slip' => $slip,
                'year' => $this->year,
                'facsimile' => true,
            ]))->all();

        return $exporter->downloadRaw(app(PdfMerger::class)->merge(...$documents), 't4-slips-'.$this->year.'.pdf');
    }

    public function exportSummaryPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.t4-summary', [
            'company' => $this->company,
            'summary' => $this->summary,
            'slips' => $this->slips,
            'year' => $this->year,
        ], 't4-summary-'.$this->year.'.pdf');
    }

    public function exportXml()
    {
        $xml = app(T4XmlGenerator::class)->generate($this->company, $this->year, $this->slips, $this->summary);

        return response()->streamDownload(
            fn () => print($xml),
            't4-'.$this->year.'.xml',
            ['Content-Type' => 'application/xml'],
        );
    }

    public function exportCsv()
    {
        $rows = collect($this->slips)->map(fn ($s) => [
            $s['name'], $s['sin_last4'] ? '•••• '.$s['sin_last4'] : '',
            CsvExporter::cents($s['box14']), CsvExporter::cents($s['box16']), CsvExporter::cents($s['box16a']),
            CsvExporter::cents($s['box18']), CsvExporter::cents($s['box22']), CsvExporter::cents($s['box24']), CsvExporter::cents($s['box26']),
        ]);

        return app(CsvExporter::class)->stream(
            't4-slips-'.$this->year.'.csv',
            ['Employee', 'SIN', 'Box 14', 'Box 16', 'Box 16A', 'Box 18', 'Box 22', 'Box 24', 'Box 26'],
            $rows,
        );
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('T4 slips') }}</flux:heading>
            <flux:subheading>{{ __('Year-end employment income and deductions, one slip per employee.') }}</flux:subheading>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <flux:input type="number" wire:model.live="year" :label="__('Tax year')" class="max-w-[120px]" />
            @if ($this->filing)
                <flux:badge color="green" data-test="t4-finalized-badge">{{ __('Finalized') }}</flux:badge>
                <flux:button
                    variant="ghost"
                    icon="lock-open"
                    wire:click="unlock"
                    wire:confirm="{{ __('Unlock the :year T4 slips? They will revert to draft, disappear from the employee portal, and recompute from posted payroll.', ['year' => $this->year]) }}"
                    data-test="t4-unlock-button"
                >
                    {{ __('Unlock') }}
                </flux:button>
            @else
                <flux:badge color="zinc" data-test="t4-draft-badge">{{ __('Draft') }}</flux:badge>
                <flux:button
                    variant="ghost"
                    icon="lock-closed"
                    wire:click="finalize"
                    wire:confirm="{{ __('Finalize the :year T4 slips? This locks the amounts as issued and publishes the slips to the employee portal. You can unlock later to amend.', ['year' => $this->year]) }}"
                    data-test="t4-finalize-button"
                >
                    {{ __('Finalize :year', ['year' => $this->year]) }}
                </flux:button>
            @endif
            <flux:dropdown align="end">
                <flux:button variant="primary" icon="arrow-down-tray" icon:trailing="chevron-down" :disabled="empty($this->slips)">{{ __('Download') }}</flux:button>
                <flux:menu>
                    <flux:menu.item icon="document-duplicate" wire:click="printAllSlips" data-test="t4-all-slips">{{ __('All slips (PDF)') }}</flux:menu.item>
                    <flux:menu.item icon="document-text" wire:click="exportCsv">{{ __('Slips CSV') }}</flux:menu.item>
                    <flux:menu.item icon="document" wire:click="exportSummaryPdf">{{ __('T4 Summary PDF') }}</flux:menu.item>
                    <flux:menu.item icon="code-bracket" wire:click="exportXml">{{ __('CRA XML (e-file)') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    @if ($this->officialTemplate)
        <div class="mb-4 rounded-lg border border-border bg-muted/50 px-4 py-3 text-sm text-muted-foreground" data-test="t4-template-official">
            {{ __('Slip PDFs print on the official CRA :year T4 form (employee copies, 2 per page).', ['year' => $this->year]) }}
        </div>
    @else
        <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm dark:border-amber-700 dark:bg-amber-950/40" data-test="t4-template-missing">
            {{ __('No official CRA template is installed for :year — slips print as a clearly-labelled facsimile. Install a flattened copy of the official fillable T4 at storage/app/slip-templates/:year/t4.pdf (see config/payroll.php).', ['year' => $this->year]) }}
        </div>
    @endif

    @if ($this->filing)
        <div class="mb-4 rounded-lg border border-border bg-muted/50 px-4 py-3 text-sm text-muted-foreground" data-test="t4-finalized-note">
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
            <div class="text-sm text-muted-foreground">{{ __('Box 14 income') }}</div>
            <div class="font-mono text-lg font-semibold">{{ $this->money($s['box14']) }}</div>
        </div>
        <div class="rounded-lg border border-border p-4">
            <div class="text-sm text-muted-foreground">{{ __('Box 22 tax') }}</div>
            <div class="font-mono text-lg font-semibold">{{ $this->money($s['box22']) }}</div>
        </div>
        <div class="rounded-lg border border-border p-4">
            <div class="text-sm text-muted-foreground">{{ __('CPP + EI (Box 16/18)') }}</div>
            <div class="font-mono text-lg font-semibold">{{ $this->money($s['box16'] + $s['box16a'] + $s['box18']) }}</div>
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-3 py-2 text-left">{{ __('Employee') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('14 Income') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('16 CPP') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('18 EI') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('22 Tax') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('24 EI ins.') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('26 CPP pens.') }}</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->slips as $slip)
                    <tr>
                        <td class="px-3 py-2 font-medium">{{ $slip['name'] }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($slip['box14']) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($slip['box16'] + $slip['box16a']) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($slip['box18']) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($slip['box22']) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($slip['box24']) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($slip['box26']) }}</td>
                        <td class="px-3 py-2 text-right">
                            <flux:button size="xs" variant="ghost" icon="printer" wire:click="printSlip({{ $slip['contact_id'] }})">{{ __('T4 PDF') }}</flux:button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-3 py-8 text-center text-muted-foreground">{{ __('No posted payroll for :year.', ['year' => $this->year]) }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 text-sm text-muted-foreground">
        <p>{{ __('Box totals come from posted pay runs in the calendar year. File the slips and T4 Summary with the CRA — download the CRA XML (e-file) from the Download menu for Internet File Transfer, or enter these figures on the CRA portal.') }}</p>
    </div>
</section>
