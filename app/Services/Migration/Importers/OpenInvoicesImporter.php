<?php

namespace App\Services\Migration\Importers;

use App\Enums\AccountSubtype;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Invoice;
use App\Services\Migration\Csv\CsvParser;
use App\Services\Migration\ImportContext;
use App\Services\Migration\ImportResult;
use App\Services\Posting\InvoicePoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports open (unpaid / partially-paid) customer invoices at conversion date.
 *
 * Each row becomes an Invoice with is_opening_balance=true. The synthetic
 * single line targets Opening Balance Equity (subtype Equity, code 3000),
 * so the existing InvoicePoster naturally posts:
 *   DR Accounts Receivable          balance_remaining
 *   CR Opening Balance Equity       balance_remaining
 *
 * No revenue, tax, or COGS is recognised — those events happened in QB.
 */
class OpenInvoicesImporter implements Importer
{
    /** When importing a QuickBooks report the customer name is authoritative, so create it if missing. */
    protected bool $autoCreateMissingCustomers = false;

    public function __construct(
        protected CsvParser $parser,
        protected InvoicePoster $invoicePoster,
    ) {}

    public function templateHeaders(): array
    {
        return ['customer_display_name', 'invoice_no', 'invoice_date', 'due_date', 'balance_remaining', 'memo'];
    }

    public function templateExampleRows(): array
    {
        return [[
            'customer_display_name' => 'Acme Construction Ltd.',
            'invoice_no' => 'INV-1042',
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-07-15',
            'balance_remaining' => '1250.00',
            'memo' => 'Carried over from QuickBooks',
        ]];
    }

    public function preview(string $csvPath, ImportContext $ctx): ImportResult
    {
        return $this->run($csvPath, $ctx, true);
    }

    public function commit(string $csvPath, ImportContext $ctx): ImportResult
    {
        return $this->run($csvPath, $ctx, false);
    }

