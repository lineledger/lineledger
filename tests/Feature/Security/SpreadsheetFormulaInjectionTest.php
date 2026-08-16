<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\XlsxExporter;

/**
 * M2: report exports must not turn attacker-entered text (memos, names) into
 * live spreadsheet formulas (CWE-1236).
 */
it('neutralizes formula-triggering CSV cells but preserves numbers', function (string $input, string $expected) {
    expect(CsvExporter::neutralize($input))->toBe($expected);
})->with([
    'formula' => ['=1+1', "'=1+1"],
    'cmd' => ['=cmd|\'/c calc\'!A1', "'=cmd|'/c calc'!A1"],
    'plus' => ['+1+1', "'+1+1"],
    'at' => ['@SUM(A1)', "'@SUM(A1)"],
    'negative number kept' => ['-5.00', '-5.00'],
    'positive number kept' => ['5.00', '5.00'],
    'plain text kept' => ['Acme Inc', 'Acme Inc'],
]);

it('writes a malicious account name as inert text in XLSX, not a formula', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $company);

    $payload = '=HYPERLINK("http://evil.test","click")';

    $report = [
        'rows' => [
            ['code' => '1000', 'name' => $payload, 'type' => 'asset', 'debit' => 500, 'credit' => 0],
        ],
        'totals' => ['debit' => 500, 'credit' => 0],
    ];

    $response = app(XlsxExporter::class)->trialBalance('tb.xlsx', $company, $report, '2026-05-20');

    $path = $response->getFile()->getPathname();
    $zip = new ZipArchive;
    $zip->open($path);
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    // The payload must be present as an inline/shared string, never inside an
    // <f> formula element. Our own =SUM() totals still appear as <f>.
    expect($sheetXml)->not->toContain('<f>'.htmlspecialchars($payload, ENT_QUOTES));
    expect($sheetXml)->toContain('<f>'); // legitimate SUM totals remain formulas

    app()->forgetInstance('current_company');
});
