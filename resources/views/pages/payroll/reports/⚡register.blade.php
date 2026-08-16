<?php

use App\Models\Company;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PayrollRegisterCalculator;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\XlsxExporter;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Payroll register')] class extends Component {
    public Company $company;

    #[Url(as: 'from')]
    public string $startDate = '';

    #[Url(as: 'to')]
    public string $endDate = '';

    public function mount(Company $company): void
    {
        abort_unless($company->usesPayroll(), 404);

        $this->company = $company;

        if ($this->startDate === '' || $this->endDate === '') {
            $today = $company->currentDateTime();
            $this->startDate = $today->startOfMonth()->toDateString();
            $this->endDate = $today->endOfMonth()->toDateString();
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(): array
    {
        return [CarbonImmutable::parse($this->startDate)->startOfDay(), CarbonImmutable::parse($this->endDate)->endOfDay()];
    }

    #[Computed]
    public function rows(): array
    {
        [$start, $end] = $this->range();

        return app(PayrollRegisterCalculator::class)->rows($this->company, $start, $end);
    }

    #[Computed]
    public function summary(): array
    {
        [$start, $end] = $this->range();

        return app(PayrollRegisterCalculator::class)->summary($this->company, $start, $end);
    }

    public function money(int $cents): string
    {
        return Money::fromCents($cents)->format();
    }

    private function filename(string $ext): string
    {
        return 'payroll-register-'.$this->startDate.'-to-'.$this->endDate.'.'.$ext;
    }

    public function exportCsv()
    {
        $rows = collect($this->rows)->map(fn ($r) => [
            $r['name'], $r['run_no'], $r['pay_date'],
            CsvExporter::cents($r['gross_cents']), CsvExporter::cents($r['cpp_cents']),
            CsvExporter::cents($r['ei_cents']), CsvExporter::cents($r['tax_cents']),
            CsvExporter::cents($r['deductions_cents']), CsvExporter::cents($r['employer_cents']),
            CsvExporter::cents($r['net_cents']),
        ]);

        $s = $this->summary;
        $rows->push(['TOTAL', '', '',
            CsvExporter::cents($s['gross_cents']), CsvExporter::cents($s['cpp_cents']),
            CsvExporter::cents($s['ei_cents']), CsvExporter::cents($s['tax_cents']),
            CsvExporter::cents($s['deductions_cents']), CsvExporter::cents($s['employer_cents']),
            CsvExporter::cents($s['net_cents'])]);

        return app(CsvExporter::class)->stream(
            $this->filename('csv'),
            ['Employee', 'Run #', 'Pay date', 'Gross', 'CPP/QPP', 'EI/QPIP', 'Income tax', 'Other deductions', 'Employer cost', 'Net'],
            $rows,
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.payroll-register', [
            'company' => $this->company,
            'rows' => $this->rows,
            'summary' => $this->summary,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
        ], $this->filename('pdf'));
    }

    public function exportXlsx()
    {
        return app(XlsxExporter::class)->payrollRegister($this->filename('xlsx'), $this->company, $this->rows, $this->startDate, $this->endDate);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Payroll register') }}</flux:heading>
            <flux:subheading>{{ __('Per-employee earnings, deductions, employer cost and net for posted pay runs in a date range.') }}</flux:subheading>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <flux:input type="date" wire:model.live="startDate" :label="__('From')" />
            <flux:input type="date" wire:model.live="endDate" :label="__('To')" />
            <flux:dropdown align="end">
                <flux:button variant="primary" icon="arrow-down-tray" icon:trailing="chevron-down" :disabled="empty($this->rows)">{{ __('Download') }}</flux:button>
                <flux:menu>
                    <flux:menu.item icon="document-text" wire:click="exportCsv">{{ __('CSV') }}</flux:menu.item>
                    <flux:menu.item icon="document" wire:click="exportPdf">{{ __('PDF') }}</flux:menu.item>
                    <flux:menu.item icon="table-cells" wire:click="exportXlsx">{{ __('Excel') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    @php($s = $this->summary)
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-lg border border-border p-4">
            <div class="text-sm text-muted-foreground">{{ __('Gross') }}</div>
            <div class="font-mono text-lg font-semibold">{{ $this->money($s['gross_cents']) }}</div>
        </div>
        <div class="rounded-lg border border-border p-4">
            <div class="text-sm text-muted-foreground">{{ __('Net pay') }}</div>
            <div class="font-mono text-lg font-semibold">{{ $this->money($s['net_cents']) }}</div>
        </div>
        <div class="rounded-lg border border-border p-4">
            <div class="text-sm text-muted-foreground">{{ __('Employer cost') }}</div>
            <div class="font-mono text-lg font-semibold">{{ $this->money($s['employer_cents']) }}</div>
        </div>
        <div class="rounded-lg border border-border p-4">
            <div class="text-sm text-muted-foreground">{{ __('Cheques') }}</div>
            <div class="text-xl font-semibold">{{ $s['line_count'] }}</div>
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-3 py-2 text-left">{{ __('Employee') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Run #') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Pay date') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Gross') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('CPP/QPP') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('EI/QPIP') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Tax') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Other ded.') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Employer') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Net') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->rows as $row)
                    <tr>
                        <td class="px-3 py-2 font-medium">{{ $row['name'] }}</td>
                        <td class="px-3 py-2 font-mono">{{ $row['run_no'] }}</td>
                        <td class="px-3 py-2">{{ $row['pay_date'] }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($row['gross_cents']) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($row['cpp_cents']) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($row['ei_cents']) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($row['tax_cents']) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($row['deductions_cents']) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($row['employer_cents']) }}</td>
                        <td class="px-3 py-2 text-right font-mono font-semibold">{{ $this->money($row['net_cents']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-3 py-8 text-center text-muted-foreground">{{ __('No posted pay runs in this period.') }}</td></tr>
                @endforelse
            </tbody>
            @if (! empty($this->rows))
                <tfoot class="bg-muted/50 font-semibold">
                    <tr>
                        <td class="px-3 py-2" colspan="3">{{ __('Total') }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($s['gross_cents']) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($s['cpp_cents']) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($s['ei_cents']) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($s['tax_cents']) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($s['deductions_cents']) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($s['employer_cents']) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($s['net_cents']) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</section>
