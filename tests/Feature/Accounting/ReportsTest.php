<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillType;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\TaxCode;
use App\Services\Posting\BillPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\JournalPoster;
use App\Services\Reporting\ReportCalculator;
use App\Services\Reporting\XlsxExporter;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;

/**
 * Build a minimal but complete scenario for reports testing.
 * Returns the company and key accounts so individual tests can assert.
 */
function reportsScenario(): array
{
    $company = Company::factory()->create();
    app()->instance('current_company', $company);

    $customer = Contact::create(['display_name' => 'Report Customer', 'is_customer' => true]);
    $vendor = Contact::create(['display_name' => 'Report Vendor', 'is_vendor' => true]);

    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
    $gst = TaxCode::where('code', 'GST')->firstOrFail();

    // Invoice: $1000 + 5% GST = $1050
    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-R-1',
        'invoice_date' => CarbonImmutable::create(2026, 3, 1),
        'due_date' => CarbonImmutable::create(2026, 3, 31),
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 100000,
        'tax_code_id' => $gst->id,
        'line_subtotal_cents' => 100000,
        'line_tax_cents' => 5000,
        'line_total_cents' => 105000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    // Bill: $300 + 5% GST = $315 (recoverable)
    $bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-R-1',
        'bill_date' => CarbonImmutable::create(2026, 3, 5),
        'due_date' => CarbonImmutable::create(2026, 4, 5),
    ]);
    $bill->lines()->create([
        'account_id' => $expense->id,
        'description' => 'Supplies',
        'quantity' => '1',
        'unit_price_cents' => 30000,
        'tax_code_id' => $gst->id,
        'line_subtotal_cents' => 30000,
        'line_tax_cents' => 1500,
        'line_total_cents' => 31500,
        'line_order' => 0,
    ]);
    app(BillPoster::class)->post($bill);

    return compact('company', 'income', 'expense', 'gst');
}

/**
 * Wrap a balance-sheet bucket's subtype groups (subtypeValue => ['label','rows'])
 * into the section-block shape the exporters now consume (no custom sections, so a
 * single Unassigned block per subtype).
 *
 * @param  array<string, array{label: string, rows: array<int, array<string, mixed>>}>  $groups
 */
function bsGroupsToBlocks(array $groups): array
{
    return collect($groups)->map(fn (array $group): array => [
        'label' => $group['label'],
        'blocks' => [[
            'type' => 'unassigned',
            'rows' => $group['rows'],
            'subtotal' => array_sum(array_column($group['rows'], 'balance')),
            'prior_subtotal' => array_sum(array_column($group['rows'], 'prior')),
        ]],
    ])->all();
}

/**
 * Wrap an income-statement bucket's rows into the section-block shape (a single
 * Unassigned block, or an empty list when the bucket has no rows).
 *
 * @param  array<int, array<string, mixed>>  $rows
 */
function isBucketToBlocks(array $rows): array
{
    if ($rows === []) {
        return [];
    }

    return [[
        'type' => 'unassigned',
        'rows' => $rows,
        'subtotal' => array_sum(array_column($rows, 'current')),
        'prior_subtotal' => array_sum(array_column($rows, 'prior')),
    ]];
}

it('trial balance: debits equal credits', function () {
    $s = reportsScenario();
    $tb = app(ReportCalculator::class)->trialBalance($s['company'], CarbonImmutable::create(2026, 4, 1));

    $totalDr = 0;
    $totalCr = 0;

    foreach ($tb as $row) {
        $balance = $row['balance'];

        if ($row['account']->normal_balance->value === 'debit') {
            $balance > 0 ? $totalDr += $balance : $totalCr += -$balance;
        } else {
            $balance > 0 ? $totalCr += $balance : $totalDr += -$balance;
        }
    }

    expect($totalDr)->toBe($totalCr);
    expect($totalDr)->toBeGreaterThan(0);

    app()->forgetInstance('current_company');
});

