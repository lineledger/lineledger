<?php

use App\Concerns\HasCustomReportHeader;
use App\Concerns\HasReportDateRange;
use App\Concerns\Memorizable;
use App\Enums\AccountSubtype;
use App\Enums\GifiStatement;
use App\Models\Account;
use App\Models\Company;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\GifiStatementBuilder;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\XlsxExporter;
use App\Support\Gifi\GifiCatalog;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('GIFI Statement')] class extends Component {
    use HasCustomReportHeader;
    use HasReportDateRange;
    use Memorizable;

    public Company $company;

    public function mount(Company $company): void
    {
        // GIFI is a CRA construct — only Canadian companies file it.
        abort_unless($company->usesGifi(), 404);

        $this->company = $company;
        $this->initReportDateRange('this_fiscal_year');
        $this->applyMemorized((int) request('memorized'));
    }

    protected function reportKey(): string
    {
        return 'reports.gifi';
    }

    /**
     * GIFI lines grouped into the Schedule 100 balance sheet (cumulative as-of the
     * period end) and the Schedule 125 income statement (activity over the period).
     * Each line carries its member accounts so they can be reassigned inline.
     *
     * Balancing mirrors the Balance Sheet report: prior-year net income folds into
     * the retained-earnings line and the current year's net income is added to
     * equity, so Total Assets = Total Liabilities + Equity.
     *
     * @return array{
     *   bs: array{halves: array<string, array{label: string, sections: list<array<string, mixed>>, total: int}>, total_assets: int, total_le: int, balanced: bool},
     *   is: array{halves: array<string, array{label: string, sections: list<array<string, mixed>>, total: int}>, net_income: int},
     *   unassigned: array{lines: list<array{id: int, code: string, name: string, amount: int}>, total: int}
     * }
     */
    #[Computed]
    public function report(): array
    {
        return app(GifiStatementBuilder::class)->build(
            $this->company,
            CarbonImmutable::parse($this->startDate),
            CarbonImmutable::parse($this->endDate),
        );
    }

    /**
     * Move an account onto a different GIFI line (or clear it). Driven from the
     * report so the mapping can be tuned without leaving the statement.
     */
    public function reassign(int $accountId, ?string $gifiCode): void
    {
        $account = Account::withoutGlobalScopes()->findOrFail($accountId);
        abort_unless($account->company_id === $this->company->id, 403);

        $gifiCode = $gifiCode === '' ? null : $gifiCode;

        if ($gifiCode !== null && GifiCatalog::find($gifiCode) === null) {
            return;
        }

        $account->update(['gifi_code' => $gifiCode]);

        unset($this->report);

        Flux::toast(variant: 'success', text: __('GIFI line updated.'));
    }

    /**
     * Grouped options for the inline reassignment selects.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    public function gifiOptions(): array
    {
        return GifiCatalog::options();
    }

    /**
     * Flat rows for the file exports: [schedule, section, code, description, amount].
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: int}>
     */
    private function exportRows(): array
    {
        $r = $this->report;
        $rows = [];

        foreach ($r['bs']['halves'] as $half) {
            foreach ($half['sections'] as $section) {
                foreach ($section['lines'] as $line) {
                    $rows[] = ['Balance Sheet', $section['label'], $line['code'], $line['label'], $line['amount']];
                }
            }
        }

        if ($r['bs']['halves']['equity']['net_income'] !== 0) {
            $rows[] = ['Balance Sheet', 'Shareholder equity', '', 'Net income for the year', $r['bs']['halves']['equity']['net_income']];
        }

        foreach ($r['is']['halves'] as $half) {
            foreach ($half['sections'] as $section) {
                foreach ($section['lines'] as $line) {
                    $rows[] = ['Income Statement', $section['label'], $line['code'], $line['label'], $line['amount']];
                }
            }
        }

        $rows[] = ['Income Statement', '', '', 'Net income', $r['is']['net_income']];

        foreach ($r['unassigned']['lines'] as $line) {
            $rows[] = ['Unassigned', '', $line['code'], $line['name'], $line['amount']];
        }

        return $rows;
    }

    public function exportCsv()
    {
        return app(CsvExporter::class)->stream(
            "gifi-statement-{$this->startDate}-{$this->endDate}.csv",
            ['Schedule', 'Section', 'GIFI', 'Description', 'Amount'],
            collect($this->exportRows())->map(fn (array $row) => [
                $row[0], $row[1], $row[2], $row[3], CsvExporter::cents($row[4]),
            ]),
        );
    }

    public function exportXlsx()
    {
        return app(XlsxExporter::class)->gifi(
            "gifi-statement-{$this->startDate}-{$this->endDate}.xlsx",
            $this->company,
            $this->exportRows(),
            $this->startDate,
            $this->endDate,
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.gifi', [
            'company' => $this->company,
            'report' => $this->report,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'title' => $this->effectiveTitle('GIFI Statement'),
        ], "gifi-statement-{$this->startDate}-{$this->endDate}.pdf");
    }
}; ?>