    protected function run(string $csvPath, ImportContext $ctx, bool $dryRun): ImportResult
    {
        try {
            $rows = $this->normalizedRows($csvPath);
        } catch (Throwable $e) {
            return new ImportResult(isDryRun: $dryRun, previewRows: [], errors: [['row' => 0, 'message' => $e->getMessage()]]);
        }

        $errors = [];
        $preview = [];
        $createdIds = [];
        $created = 0;
        $totalCents = 0;

        $obe = $this->openingBalanceEquityAccount($ctx->company->id);

        if (! $obe) {
            return new ImportResult(
                isDryRun: $dryRun,
                previewRows: [],
                errors: [['row' => 0, 'message' => "Missing 'Opening Balance Equity' account — chart of accounts must include an Equity account named 'Opening Balance Equity'."]],
            );
        }

        $contactByName = Contact::withoutGlobalScopes()
            ->where('company_id', $ctx->company->id)
            ->where('is_customer', true)
            ->get(['id', 'display_name'])
            ->keyBy('display_name');

        $runner = function () use ($rows, $ctx, $obe, $contactByName, &$errors, &$preview, &$createdIds, &$created, &$totalCents, $dryRun): void {
            foreach ($rows as $i => $row) {
                $rowNum = $i + 2;
                $name = $row['customer_display_name'];
                $invoiceNo = $row['invoice_no'];
                $balanceCents = CsvParser::parseCents($row['balance_remaining']);

                if (! $name || ! $invoiceNo || $balanceCents === null) {
                    $errors[] = ['row' => $rowNum, 'message' => 'customer_display_name, invoice_no and balance_remaining are required.'];

                    continue;
                }

                if ($balanceCents <= 0) {
                    $errors[] = ['row' => $rowNum, 'message' => 'balance_remaining must be greater than zero.'];

                    continue;
                }

                $contact = $contactByName->get($name);

                if (! $contact) {
                    // Native template: require the customer to exist (catches typos).
                    // QuickBooks report: the customer name is authoritative — create it.
                    if (! $this->autoCreateMissingCustomers) {
                        $errors[] = ['row' => $rowNum, 'message' => "Customer '{$name}' not found. Import customers before open invoices."];

                        continue;
                    }

                    if (! $dryRun) {
                        $contact = Contact::withoutGlobalScopes()->create([
                            'company_id' => $ctx->company->id,
                            'display_name' => $name,
                            'is_customer' => true,
                            'is_active' => true,
                        ]);
                        $contactByName->put($name, $contact);
                    }
                }

                $invoiceDate = $ctx->useOriginalDates && $row['invoice_date']
                    ? CarbonImmutable::parse($row['invoice_date'])
                    : $ctx->conversionDate;

                $dueDate = $row['due_date']
                    ? CarbonImmutable::parse($row['due_date'])
                    : $invoiceDate;

                $preview[] = [
                    'row' => $rowNum,
                    'customer' => $name,
                    'invoice_no' => $invoiceNo,
                    'invoice_date' => $invoiceDate->toDateString(),
                    'balance' => CsvParser::centsLabel($balanceCents),
                ];

                $totalCents += $balanceCents;

                if ($dryRun) {
                    continue;
                }

                $invoice = Invoice::withoutGlobalScopes()->create([
                    'company_id' => $ctx->company->id,
                    'contact_id' => $contact->id,
                    'invoice_no' => $invoiceNo,
                    'invoice_date' => $invoiceDate,
                    'due_date' => $dueDate,
                    'status' => InvoiceStatus::Draft,
                    'subtotal_cents' => $balanceCents,
                    'tax_cents' => 0,
                    'total_cents' => $balanceCents,
                    'amount_paid_cents' => 0,
                    'memo' => $row['memo'] ?? 'Opening balance — carried over from QuickBooks',
                    'is_opening_balance' => true,
                ]);

                $invoice->lines()->create([
                    'account_id' => $obe->id,
                    'description' => "Opening balance — QB invoice {$invoiceNo}",
                    'quantity' => '1.0000',
                    'unit_price_cents' => $balanceCents,
                    'line_subtotal_cents' => $balanceCents,
                    'line_tax_cents' => 0,
                    'line_total_cents' => $balanceCents,
                    'line_order' => 0,
                ]);

                $this->invoicePoster->post($invoice->fresh());

                $created++;
                $createdIds[] = $invoice->id;
            }
        };

        if ($dryRun) {
            $runner();
        } else {
            try {
                DB::transaction($runner);
            } catch (Throwable $e) {
                $errors[] = ['row' => 0, 'message' => 'Import aborted: '.$e->getMessage()];
            }
        }

        return new ImportResult(
            isDryRun: $dryRun,
            previewRows: $preview,
            errors: $errors,
            createdIds: $createdIds,
            summary: [
                'created' => $created,
                'rows' => count($rows),
                'total_ar_cents' => $totalCents,
            ],
        );
    }

    /**
     * Read the native template, or a QuickBooks "Open Invoices" report. The QB report
     * is grouped by customer and lists each open Invoice / Payment / Journal line with
     * an Open Balance; we net each customer's credits against their invoices (oldest
     * first) so a customer whose invoices and payments cancel out imports nothing.
     *
     * @return list<array<string, ?string>>
     */
    protected function normalizedRows(string $csvPath): array
    {
        if ($this->isQuickBooksFormat($csvPath)) {
            $this->autoCreateMissingCustomers = true;

            return $this->parseQuickBooks($csvPath);
        }

        return $this->parser->parse($csvPath, ['customer_display_name', 'invoice_no', 'balance_remaining'], $this->templateHeaders());
    }