it('balance sheet: assets = liabilities + equity + net income', function () {
    $s = reportsScenario();
    $calc = app(ReportCalculator::class);
    $asOf = CarbonImmutable::create(2026, 4, 1);

    $assets = $calc->totalForType($s['company'], AccountType::Asset, CarbonImmutable::create(2000, 1, 1), $asOf);
    $liabilities = $calc->totalForType($s['company'], AccountType::Liability, CarbonImmutable::create(2000, 1, 1), $asOf);
    $equity = $calc->totalForType($s['company'], AccountType::Equity, CarbonImmutable::create(2000, 1, 1), $asOf);
    $netIncome = $calc->netIncomeYtd($s['company'], $asOf);

    expect($assets)->toBe($liabilities + $equity + $netIncome);

    app()->forgetInstance('current_company');
});

it('income statement: net income matches the scenario', function () {
    $s = reportsScenario();
    $calc = app(ReportCalculator::class);

    // Scenario: $1000 income, $300 expense → $700 net income
    $netIncome = $calc->netIncomeYtd($s['company'], CarbonImmutable::create(2026, 4, 1));

    expect($netIncome)->toBe(70000);

    app()->forgetInstance('current_company');
});

it('sales tax: collected on invoice, paid as ITC on bill, net = collected - paid', function () {
    $s = reportsScenario();
    $calc = app(ReportCalculator::class);

    $agency = $s['gst']->agency;
    $result = $calc->salesTaxForAgency($agency, CarbonImmutable::create(2026, 1, 1), CarbonImmutable::create(2026, 12, 31));

    // Invoice GST collected: $50.00 = 5000c
    // Bill GST ITC claimed: $15.00 = 1500c
    // Net owing: $35.00 = 3500c
    expect($result['collected'])->toBe(5000);
    expect($result['paid'])->toBe(1500);
    expect($result['net'])->toBe(3500);

    app()->forgetInstance('current_company');
});

it('sales tax: voided invoice reduces collected, not ITC', function () {
    $company = Company::factory()->create();
    app()->instance('current_company', $company);

    $customer = Contact::create(['display_name' => 'Void Customer', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $gst = TaxCode::where('code', 'GST')->firstOrFail();

    // Two $500 invoices, then void the first
    $first = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-V-1',
        'invoice_date' => CarbonImmutable::create(2026, 5, 1),
        'due_date' => CarbonImmutable::create(2026, 5, 31),
    ]);
    $first->lines()->create([
        'account_id' => $income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 50000,
        'tax_code_id' => $gst->id,
        'line_subtotal_cents' => 50000,
        'line_tax_cents' => 2500,
        'line_total_cents' => 52500,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($first);

    $second = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-V-2',
        'invoice_date' => CarbonImmutable::create(2026, 5, 2),
        'due_date' => CarbonImmutable::create(2026, 6, 1),
    ]);
    $second->lines()->create([
        'account_id' => $income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 50000,
        'tax_code_id' => $gst->id,
        'line_subtotal_cents' => 50000,
        'line_tax_cents' => 2500,
        'line_total_cents' => 52500,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($second);

    app(JournalPoster::class)->void(
        $first->fresh('journalEntry')->journalEntry,
        CarbonImmutable::create(2026, 5, 3),
    );

    $calc = app(ReportCalculator::class);
    $result = $calc->salesTaxForAgency(
        $gst->agency,
        CarbonImmutable::create(2026, 5, 1),
        CarbonImmutable::create(2026, 5, 31),
    );

    // The void cancels the first invoice's $25 GST: collected nets to $25, ITC stays $0.
    expect($result['collected'])->toBe(2500);
    expect($result['paid'])->toBe(0);
    expect($result['net'])->toBe(2500);

    app()->forgetInstance('current_company');
});

it('sales tax: drill-down lists source documents per bucket', function () {
    $s = reportsScenario();
    $calc = app(ReportCalculator::class);

    $lines = $calc->salesTaxLines(
        $s['gst']->agency,
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 12, 31),
    );

    $collected = $lines->where('bucket', 'collected')->values();
    $paid = $lines->where('bucket', 'paid')->values();

    expect($collected)->toHaveCount(1)
        ->and($collected[0]['amount_cents'])->toBe(5000)
        ->and($collected[0]['source_type'])->toBe(Invoice::class)
        ->and($collected[0]['doc_label'])->toContain('INV-R-1');

    expect($paid)->toHaveCount(1)
        ->and($paid[0]['amount_cents'])->toBe(1500)
        ->and($paid[0]['source_type'])->toBe(Bill::class)
        ->and($paid[0]['doc_label'])->toContain('BILL-R-1');

    app()->forgetInstance('current_company');
});

it('general ledger: running balance equals balance-as-of-end', function () {
    $s = reportsScenario();
    $calc = app(ReportCalculator::class);

    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();

    $gl = $calc->generalLedger($ar, CarbonImmutable::create(2026, 1, 1), CarbonImmutable::create(2026, 12, 31));
    $balance = $calc->balanceAsOf($ar, CarbonImmutable::create(2026, 12, 31));

    expect($gl['closing'])->toBe($balance);
    expect($gl['closing'])->toBe(105000); // single invoice posted

    app()->forgetInstance('current_company');
});

it('general ledger (all accounts): groups by entry with balanced totals', function () {
    $s = reportsScenario();
    $calc = app(ReportCalculator::class);

    $gl = $calc->generalLedgerAllAccounts(
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 12, 31),
    );

    // Scenario posts exactly two entries (one invoice, one bill).
    expect($gl['entry_count'])->toBe(2);
    expect($gl['line_count'])->toBeGreaterThanOrEqual(4); // 3 lines per entry minimum: AR/AP, income/expense, tax
    expect($gl['total_debit'])->toBe($gl['total_credit']);
    expect($gl['total_debit'])->toBeGreaterThan(0);

    // Each entry itself is balanced.
    foreach ($gl['entries'] as $entry) {
        expect($entry['total_debit'])->toBe($entry['total_credit']);
        expect($entry['lines'])->not->toBeEmpty();
        foreach ($entry['lines'] as $line) {
            expect($line)->toHaveKeys(['account_code', 'account_name', 'memo', 'debit', 'credit']);
        }
    }

    // Entries are ordered chronologically.
    $dates = $gl['entries']->pluck('date')->all();
    $sorted = $dates;
    sort($sorted);
    expect($dates)->toBe($sorted);

    app()->forgetInstance('current_company');
});

