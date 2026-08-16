<?php

use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportDateRange;
use App\Concerns\Memorizable;
use App\Enums\CcaClass;
use App\Models\Company;
use App\Models\CcaPool;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\GifiStatementBuilder;
use App\Services\Reporting\PdfExporter;
use App\Services\Tax\CcaCalculator;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('T2125 Business Activities')] class extends Component {
    use HasCustomReportHeader;
    use HasReportDateRange;
    use Memorizable;

    public Company $company;

    /** @var array<string, string> opening UCC per class, in dollars, for the input fields */
    public array $openingDollars = [];

    public function mount(Company $company): void
    {
        abort_unless($company->filesT2125(), 404);

        $this->company = $company;
        $this->initReportDateRange('this_fiscal_year');
        $this->applyMemorized((int) request('memorized'));
        $this->hydrateOpeningUcc();
    }

    protected function reportKey(): string
    {
        return 'reports.t2125';
    }

    public function taxYear(): int
    {
        return CarbonImmutable::parse($this->endDate)->year;
    }

    private function hydrateOpeningUcc(): void
    {
        $pools = CcaPool::query()
            ->where('company_id', $this->company->id)
            ->where('tax_year', $this->taxYear())
            ->get();

        $this->openingDollars = [];
        foreach ($pools as $pool) {
            $this->openingDollars[$pool->cca_class->value] = number_format($pool->opening_ucc_cents / 100, 2, '.', '');
        }
    }

    #[Computed]
    public function report(): array
    {
        return app(GifiStatementBuilder::class)->build(
            $this->company,
            CarbonImmutable::parse($this->startDate),
            CarbonImmutable::parse($this->endDate),
        );
    }

    #[Computed]
    public function cca(): array
    {
        $schedule = app(CcaCalculator::class)->schedule($this->company, $this->taxYear());

        // Re-key by class for easy lookup in the all-classes worksheet.
        $schedule['byClass'] = collect($schedule['rows'])->keyBy('class')->all();

        return $schedule;
    }

    public function saveOpeningUcc(string $class): void
    {
        $ccaClass = CcaClass::tryFrom($class);
        if ($ccaClass === null) {
            return;
        }

        $cents = (int) round(((float) ($this->openingDollars[$class] ?? 0)) * 100);

        CcaPool::query()->updateOrCreate(
            ['company_id' => $this->company->id, 'tax_year' => $this->taxYear(), 'cca_class' => $ccaClass->value],
            ['opening_ucc_cents' => $cents],
        );

        unset($this->cca);

        Flux::toast(variant: 'success', text: __('Opening UCC saved.'));
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: int}>
     */
    private function exportRows(): array
    {
        $r = $this->report;
        $rows = [];

        foreach ($r['is']['halves'] as $half) {
            foreach ($half['sections'] as $section) {
                foreach ($section['lines'] as $line) {
                    $rows[] = ['Income & expenses', $line['code'], $line['label'], $line['amount']];
                }
            }
        }
        $rows[] = ['Income & expenses', '', 'Net income before CCA', $r['is']['net_income']];

        foreach ($this->cca['rows'] as $row) {
            $rows[] = ['CCA', $row['class'], $row['label'], $row['cca_cents']];
        }
        $rows[] = ['CCA', '', 'Total CCA', $this->cca['total_cca_cents']];

        foreach ($r['bs']['halves'] as $half) {
            foreach ($half['sections'] as $section) {
                foreach ($section['lines'] as $line) {
                    $rows[] = ['Balance sheet', $line['code'], $line['label'], $line['amount']];
                }
            }
        }

        return $rows;
    }

    public function exportCsv()
    {
        return app(CsvExporter::class)->stream(
            "t2125-{$this->taxYear()}.csv",
            ['Section', 'Code', 'Description', 'Amount'],
            collect($this->exportRows())->map(fn (array $r) => [$r[0], $r[1], $r[2], CsvExporter::cents($r[3])]),
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.t2125', [
            'company' => $this->company,
            'report' => $this->report,
            'cca' => $this->cca,
            'netAfterCca' => $this->report['is']['net_income'] - $this->cca['total_cca_cents'],
            'taxYear' => $this->taxYear(),
            'title' => $this->effectiveTitle('T2125 Business Activities'),
        ], "t2125-{$this->taxYear()}.pdf");
    }
}; ?>

<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('T2125 Business Activities'))"
        :subtitle="$company->name.' · '.__('Statement of Business or Professional Activities')"
        mode="range"
        :title-editable="true"
        :memorizable="true"
        :exports="['csv', 'pdf']"
    />

    @include('partials.reports.gifi-statement-readonly', [
        'report' => $this->report,
        'bsHeading' => __('Part 5 — Balance sheet'),
        'isHeading' => __('Parts 3 & 4 — Income and expenses'),
    ])

    {{-- ───────────────── Part 7 — Capital cost allowance (Area A) ───────────────── --}}
    <div class="mb-8">
        <flux:heading size="lg" class="mb-1">{{ __('Part 7 — Capital cost allowance (Area A)') }}</flux:heading>
        <flux:subheading class="mb-3">{{ __('Enter the opening UCC for each class. Additions come from your asset register for :year.', ['year' => $this->taxYear()]) }}</flux:subheading>

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm" data-test="t2125-cca">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium text-muted-foreground">{{ __('Class') }}</th>
                        <th class="px-3 py-2 text-right font-medium text-muted-foreground">{{ __('Rate') }}</th>
                        <th class="px-3 py-2 text-right font-medium text-muted-foreground">{{ __('Opening UCC') }}</th>
                        <th class="px-3 py-2 text-right font-medium text-muted-foreground">{{ __('Additions') }}</th>
                        <th class="px-3 py-2 text-right font-medium text-muted-foreground">{{ __('CCA') }}</th>
                        <th class="px-3 py-2 text-right font-medium text-muted-foreground">{{ __('Closing UCC') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach (\App\Enums\CcaClass::cases() as $class)
                        @php($row = $this->cca['byClass'][$class->value] ?? null)
                        <tr wire:key="cca-{{ $class->value }}" data-test="t2125-cca-row">
                            <td class="px-3 py-2">{{ $class->label() }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ number_format($class->rate() * 100, 0) }}%</td>
                            <td class="px-3 py-2 text-right">
                                <flux:input
                                    type="number" step="0.01" min="0"
                                    wire:model="openingDollars.{{ $class->value }}"
                                    wire:change="saveOpeningUcc('{{ $class->value }}')"
                                    class="max-w-32 text-right"
                                    data-test="t2125-opening-ucc"
                                />
                            </td>
                            <td class="px-3 py-2 text-right font-mono">{{ number_format(($row['additions_cents'] ?? 0) / 100, 2) }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ number_format(($row['cca_cents'] ?? 0) / 100, 2) }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ number_format(($row['closing_cents'] ?? 0) / 100, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-muted">
                    <tr class="font-semibold">
                        <td class="px-3 py-2" colspan="4">{{ __('Total CCA') }}</td>
                        <td class="px-3 py-2 text-right font-mono" data-test="t2125-total-cca">{{ number_format($this->cca['total_cca_cents'] / 100, 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="mt-4 flex justify-end">
            <div class="rounded-lg border border-border bg-muted/40 px-4 py-3 text-right">
                <div class="text-xs uppercase tracking-wide text-muted-foreground">{{ __('Net income after CCA') }}</div>
                <div class="font-mono text-lg font-semibold" data-test="t2125-net-after-cca">
                    {{ number_format(($this->report['is']['net_income'] - $this->cca['total_cca_cents']) / 100, 2) }}
                </div>
            </div>
        </div>
    </div>
</section>