    protected function isQuickBooksFormat(string $csvPath): bool
    {
        $handle = @fopen($csvPath, 'r');

        if ($handle === false) {
            return false;
        }

        try {
            $n = 0;
            while (($row = fgetcsv($handle, escape: '')) !== false && $n++ < 15) {
                $map = $this->headerMap($row);

                if (isset($map['customer_display_name'])) {
                    return false;
                }
                if (isset($map['open balance'], $map['type'])) {
                    return true;
                }
            }

            return false;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return list<array<string, ?string>>
     */
    protected function parseQuickBooks(string $csvPath): array
    {
        $handle = @fopen($csvPath, 'r');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open CSV file at: {$csvPath}");
        }

        try {
            $cols = null;
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                if ($row === [null] || $row === false) {
                    continue;
                }
                $map = $this->headerMap($row);
                if (isset($map['open balance'], $map['type'])) {
                    $cols = $map;
                    break;
                }
            }

            if ($cols === null) {
                throw new \RuntimeException('Could not find a header row. Use the template (customer_display_name, invoice_no, balance_remaining) or a QuickBooks "Open Invoices" report.');
            }

            // Group rows under their customer section header (the leftmost column).
            $groups = [];
            $current = null;

            while (($cells = fgetcsv($handle, escape: '')) !== false) {
                if ($cells === [null] || $cells === false) {
                    continue;
                }

                $section = $this->valueAt($cells, 0);

                if ($section !== null) {
                    $current = str_starts_with(mb_strtolower($section), 'total') ? null : $section;

                    continue;
                }

                if ($current === null) {
                    continue;
                }

                $open = CsvParser::parseCents($this->col($cells, $cols, 'open balance'));

                if ($open === null || $open === 0) {
                    continue;
                }

                $groups[$current][] = [
                    'num' => $this->col($cells, $cols, 'num'),
                    'date' => $this->col($cells, $cols, 'date'),
                    'due' => $this->col($cells, $cols, 'due date'),
                    'open' => $open,
                ];
            }

            return $this->netOpenItems($groups);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Net each customer's credits (negative balances) against their open items
     * (positive balances), oldest first, and emit the remaining open invoices.
     *
     * @param  array<string, list<array{num: ?string, date: ?string, due: ?string, open: int}>>  $groups
     * @return list<array<string, ?string>>
     */
    protected function netOpenItems(array $groups): array
    {
        $rows = [];

        foreach ($groups as $customer => $items) {
            $positives = array_values(array_filter($items, fn ($i) => $i['open'] > 0));
            $credit = array_sum(array_map(fn ($i) => -$i['open'], array_filter($items, fn ($i) => $i['open'] < 0)));

            usort($positives, fn ($a, $b) => ($this->sortableDate($a['date'])) <=> ($this->sortableDate($b['date'])));

            foreach ($positives as $idx => $item) {
                $applied = min($credit, $item['open']);
                $credit -= $applied;
                $remaining = $item['open'] - $applied;

                if ($remaining <= 0) {
                    continue;
                }

                $rows[] = [
                    'customer_display_name' => $customer,
                    'invoice_no' => ($item['num'] ?? '') !== '' ? $item['num'] : 'OB-'.$customer.'-'.($idx + 1),
                    'invoice_date' => $item['date'],
                    'due_date' => $item['due'],
                    'balance_remaining' => number_format($remaining / 100, 2, '.', ''),
                    'memo' => null,
                ];
            }
        }

        return $rows;
    }

    protected function sortableDate(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        try {
            return CarbonImmutable::parse(trim($value))->format('Y-m-d');
        } catch (Throwable) {
            return $value;
        }
    }

    /**
     * @param  array<int, ?string>  $row
     * @return array<string, int>
     */
    protected function headerMap(array $row): array
    {
        $map = [];
        foreach ($row as $i => $cell) {
            $header = strtolower(trim($this->toUtf8((string) ($cell ?? ''))));
            if ($header !== '' && ! isset($map[$header])) {
                $map[$header] = $i;
            }
        }

        return $map;
    }

    /**
     * @param  array<int, ?string>  $cells
     * @param  array<string, int>  $cols
     */
    protected function col(array $cells, array $cols, string $key): ?string
    {
        return isset($cols[$key]) ? $this->valueAt($cells, $cols[$key]) : null;
    }

    /**
     * @param  array<int, ?string>  $cells
     */
    protected function valueAt(array $cells, int $index): ?string
    {
        $value = $cells[$index] ?? null;
        $value = $value === null ? '' : trim($this->toUtf8((string) $value));

        return $value === '' ? null : $value;
    }

    protected function toUtf8(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        if (str_starts_with($value, "\u{FEFF}")) {
            $value = substr($value, 3);
        }

        return mb_scrub($value, 'UTF-8');
    }

    protected function openingBalanceEquityAccount(int $companyId): ?Account
    {
        return Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('subtype', AccountSubtype::Equity->value)
            ->whereIn('name', Account::OPENING_BALANCE_NAMES)
            ->first();
    }
}