it('general ledger (all accounts): excludes unposted entries', function () {
    $s = reportsScenario();

    // Add a draft entry that should NOT appear in the report.
    $income = $s['income'];
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $draft = JournalEntry::create([
        'entry_no' => 'JE-DRAFT',
        'entry_date' => CarbonImmutable::create(2026, 3, 15),
        'memo' => 'Draft',
        'is_posted' => false,
    ]);
    $draft->lines()->create(['account_id' => $ar->id, 'debit_cents' => 1000, 'credit_cents' => 0, 'line_order' => 0]);
    $draft->lines()->create(['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => 1000, 'line_order' => 1]);

    $gl = app(ReportCalculator::class)->generalLedgerAllAccounts(
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 12, 31),
    );

    foreach ($gl['entries'] as $entry) {
        expect($entry['entry_no'])->not->toBe('JE-DRAFT');
    }

    app()->forgetInstance('current_company');
});

it('xlsx export: writes a real xlsx file for the all-accounts GL', function () {
    $s = reportsScenario();
    $calc = app(ReportCalculator::class);

    $gl = $calc->generalLedgerAllAccounts(
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 12, 31),
    );

    $response = app(XlsxExporter::class)->generalLedgerAllAccounts(
        'test.xlsx',
        $s['company'],
        $gl,
        '2026-01-01',
        '2026-12-31',
    );

    $path = $response->getFile()->getRealPath();
    expect(filesize($path))->toBeGreaterThan(0);

    // XLSX files are ZIP archives → first 2 bytes are "PK".
    $first2 = file_get_contents($path, false, null, 0, 2);
    expect($first2)->toBe('PK');

    expect($response->headers->get('Content-Disposition'))->toContain('test.xlsx');

    @unlink($path);
    app()->forgetInstance('current_company');
});

it('xlsx export: writes a real xlsx file for a single-account GL', function () {
    $s = reportsScenario();
    $calc = app(ReportCalculator::class);

    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $gl = $calc->generalLedger($ar, CarbonImmutable::create(2026, 1, 1), CarbonImmutable::create(2026, 12, 31));

    $response = app(XlsxExporter::class)->generalLedgerSingleAccount(
        'ar.xlsx',
        $s['company'],
        $ar,
        $gl,
        '2026-01-01',
        '2026-12-31',
    );

    $path = $response->getFile()->getRealPath();
    $first2 = file_get_contents($path, false, null, 0, 2);
    expect($first2)->toBe('PK');
    expect(filesize($path))->toBeGreaterThan(0);

    @unlink($path);
    app()->forgetInstance('current_company');
});

