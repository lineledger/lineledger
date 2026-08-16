<?php

use App\Actions\Reporting\SeedReportGroupMappings;
use App\Enums\AccountType;
use App\Models\Company;
use App\Models\ReportGroup;
use App\Models\User;
use App\Services\Reporting\CombinedReportCalculator;
use App\Services\Reporting\XlsxExporter;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;

/**
 * Render a combined-report Blade view through DomPDF and assert real PDF bytes.
 */
function assertCombinedPdfRenders(string $view, array $data): void
{
    $bytes = Pdf::loadView($view, $data)->output();
    expect(substr($bytes, 0, 5))->toBe('%PDF-');
    expect(strlen($bytes))->toBeGreaterThan(500);
}

/**
 * Read every string / shared-string / formula out of an xlsx as a flat array.
 *
 * @return array<int, string>
 */
function combinedXlsxStrings(string $path): array
{
    $zip = new ZipArchive;
    expect($zip->open($path))->toBe(true);

    $decode = fn (string $s): string => html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8');

    $shared = [];
    $sst = $zip->getFromName('xl/sharedStrings.xml');
    if ($sst !== false && preg_match_all('/<t[^>]*>([^<]*)<\/t>/', $sst, $m)) {
        $shared = array_map($decode, $m[1]);
    }

    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
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

    $zip->close();

    return $values;
}

/**
 * A creator who belongs to two companies with a posted ledger and an auto-seeded
 * report group. Reuses helpers from CombinedReportsTest / ReportGroupManagementTest.
 *
 * @return array{user: User, group: ReportGroup, a: Company, b: Company}
 */
function combinedPageScenario(): array
{
    [$user, $companies] = userWithCompanies(2);
    $a = $companies[0];
    $b = $companies[1];

    $bankA = acctOfType($a, AccountType::Asset);
    $incomeA = acctOfType($a, AccountType::Income);
    $bankB = acctOfType($b, AccountType::Asset);
    $incomeB = acctOfType($b, AccountType::Income);

    postEntry($a, '2026-03-01', [['account' => $bankA, 'debit' => 100000], ['account' => $incomeA, 'credit' => 100000]]);
    postEntry($b, '2026-03-01', [['account' => $bankB, 'debit' => 50000], ['account' => $incomeB, 'credit' => 50000]]);

    $group = ReportGroup::create(['user_id' => $user->id, 'name' => 'Combined', 'currency_code' => 'CAD']);
    $group->companies()->attach([$a->id, $b->id]);
    app(SeedReportGroupMappings::class)->handle($group);

    return compact('user', 'group', 'a', 'b');
}

it('renders the combined balance sheet, income statement, and trial balance', function () {
    $s = combinedPageScenario();
    $this->actingAs($s['user']);

    $this->get(route('report-groups.balance-sheet', $s['group']))->assertOk()->assertSee('Combined Balance Sheet');
    $this->get(route('report-groups.income-statement', $s['group']))->assertOk()->assertSee('Combined Income Statement');
    $this->get(route('report-groups.trial-balance', $s['group']))->assertOk()->assertSee('Combined Trial Balance');
});

it('shows a currency-mismatch warning when members diverge', function () {
    $s = combinedPageScenario();
    $s['b']->update(['currency_code' => 'USD']);

    $this->actingAs($s['user'])
        ->get(route('report-groups.balance-sheet', $s['group']))
        ->assertOk()
        ->assertSee('mix currencies');
});

it('renders combined report PDFs', function () {
    $s = combinedPageScenario();
    $calc = app(CombinedReportCalculator::class);
    $asOf = CarbonImmutable::create(2026, 4, 1);

    assertCombinedPdfRenders('pdf.reports.combined-balance-sheet', [
        'group' => $s['group'], 'report' => $calc->balanceSheet($s['group'], $asOf), 'asOf' => '2026-04-01',
    ]);

    assertCombinedPdfRenders('pdf.reports.combined-income-statement', [
        'group' => $s['group'],
        'report' => $calc->incomeStatement($s['group'], CarbonImmutable::create(2026, 1, 1), $asOf),
        'startDate' => '2026-01-01', 'endDate' => '2026-04-01',
    ]);

    assertCombinedPdfRenders('pdf.reports.combined-trial-balance', [
        'group' => $s['group'], 'report' => $calc->trialBalance($s['group'], $asOf), 'asOf' => '2026-04-01',
    ]);
});

it('exports combined report XLSX files with per-company columns', function () {
    $s = combinedPageScenario();
    $calc = app(CombinedReportCalculator::class);
    $exporter = app(XlsxExporter::class);
    $asOf = CarbonImmutable::create(2026, 4, 1);

    // Balance sheet, by-company: expect each company name + Combined as column headers.
    $response = $exporter->combinedBalanceSheet('bs.xlsx', $s['group'], $calc->balanceSheet($s['group'], $asOf), '2026-04-01', byCompany: true);
    $path = $response->getFile()->getRealPath();

    expect(file_get_contents($path, false, null, 0, 2))->toBe('PK');
    $strings = combinedXlsxStrings($path);
    expect($strings)->toContain($s['a']->name)
        ->toContain($s['b']->name)
        ->toContain('Combined');
    expect(collect($strings)->contains(fn ($v) => str_starts_with($v, '=')))->toBeTrue(); // live formulas
    @unlink($path);

    // Income statement, combined-only.
    $response = $exporter->combinedIncomeStatement('is.xlsx', $s['group'], $calc->incomeStatement($s['group'], CarbonImmutable::create(2026, 1, 1), $asOf), '2026-01-01', '2026-04-01', byCompany: false);
    $path = $response->getFile()->getRealPath();
    expect(file_get_contents($path, false, null, 0, 2))->toBe('PK');
    expect(combinedXlsxStrings($path))->toContain('Combined Income Statement')->toContain('NET INCOME');
    @unlink($path);

    // Trial balance: per-company sections, always balanced.
    $response = $exporter->combinedTrialBalance('tb.xlsx', $s['group'], $calc->trialBalance($s['group'], $asOf), '2026-04-01');
    $path = $response->getFile()->getRealPath();
    expect(file_get_contents($path, false, null, 0, 2))->toBe('PK');
    expect(combinedXlsxStrings($path))->toContain($s['a']->name)->toContain('Total');
    @unlink($path);
});
