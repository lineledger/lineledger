<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Services\Posting\InvoicePoster;
use App\Services\Reporting\Render\ReportRenderer;
use App\Support\Reporting\RenderableReports;
use Carbon\CarbonImmutable;
use OpenSpout\Reader\XLSX\Reader;

afterEach(fn () => app()->forgetInstance('current_company'));

function rendererPostInvoice(Company $company, string $customerName, string $invoiceNo): void
{
    app()->instance('current_company', $company);

    $customer = Contact::create(['display_name' => $customerName, 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => $invoiceNo,
        'invoice_date' => now()->subDays(10)->toDateString(),
        'due_date' => now()->addDays(20)->toDateString(),
    ]);

    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'x',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($invoice);

    app()->forgetInstance('current_company');
}

/**
 * @return list<string> every cell value in the workbook
 */
function rendererXlsxCells(string $bytes): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx-test-');
    file_put_contents($tmp, $bytes);

    $cells = [];
    $reader = new Reader;
    $reader->open($tmp);

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->toArray() as $value) {
                $cells[] = (string) $value;
            }
        }
    }

    $reader->close();
    unlink($tmp);

    return $cells;
}

it('renders every registry report to valid bytes in each supported format', function () {
    // features_membership on so the membership-roster report (which 403s otherwise)
    // is exercised alongside the rest.
    $company = Company::factory()->create(['fiscal_year_start_month' => 1, 'features_membership' => true]);
    $renderer = app(ReportRenderer::class);

    foreach (RenderableReports::all() as $key => $entry) {
        foreach ($entry['formats'] as $format) {
            try {
                $artifact = $renderer->render($company, $key, [], $format);
            } catch (Throwable $e) {
                $this->fail("{$key} [{$format}] failed to render: {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}");
            }

            $expectedPrefix = $format === 'pdf' ? '%PDF' : "PK\x03\x04";

            expect(substr($artifact->bytes, 0, strlen($expectedPrefix)))
                ->toBe($expectedPrefix, "{$key} did not render valid {$format} bytes");
            expect($artifact->filename)->toEndWith('.'.$format);
        }
    }
});

it('applies a settings snapshot to the component before rendering', function () {
    $company = Company::factory()->create(['fiscal_year_start_month' => 1]);

    $artifact = app(ReportRenderer::class)->render($company, 'reports.income-statement', [
        'preset' => 'custom',
        'startDate' => '2025-03-01',
        'endDate' => '2025-03-31',
    ], 'pdf');

    expect($artifact->filename)->toBe('income-statement-2025-03-01-2025-03-31.pdf');
});

it('re-resolves a saved date preset against today when asked', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-09 12:00:00'));

    $company = Company::factory()->create(['fiscal_year_start_month' => 1]);

    $settings = [
        'preset' => 'last_month',
        'startDate' => '2020-01-01',
        'endDate' => '2020-01-31',
    ];

    $stale = app(ReportRenderer::class)->render($company, 'reports.income-statement', $settings, 'pdf');
    expect($stale->filename)->toBe('income-statement-2020-01-01-2020-01-31.pdf');

    $fresh = app(ReportRenderer::class)->render($company, 'reports.income-statement', $settings, 'pdf', resolvePresets: true);
    expect($fresh->filename)->toBe('income-statement-2026-05-01-2026-05-31.pdf');
});

it('scopes the render to the given company and restores the previous binding', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    rendererPostInvoice($companyA, 'Alpha Customer Inc', 'INV-A-1');
    rendererPostInvoice($companyB, 'Bravo Customer Ltd', 'INV-B-1');

    // Simulate a request for company B while rendering company A's report.
    app()->instance('current_company', $companyB);

    $artifact = app(ReportRenderer::class)->render($companyA, 'reports.ar-aging', [], 'xlsx');

    $cells = rendererXlsxCells($artifact->bytes);

    expect(implode("\n", $cells))
        ->toContain('Alpha Customer Inc')
        ->not->toContain('Bravo Customer Ltd');

    expect(app('current_company')->is($companyB))->toBeTrue();
});

it('rejects reports and formats outside the registry', function () {
    $company = Company::factory()->create();

    expect(fn () => app(ReportRenderer::class)->render($company, 'reports.nope', [], 'pdf'))
        ->toThrow(InvalidArgumentException::class);

    // The general ledger page has no PDF export.
    expect(fn () => app(ReportRenderer::class)->render($company, 'reports.general-ledger', [], 'pdf'))
        ->toThrow(InvalidArgumentException::class);
});