/**
 * Read every cell value out of a generated xlsx. Returns a flat array of strings,
 * which lets tests assert presence of formulas, totals, and the generation timestamp.
 */
function readXlsxStrings(string $path): array
{
    $zip = new ZipArchive;
    expect($zip->open($path))->toBe(true);

    $decode = fn (string $s): string => html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8');

    $shared = [];
    $sst = $zip->getFromName('xl/sharedStrings.xml');
    if ($sst !== false) {
        if (preg_match_all('/<t[^>]*>([^<]*)<\/t>/', $sst, $m)) {
            $shared = array_map($decode, $m[1]);
        }
    }

    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    expect($sheet)->not->toBeFalse();

    $values = [];

    if (preg_match_all('/<c[^>]*t="inlineStr"[^>]*>\s*<is>\s*<t[^>]*>([^<]*)<\/t>/', $sheet, $m)) {
        foreach ($m[1] as $v) {
            $values[] = $decode($v);
        }
    }

    if (preg_match_all('/<c[^>]*t="s"[^>]*>\s*<v>(\d+)<\/v>/', $sheet, $m)) {
        foreach ($m[1] as $idx) {
            $values[] = $shared[(int) $idx] ?? '';
        }
    }

    if (preg_match_all('/<f[^>]*>([^<]+)<\/f>/', $sheet, $m)) {
        foreach ($m[1] as $f) {
            $values[] = '='.$decode($f);
        }
    }

    if (preg_match_all('/<c [^>]*>\s*<v>([^<]+)<\/v>\s*<\/c>/', $sheet, $m)) {
        foreach ($m[1] as $v) {
            $values[] = $v;
        }
    }

    $zip->close();

    return $values;
}

it('xlsx: trial balance has SUM formulas for totals + generated date', function () {
    $s = reportsScenario();

    $report = (function () use ($s) {
        $calc = app(ReportCalculator::class);
        $tb = $calc->trialBalance($s['company'], CarbonImmutable::create(2026, 4, 1));
        $rows = [];
        $totalDr = 0;
        $totalCr = 0;
        foreach ($tb as $r) {
            $account = $r['account'];
            $balance = $r['balance'];
            if ($account->normal_balance->value === 'debit') {
                $debit = $balance > 0 ? $balance : 0;
                $credit = $balance < 0 ? -$balance : 0;
            } else {
                $credit = $balance > 0 ? $balance : 0;
                $debit = $balance < 0 ? -$balance : 0;
            }
            $rows[] = ['code' => $account->code, 'name' => $account->name, 'type' => $account->type->label(), 'debit' => $debit, 'credit' => $credit];
            $totalDr += $debit;
            $totalCr += $credit;
        }

        return ['rows' => $rows, 'totals' => ['debit' => $totalDr, 'credit' => $totalCr]];
    })();

    $response = app(XlsxExporter::class)->trialBalance('tb.xlsx', $s['company'], $report, '2026-04-01');
    $path = $response->getFile()->getRealPath();

    $values = readXlsxStrings($path);

    expect(implode("\n", $values))
        ->toContain('Trial Balance')
        ->toContain('Generated ')
        ->toContain('Total');

    $hasFormula = collect($values)->contains(fn ($v) => str_starts_with($v, '=SUM(D'));
    expect($hasFormula)->toBeTrue();

    @unlink($path);
    app()->forgetInstance('current_company');
});

