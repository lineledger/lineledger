<?php

namespace App\Services\Proof;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use ZipArchive;

/**
 * Turns a validated {@see ProofScenario} into the downloadable evidence behind
 * the public verification page: a ZIP of the source transactions and generated
 * reports, plus a `manifest.json` (totals, pass/fail, SHA-256 hashes) that the
 * page reads. Anyone can unzip the bundle and re-derive every figure by hand.
 */
class ProofArtifactWriter
{
    public function __construct(
        private readonly ReportCalculator $calculator,
        private readonly PdfExporter $pdf,
    ) {}

    /**
     * Directory holding the published manifests + bundles.
     */
    public static function directory(): string
    {
        return storage_path('app/proof');
    }

    public static function manifestPath(string $key): string
    {
        return self::directory()."/{$key}.json";
    }

    public static function zipPath(string $key): string
    {
        return self::directory()."/{$key}.zip";
    }

    /**
     * Render every artifact for a scenario and write `{key}.zip` + `{key}.json`.
     *
     * @param  array<string, mixed>  $validation  output of {@see ProofValidator::validate()}
     * @return array<string, mixed> the manifest that was written
     */
    public function write(ProofScenario $scenario, array $validation, CarbonImmutable $generatedAt): array
    {
        if (! is_dir(self::directory())) {
            mkdir(self::directory(), 0775, true);
        }

        /** @var array<string, string> $files  relative path within zip => contents */
        $files = [];

        foreach ($this->sourceFiles($scenario) as $name => $contents) {
            $files["source/{$name}"] = $contents;
        }

        // Verbatim import files (e.g. the literal QuickBooks journal CSVs).
        foreach ($scenario->extraSourceFiles as $name => $contents) {
            $files["source/{$name}"] = $contents;
        }

        $reportNames = [];
        foreach ($scenario->checkpoints as $checkpoint) {
            foreach ($this->reportFiles($scenario, $checkpoint['as_of']) as $name => $contents) {
                $files["reports/{$name}"] = $contents;
                $reportNames[] = $name;
            }
            gc_collect_cycles();
        }

        // The general ledger is a single full-history PDF, not a per-checkpoint one.
        foreach ($this->generalLedgerFiles($scenario) as $name => $contents) {
            $files["reports/{$name}"] = $contents;
            $reportNames[] = $name;
        }

        $sourceHashes = [];
        foreach ($files as $path => $contents) {
            if (str_starts_with($path, 'source/')) {
                $sourceHashes[] = ['name' => $path, 'sha256' => hash('sha256', $contents), 'bytes' => strlen($contents)];
            }
        }

        $manifest = [
            ...$validation,
            'generated_at' => $generatedAt->toIso8601String(),
            'zip' => "{$scenario->key}.zip",
            'source_files' => $sourceHashes,
            'reports' => $reportNames,
        ];

        $files['manifest.json'] = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->zip(self::zipPath($scenario->key), $files);
        file_put_contents(self::manifestPath($scenario->key), $files['manifest.json']);

        return $manifest;
    }