<section class="w-full">
    <x-reports.control-bar
        :title="$this->effectiveTitle(__('GIFI Statement'))"
        :subtitle="$company->name.' · '.__('T2 — GIFI Schedule 100 / 125')"
        mode="range"
        :title-editable="true"
        :memorizable="true"
    />

    @php $r = $this->report; $options = $this->gifiOptions(); @endphp

    @php
        // One GIFI line row with an expandable list of member accounts, each of
        // which can be moved to another line. $half scopes the reassignment.
        $renderLine = function (array $line) use ($options) {
            return view('partials.reports.gifi-line', ['line' => $line, 'options' => $options])->render();
        };
    @endphp

    {{-- ───────────────── Schedule 100 — Balance Sheet ───────────────── --}}
    <div class="mb-8">
        <flux:heading size="lg" class="mb-3">{{ __('Schedule 100 — Balance Sheet') }}</flux:heading>

        <div class="overflow-hidden rounded-lg border border-border">
            <table class="w-full text-sm" data-test="gifi-bs">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium text-muted-foreground">{{ __('GIFI') }}</th>
                        <th class="px-4 py-2 text-left font-medium text-muted-foreground">{{ __('Description') }}</th>
                        <th class="px-4 py-2 text-right font-medium text-muted-foreground">{{ __('Amount') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($r['bs']['halves'] as $halfKey => $half)
                        @if ($half['sections'] !== [] || ($halfKey === 'equity' && $half['net_income'] !== 0))
                            <tr class="bg-muted/50"><td colspan="3" class="px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ $half['label'] }}</td></tr>
                            @foreach ($half['sections'] as $section)
                                <tr><td colspan="3" class="px-4 pt-2 text-xs font-medium text-muted-foreground">{{ $section['label'] }}</td></tr>
                                @foreach ($section['lines'] as $line)
                                    {!! $renderLine($line) !!}
                                @endforeach
                            @endforeach
                            @if ($halfKey === 'equity' && $half['net_income'] !== 0)
                                <tr>
                                    <td class="px-4 py-2 font-mono"></td>
                                    <td class="px-4 py-2 italic">{{ __('Net income for the year') }}</td>
                                    <td class="px-4 py-2 text-right font-mono">{{ number_format($half['net_income'] / 100, 2) }}</td>
                                </tr>
                            @endif
                            <tr class="bg-muted/40 font-semibold">
                                <td class="px-4 py-2"></td>
                                <td class="px-4 py-2 text-right">{{ __('Total') }} {{ $half['label'] }}</td>
                                <td class="px-4 py-2 text-right font-mono" data-test="gifi-total-{{ $halfKey }}">{{ number_format($half['total'] / 100, 2) }}</td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
                <tfoot class="bg-muted">
                    <tr class="text-base font-semibold">
                        <td class="px-4 py-2"></td>
                        <td class="px-4 py-2 text-right">{{ __('Total assets') }}</td>
                        <td class="px-4 py-2 text-right font-mono" data-test="gifi-total-assets">{{ number_format($r['bs']['total_assets'] / 100, 2) }}</td>
                    </tr>
                    <tr class="text-base font-semibold">
                        <td class="px-4 py-2"></td>
                        <td class="px-4 py-2 text-right">{{ __('Total liabilities & equity') }}</td>
                        <td class="px-4 py-2 text-right font-mono" data-test="gifi-total-le">{{ number_format($r['bs']['total_le'] / 100, 2) }}</td>
                    </tr>
                    @unless ($r['bs']['balanced'])
                        <tr><td colspan="3" class="px-4 py-2 text-right text-red-600">{{ __('Out of balance by') }} {{ number_format(abs($r['bs']['total_assets'] - $r['bs']['total_le']) / 100, 2) }}</td></tr>
                    @endunless
                </tfoot>
            </table>
        </div>
    </div>

    {{-- ───────────────── Schedule 125 — Income Statement ───────────────── --}}
    <div class="mb-8">
        <flux:heading size="lg" class="mb-3">{{ __('Schedule 125 — Income Statement') }}</flux:heading>

        <div class="overflow-hidden rounded-lg border border-border">
            <table class="w-full text-sm" data-test="gifi-is">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium text-muted-foreground">{{ __('GIFI') }}</th>
                        <th class="px-4 py-2 text-left font-medium text-muted-foreground">{{ __('Description') }}</th>
                        <th class="px-4 py-2 text-right font-medium text-muted-foreground">{{ __('Amount') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @php $anyIs = false; @endphp
                    @foreach ($r['is']['halves'] as $halfKey => $half)
                        @if ($half['sections'] !== [])
                            @php $anyIs = true; @endphp
                            <tr class="bg-muted/50"><td colspan="3" class="px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ $half['label'] }}</td></tr>
                            @foreach ($half['sections'] as $section)
                                @foreach ($section['lines'] as $line)
                                    {!! $renderLine($line) !!}
                                @endforeach
                            @endforeach
                            <tr class="bg-muted/40 font-semibold">
                                <td class="px-4 py-2"></td>
                                <td class="px-4 py-2 text-right">{{ __('Total') }} {{ $half['label'] }}</td>
                                <td class="px-4 py-2 text-right font-mono" data-test="gifi-total-{{ $halfKey }}">{{ number_format($half['total'] / 100, 2) }}</td>
                            </tr>
                        @endif
                    @endforeach
                    @unless ($anyIs)
                        <tr><td colspan="3" class="px-4 py-8 text-center text-muted-foreground">{{ __('No income or expense activity in this period.') }}</td></tr>
                    @endunless
                </tbody>
                <tfoot class="bg-muted">
                    <tr class="text-base font-semibold">
                        <td class="px-4 py-2"></td>
                        <td class="px-4 py-2 text-right">{{ __('Net income (loss)') }}</td>
                        <td class="px-4 py-2 text-right font-mono" data-test="gifi-net-income">{{ number_format($r['is']['net_income'] / 100, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- ───────────────── Unassigned accounts ───────────────── --}}
    @if ($r['unassigned']['lines'] !== [])
        <div class="mb-8">
            <flux:heading size="lg" class="mb-1">{{ __('Unassigned accounts') }}</flux:heading>
            <flux:subheading class="mb-3">{{ __('These accounts have a balance but no GIFI line. Assign one so they appear on the statement.') }}</flux:subheading>

            <div class="overflow-hidden rounded-lg border border-amber-300 dark:border-amber-700">
                <table class="w-full text-sm" data-test="gifi-unassigned">
                    <tbody class="divide-y divide-border">
                        @foreach ($r['unassigned']['lines'] as $line)
                            <tr data-test="gifi-unassigned-row">
                                <td class="px-4 py-2 font-mono">{{ $line['code'] }}</td>
                                <td class="px-4 py-2">{{ $line['name'] }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ number_format($line['amount'] / 100, 2) }}</td>
                                <td class="px-4 py-2 text-right">
                                    <select
                                        wire:change="reassign({{ $line['id'] }}, $event.target.value)"
                                        class="rounded-md border border-input bg-background px-2 py-1 text-sm"
                                        data-test="gifi-assign-select"
                                    >
                                        <option value="">{{ __('Assign GIFI line…') }}</option>
                                        @foreach ($options as $sectionLabel => $opts)
                                            <optgroup label="{{ $sectionLabel }}">
                                                @foreach ($opts as $opt)
                                                    <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</section>