it('xlsx: balance sheet uses formulas and includes generation timestamp', function () {
    $s = reportsScenario();
    $calc = app(ReportCalculator::class);
    $asOf = CarbonImmutable::create(2026, 4, 1);

    $accounts = Account::withoutGlobalScopes()
        ->where('company_id', $s['company']->id)
        ->whereIn('type', [AccountType::Asset->value, AccountType::Liability->value, AccountType::Equity->value])
        ->orderBy('code')->get();

    $groups = ['assets' => [], 'liabilities' => [], 'equity' => []];
    $totals = ['assets' => 0, 'liabilities' => 0, 'equity' => 0];
    foreach ($accounts as $a) {
        $balance = $calc->balanceAsOf($a, $asOf);
        if ($balance === 0) {
            continue;
        }
        $bucket = match ($a->type) {
            AccountType::Asset => 'assets',
            AccountType::Liability => 'liabilities',
            AccountType::Equity => 'equity',
            default => null,
        };
        if (! $bucket) {
            continue;
        }
        $groups[$bucket][$a->subtype->value] ??= ['label' => $a->subtype->label(), 'rows' => []];
        $groups[$bucket][$a->subtype->value]['rows'][] = ['name' => $a->name, 'code' => $a->code, 'balance' => $balance];
        $totals[$bucket] += $balance;
    }
    $ni = $calc->netIncomeYtd($s['company'], $asOf);
    $report = [
        'assets' => bsGroupsToBlocks($groups['assets']), 'liabilities' => bsGroupsToBlocks($groups['liabilities']), 'equity' => bsGroupsToBlocks($groups['equity']),
        'total_assets' => $totals['assets'], 'total_liabilities' => $totals['liabilities'], 'total_equity' => $totals['equity'],
        'net_income_ytd' => $ni, 'total_le' => $totals['liabilities'] + $totals['equity'] + $ni,
    ];

    $response = app(XlsxExporter::class)->balanceSheet('bs.xlsx', $s['company'], $report, '2026-04-01');
    $path = $response->getFile()->getRealPath();
    $joined = implode("\n", readXlsxStrings($path));

    expect($joined)
        ->toContain('Balance Sheet')
        ->toContain('Generated ')
        ->toContain('Total Liabilities & Equity')
        ->toContain('=D'); // formula present

    @unlink($path);
    app()->forgetInstance('current_company');
});

it('xlsx: sales tax includes net = collected - paid formula', function () {
    $s = reportsScenario();

    $rows = [[
        'agency' => 'CRA', 'payable_account' => '2200 — GST/HST Payable',
        'collected' => 5000, 'paid' => 1500, 'net' => 3500,
    ]];

    $response = app(XlsxExporter::class)->salesTax('tax.xlsx', $s['company'], $rows, '2026-01-01', '2026-12-31');
    $path = $response->getFile()->getRealPath();
    $joined = implode("\n", readXlsxStrings($path));

    expect($joined)
        ->toContain('Sales Tax')
        ->toContain('Generated ')
        ->toContain('=C')   // per-row C-D formula
        ->toContain('=SUM(C');

    @unlink($path);
    app()->forgetInstance('current_company');
});

it('xlsx: sales tax detail lists each source document with a SUM total', function () {
    $s = reportsScenario();
    $calc = app(ReportCalculator::class);

    $lines = $calc->salesTaxLines(
        $s['gst']->agency,
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 12, 31),
    )->where('bucket', 'collected')->values();

    $response = app(XlsxExporter::class)->salesTaxDetail(
        'tax-detail.xlsx',
        $s['company'],
        $s['gst']->agency->name,
        'collected',
        $lines,
        '2026-01-01',
        '2026-12-31',
    );

    $path = $response->getFile()->getRealPath();
    $joined = implode("\n", readXlsxStrings($path));

    expect($joined)
        ->toContain('Sales Tax — Collected on sales')
        ->toContain('INV-R-1')
        ->toContain('=SUM(C');

    @unlink($path);
    app()->forgetInstance('current_company');
});

it('xlsx: AR aging uses SUM for both row totals and column totals', function () {
    $s = reportsScenario();

    $report = [
        'rows' => [
            ['contact_id' => 1, 'name' => 'Customer A', 'current' => 10000, 'b1_30' => 5000, 'b31_60' => 0, 'b61_90' => 0, 'b90_plus' => 0, 'total' => 15000],
            ['contact_id' => 2, 'name' => 'Customer B', 'current' => 0, 'b1_30' => 0, 'b31_60' => 2500, 'b61_90' => 0, 'b90_plus' => 0, 'total' => 2500],
        ],
        'totals' => ['current' => 10000, 'b1_30' => 5000, 'b31_60' => 2500, 'b61_90' => 0, 'b90_plus' => 0, 'total' => 17500],
    ];

    $response = app(XlsxExporter::class)->aging('ar.xlsx', 'AR Aging', 'Customer', $s['company'], $report, '2026-04-01');
    $path = $response->getFile()->getRealPath();
    $joined = implode("\n", readXlsxStrings($path));

    expect($joined)
        ->toContain('AR Aging')
        ->toContain('Generated ')
        ->toContain('=SUM(B')   // per-row totals
        ->toContain('Total');

    @unlink($path);
    app()->forgetInstance('current_company');
});