    /**
     * @return array<string, string>
     */
    private function sourceFiles(ProofScenario $scenario): array
    {
        $company = $scenario->company;

        $accounts = Account::query()
            ->where('company_id', $company->id)
            ->orderBy('code')
            ->get();

        $files = [
            'chart-of-accounts.csv' => $this->csv(
                ['Code', 'Account', 'Type', 'Subtype'],
                $accounts->map(fn (Account $a) => [$a->code, $a->name, $a->type->label(), $a->subtype->label()]),
            ),
        ];

        if ($scenario->importedRows !== []) {
            $files['opening-trial-balance.csv'] = $this->csv(
                ['account_code', 'debit', 'credit'],
                array_map(fn (array $r) => [
                    $r['code'],
                    $r['debit'] > 0 ? $this->amount($r['debit']) : '',
                    $r['credit'] > 0 ? $this->amount($r['credit']) : '',
                ], $scenario->importedRows),
            );

            return $files;
        }

        $entries = JournalEntry::query()
            ->where('company_id', $company->id)
            ->where('is_posted', true)
            ->with(['lines.account'])
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        $journalRows = [];
        foreach ($entries as $entry) {
            foreach ($entry->lines->sortBy('line_order') as $line) {
                $journalRows[] = [
                    $entry->entry_no,
                    $entry->entry_date->toDateString(),
                    $line->account?->code,
                    $line->account?->name,
                    $line->debit_cents > 0 ? $this->amount((int) $line->debit_cents) : '',
                    $line->credit_cents > 0 ? $this->amount((int) $line->credit_cents) : '',
                    $line->memo ?? $entry->memo,
                ];
            }
        }
        $files['journal-entries.csv'] = $this->csv(
            ['Entry no', 'Date', 'Account code', 'Account', 'Debit', 'Credit', 'Memo'],
            $journalRows,
        );

        $files['invoices.csv'] = $this->csv(
            ['Invoice no', 'Date', 'Customer', 'Status', 'Total'],
            Invoice::query()->where('company_id', $company->id)->with('contact')->orderBy('invoice_date')->orderBy('id')->get()
                ->map(fn (Invoice $i) => [$i->invoice_no, $i->invoice_date->toDateString(), $i->contact?->display_name, $i->status->value, $this->amount((int) $i->total_cents)]),
        );

        $files['bills.csv'] = $this->csv(
            ['Bill no', 'Date', 'Vendor', 'Total'],
            Bill::query()->where('company_id', $company->id)->with('contact')->orderBy('bill_date')->orderBy('id')->get()
                ->map(fn (Bill $b) => [$b->bill_no, $b->bill_date->toDateString(), $b->contact?->display_name, $this->amount((int) $b->total_cents)]),
        );

        $files['receipts.csv'] = $this->csv(
            ['Receipt no', 'Date', 'Customer', 'Amount'],
            CustomerReceipt::query()->where('company_id', $company->id)->with('contact')->orderBy('receipt_date')->orderBy('id')->get()
                ->map(fn (CustomerReceipt $r) => [$r->receipt_no, $r->receipt_date->toDateString(), $r->contact?->display_name, $this->amount((int) $r->amount_cents)]),
        );

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private function reportFiles(ProofScenario $scenario, CarbonImmutable $asOf): array
    {
        $date = $asOf->toDateString();
        $tb = $this->trialBalanceReport($scenario, $asOf);

        $files = [];

        $files["trial-balance-{$date}.pdf"] = $this->pdf->raw('pdf.reports.trial-balance', [
            'company' => $scenario->company,
            'report' => $tb,
            'asOf' => $date,
            'title' => 'Trial Balance',
        ]);

        $files["trial-balance-{$date}.csv"] = $this->csv(
            ['Code', 'Account', 'Type', 'Debit', 'Credit'],
            collect($tb['rows'])
                ->map(fn (array $row) => [$row['code'], $row['name'], $row['type'], $this->amount($row['debit']), $this->amount($row['credit'])])
                ->push(['', 'TOTAL', '', $this->amount($tb['totals']['debit']), $this->amount($tb['totals']['credit'])]),
        );

        $files["balance-sheet-{$date}.csv"] = $this->balanceSheetCsv($scenario, $asOf);
        $files["income-statement-{$date}.csv"] = $this->incomeStatementCsv($scenario, $asOf);

        // Sub-ledger reports, rendered through their real Livewire components so the
        // bundle reflects exactly what the app's report pages produce.
        $company = $scenario->company;

        $files["ar-aging-{$date}.pdf"] = $this->pdf->raw('pdf.reports.aging', [
            'company' => $company,
            'title' => 'AR Aging',
            'entityLabel' => 'Customer',
            'emptyMessage' => 'No open invoices as of this date.',
            'report' => $this->livewireReport('pages::reports.ar-aging', $company, $date),
            'asOf' => $date,
        ]);

        $files["ap-aging-{$date}.pdf"] = $this->pdf->raw('pdf.reports.aging', [
            'company' => $company,
            'title' => 'AP Aging',
            'entityLabel' => 'Vendor',
            'emptyMessage' => 'No open bills as of this date.',
            'report' => $this->livewireReport('pages::reports.ap-aging', $company, $date),
            'asOf' => $date,
        ]);

        $files["open-invoices-{$date}.pdf"] = $this->pdf->raw('pdf.reports.open-invoices', [
            'company' => $company,
            'report' => $this->livewireReport('pages::reports.open-invoices', $company, $date),
            'asOf' => $date,
            'title' => 'Open Invoices',
        ]);

        $files["open-bills-{$date}.pdf"] = $this->pdf->raw('pdf.reports.open-bills', [
            'company' => $company,
            'report' => $this->livewireReport('pages::reports.open-bills', $company, $date),
            'asOf' => $date,
            'title' => 'Open Bills',
        ]);

        return $files;
    }

    /**
     * Render a report page's `report` computed property through its real Livewire
     * component so the artifact matches the live page byte-for-byte.
     *
     * @return array<string, mixed>
     */
    private function livewireReport(string $alias, Company $company, string $asOf): array
    {
        app()->instance('current_company', $company);

        $component = Livewire::new($alias);
        $component->mount($company);
        $component->asOf = $asOf;

        return $component->report();
    }

    /**
     * General ledger across every account. The complete span is emitted as a CSV
     * (cheap and re-derivable at any volume); the PDF is rendered per fiscal year
     * because a single full-history PDF of thousands of rows exhausts dompdf.
     *
     * @return array<string, string>
     */
    private function generalLedgerFiles(ProofScenario $scenario): array
    {
        app()->instance('current_company', $scenario->company);

        $checkpoints = $scenario->checkpoints;
        $spanStart = $this->calculator->fiscalYearStart($scenario->company, $checkpoints[0]['as_of']);
        $spanEnd = $checkpoints[array_key_last($checkpoints)]['as_of'];

        $files = [];

        // Complete general ledger for the whole span, as CSV.
        $full = $this->calculator->generalLedgerAllAccounts($spanStart, $spanEnd);
        $files["general-ledger-{$spanStart->toDateString()}_to_{$spanEnd->toDateString()}.csv"] = $this->generalLedgerCsv($full);

        // One PDF per fiscal year so each render stays within memory.
        foreach ($checkpoints as $checkpoint) {
            $start = $this->calculator->fiscalYearStart($scenario->company, $checkpoint['as_of']);
            $end = $checkpoint['as_of'];
            $report = $this->calculator->generalLedgerAllAccounts($start, $end);

            $files["general-ledger-{$start->toDateString()}_to_{$end->toDateString()}.pdf"] = $this->pdf->raw('pdf.reports.general-ledger-all', [
                'company' => $scenario->company,
                'title' => 'General Ledger',
                'startDate' => $start->toDateString(),
                'endDate' => $end->toDateString(),
                'report' => $report,
            ]);

            gc_collect_cycles();
        }

        return $files;
    }

    /**
     * @param  array<string, mixed>  $report  output of ReportCalculator::generalLedgerAllAccounts()
     */
    private function generalLedgerCsv(array $report): string
    {
        $rows = [];
        foreach ($report['entries'] as $entry) {
            foreach ($entry['lines'] as $line) {
                $rows[] = [
                    $entry['entry_no'],
                    $entry['date'],
                    $line['account_code'],
                    $line['account_name'],
                    $line['debit'] > 0 ? $this->amount((int) $line['debit']) : '',
                    $line['credit'] > 0 ? $this->amount((int) $line['credit']) : '',
                    $line['memo'] ?? $entry['memo'],
                ];
            }
        }

        return $this->csv(['Entry no', 'Date', 'Account code', 'Account', 'Debit', 'Credit', 'Memo'], $rows);
    }

    /**
     * @return array{rows: list<array{code: string, name: string, type: string, debit: int, credit: int}>, totals: array{debit: int, credit: int}}
     */
    private function trialBalanceReport(ProofScenario $scenario, CarbonImmutable $asOf): array
    {
        $rows = [];
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($this->calculator->trialBalance($scenario->company, $asOf) as $row) {
            /** @var Account $account */
            $account = $row['account'];
            $balance = $row['balance'];

            if ($account->normal_balance === NormalBalance::Debit) {
                $debit = $balance > 0 ? $balance : 0;
                $credit = $balance < 0 ? -$balance : 0;
            } else {
                $credit = $balance > 0 ? $balance : 0;
                $debit = $balance < 0 ? -$balance : 0;
            }

            $rows[] = ['code' => $account->code, 'name' => $account->name, 'type' => $account->type->label(), 'debit' => $debit, 'credit' => $credit];
            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        return ['rows' => $rows, 'totals' => ['debit' => $totalDebit, 'credit' => $totalCredit]];
    }

    private function balanceSheetCsv(ProofScenario $scenario, CarbonImmutable $asOf): string
    {
        $tb = $this->calculator->trialBalance($scenario->company, $asOf);
        $rows = [];

        $section = function (AccountType $type, string $label) use ($tb, &$rows): int {
            $subtotal = 0;
            $rows[] = [$label, ''];
            foreach ($tb as $row) {
                if ($row['account']->type === $type) {
                    $rows[] = ['  '.$row['account']->code.' '.$row['account']->name, $this->amount($row['balance'])];
                    $subtotal += $row['balance'];
                }
            }
            $rows[] = ["Total {$label}", $this->amount($subtotal)];

            return $subtotal;
        };

        $assets = $section(AccountType::Asset, 'Assets');
        $liabilities = $section(AccountType::Liability, 'Liabilities');
        $equity = $section(AccountType::Equity, 'Equity');
        $netIncome = $this->calculator->netIncomeYtd($scenario->company, $asOf);
        $rows[] = ['Net income (current year)', $this->amount($netIncome)];
        $rows[] = ['Total liabilities + equity + net income', $this->amount($liabilities + $equity + $netIncome)];

        return $this->csv(['Line', 'Balance'], $rows);
    }

    private function incomeStatementCsv(ProofScenario $scenario, CarbonImmutable $asOf): string
    {
        $tb = $this->calculator->trialBalance($scenario->company, $asOf);
        $rows = [];

        $section = function (AccountType $type, string $label) use ($tb, &$rows): int {
            $subtotal = 0;
            $rows[] = [$label, ''];
            foreach ($tb as $row) {
                if ($row['account']->type === $type) {
                    $rows[] = ['  '.$row['account']->code.' '.$row['account']->name, $this->amount($row['balance'])];
                    $subtotal += $row['balance'];
                }
            }
            $rows[] = ["Total {$label}", $this->amount($subtotal)];

            return $subtotal;
        };

        $income = $section(AccountType::Income, 'Income');
        $expense = $section(AccountType::Expense, 'Expenses');
        $rows[] = ['Net income', $this->amount($income - $expense)];

        return $this->csv(['Line', 'Amount'], $rows);
    }

    /**
     * @param  iterable<array<int, string|int|null>>  $rows
     */
    private function csv(array $headers, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    private function amount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * @param  array<string, string>  $files  relative path => contents
     */
    private function zip(string $path, array $files): void
    {
        if (file_exists($path)) {
            unlink($path);
        }

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
    }
}