it('xlsx: income statement net income chains the section totals', function () {
    $s = reportsScenario();

    $report = [
        'income' => isBucketToBlocks([['code' => '4100', 'name' => 'Services', 'current' => 100000, 'prior' => 0]]),
        'cogs' => isBucketToBlocks([]),
        'expense' => isBucketToBlocks([['code' => '5100', 'name' => 'Supplies', 'current' => 30000, 'prior' => 0]]),
        'total_income' => 100000, 'total_cogs' => 0, 'total_expense' => 30000,
        'gross_profit' => 100000, 'net_income' => 70000,
        'prior_total_income' => 0, 'prior_total_cogs' => 0, 'prior_total_expense' => 0,
        'prior_gross_profit' => 0, 'prior_net_income' => 0,
    ];

    $response = app(XlsxExporter::class)->incomeStatement('is.xlsx', $s['company'], $report, '2026-01-01', '2026-12-31', false);
    $path = $response->getFile()->getRealPath();
    $joined = implode("\n", readXlsxStrings($path));

    expect($joined)
        ->toContain('Income Statement')
        ->toContain('Generated ')
        ->toContain('NET INCOME')
        ->toContain('=D')   // live cell-reference formulas for the bucket totals
        ->toContain('-D');  // net income chains them: income total − expense total

    @unlink($path);
    app()->forgetInstance('current_company');
});

/**
 * Render a view via DomPDF and assert it produced a real PDF (starts with %PDF-).
 * Catches Blade/template errors and broken references for every report.
 */
function assertPdfRenders(string $view, array $data): void
{
    $pdf = Pdf::loadView($view, $data);
    $bytes = $pdf->output();
    expect(substr($bytes, 0, 5))->toBe('%PDF-');
    expect(strlen($bytes))->toBeGreaterThan(500);
}

it('pdf: trial balance renders', function () {
    $s = reportsScenario();
    $calc = app(ReportCalculator::class);
    $tb = $calc->trialBalance($s['company'], CarbonImmutable::create(2026, 4, 1));
    $rows = [];
    $totalDr = 0;
    $totalCr = 0;
    foreach ($tb as $r) {
        $bal = $r['balance'];
        if ($r['account']->normal_balance->value === 'debit') {
            $debit = $bal > 0 ? $bal : 0;
            $credit = $bal < 0 ? -$bal : 0;
        } else {
            $credit = $bal > 0 ? $bal : 0;
            $debit = $bal < 0 ? -$bal : 0;
        }
        $rows[] = ['code' => $r['account']->code, 'name' => $r['account']->name, 'type' => $r['account']->type->label(), 'debit' => $debit, 'credit' => $credit];
        $totalDr += $debit;
        $totalCr += $credit;
    }
    $report = ['rows' => $rows, 'totals' => ['debit' => $totalDr, 'credit' => $totalCr]];

    assertPdfRenders('pdf.reports.trial-balance', [
        'company' => $s['company'], 'report' => $report, 'asOf' => '2026-04-01',
    ]);

    app()->forgetInstance('current_company');
});

it('pdf: balance sheet renders', function () {
    $s = reportsScenario();
    $calc = app(ReportCalculator::class);
    $asOf = CarbonImmutable::create(2026, 4, 1);
    $accounts = Account::withoutGlobalScopes()
        ->where('company_id', $s['company']->id)
        ->whereIn('type', [AccountType::Asset->value, AccountType::Liability->value, AccountType::Equity->value])
        ->orderBy('code')->get();
    $groups = ['assets' => [], 'liabilities' => [], 'equity' => []];
    $totals = ['assets' => 0, 'liabilities' => 0, 'equity' => 0];
    foreach ($accounts as $a) {
        $bal = $calc->balanceAsOf($a, $asOf);
        if ($bal === 0) {
            continue;
        }
        $bucket = match ($a->type) {
            AccountType::Asset => 'assets',
            AccountType::Liability => 'liabilities',
            AccountType::Equity => 'equity',
            default => null,
        };
        if (! $bucket) {
            continue;
        }
        $groups[$bucket][$a->subtype->value] ??= ['label' => $a->subtype->label(), 'rows' => []];
        $groups[$bucket][$a->subtype->value]['rows'][] = ['name' => $a->name, 'code' => $a->code, 'balance' => $bal];
        $totals[$bucket] += $bal;
    }
    $ni = $calc->netIncomeYtd($s['company'], $asOf);
    $report = [
        'assets' => bsGroupsToBlocks($groups['assets']), 'liabilities' => bsGroupsToBlocks($groups['liabilities']), 'equity' => bsGroupsToBlocks($groups['equity']),
        'total_assets' => $totals['assets'], 'total_liabilities' => $totals['liabilities'], 'total_equity' => $totals['equity'],
        'net_income_ytd' => $ni, 'total_le' => $totals['liabilities'] + $totals['equity'] + $ni,
    ];

    assertPdfRenders('pdf.reports.balance-sheet', [
        'company' => $s['company'], 'report' => $report, 'asOf' => '2026-04-01',
    ]);

    app()->forgetInstance('current_company');
});

it('pdf: income statement renders with and without comparison', function () {
    $s = reportsScenario();
    $report = [
        'income' => isBucketToBlocks([['code' => '4100', 'name' => 'Services', 'current' => 100000, 'prior' => 0]]),
        'cogs' => isBucketToBlocks([]),
        'expense' => isBucketToBlocks([['code' => '5100', 'name' => 'Supplies', 'current' => 30000, 'prior' => 0]]),
        'total_income' => 100000, 'total_cogs' => 0, 'total_expense' => 30000,
        'gross_profit' => 100000, 'net_income' => 70000,
        'prior_total_income' => 0, 'prior_total_cogs' => 0, 'prior_total_expense' => 0,
        'prior_gross_profit' => 0, 'prior_net_income' => 0,
    ];

    foreach ([false, true] as $compare) {
        assertPdfRenders('pdf.reports.income-statement', [
            'company' => $s['company'], 'report' => $report,
            'startDate' => '2026-01-01', 'endDate' => '2026-12-31',
            'showComparison' => $compare,
        ]);
    }

    app()->forgetInstance('current_company');
});

it('pdf: general ledger (single account) renders', function () {
    $s = reportsScenario();
    $calc = app(ReportCalculator::class);
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $gl = $calc->generalLedger($ar, CarbonImmutable::create(2026, 1, 1), CarbonImmutable::create(2026, 12, 31));

    assertPdfRenders('pdf.reports.general-ledger', [
        'company' => $s['company'], 'account' => $ar, 'report' => $gl,
        'startDate' => '2026-01-01', 'endDate' => '2026-12-31',
    ]);

    app()->forgetInstance('current_company');
});

it('pdf: general ledger (all accounts) renders', function () {
    $s = reportsScenario();
    $gl = app(ReportCalculator::class)->generalLedgerAllAccounts(
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 12, 31),
    );

    assertPdfRenders('pdf.reports.general-ledger-all', [
        'company' => $s['company'], 'report' => $gl,
        'startDate' => '2026-01-01', 'endDate' => '2026-12-31',
    ]);

    app()->forgetInstance('current_company');
});

it('pdf: AR and AP aging templates render', function () {
    $s = reportsScenario();
    $report = [
        'rows' => [
            ['contact_id' => 1, 'name' => 'Customer A', 'current' => 10000, 'b1_30' => 5000, 'b31_60' => 0, 'b61_90' => 0, 'b90_plus' => 0, 'total' => 15000],
        ],
        'totals' => ['current' => 10000, 'b1_30' => 5000, 'b31_60' => 0, 'b61_90' => 0, 'b90_plus' => 0, 'total' => 15000],
    ];

    assertPdfRenders('pdf.reports.aging', [
        'company' => $s['company'], 'title' => 'AR Aging', 'entityLabel' => 'Customer',
        'emptyMessage' => 'No open invoices as of this date.',
        'report' => $report, 'asOf' => '2026-04-01',
    ]);

    assertPdfRenders('pdf.reports.aging', [
        'company' => $s['company'], 'title' => 'AP Aging', 'entityLabel' => 'Vendor',
        'emptyMessage' => 'No open bills as of this date.',
        'report' => $report, 'asOf' => '2026-04-01',
    ]);

    app()->forgetInstance('current_company');
});
