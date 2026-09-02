<?php

use App\Models\Company;
use App\Models\ReportPackage;
use App\Models\ReportPackageItem;
use App\Models\User;
use App\Services\Pdf\PdfMerger;
use App\Services\Reporting\Render\ManagementReportBuilder;
use App\Services\Reporting\Render\ReportRenderer;
use App\Support\Reporting\ComparisonPeriod;
use App\Support\Reporting\ReportCatalog;
use App\Support\Reporting\ReportDatePresets;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Smalot\PdfParser\Parser;

beforeEach(function () {
    // A short, fixed name keeps PDF subtitles on one line — a long random name
    // wraps at a hyphen inside a date and breaks the text assertions.
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1, 'name' => 'Board Pack Co']);
    $this->user = User::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function makePackage(Company $company, User $user, array $reportKeys, array $attributes = []): ReportPackage
{
    $package = ReportPackage::create(array_merge([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'name' => 'Board Pack',
        'period_preset' => 'last_month',
    ], $attributes));

    foreach ($reportKeys as $index => $key) {
        $package->items()->create([
            'company_id' => $company->id,
            'report_key' => $key,
            'sort_order' => $index,
        ]);
    }

    return $package->load('items');
}

/** Whitespace-normalized text of a PDF, so assertions survive line wrapping. */
function pdfPackageText(string $bytes): string
{
    return (string) preg_replace('/\s+/', ' ', (new Parser)->parseContent($bytes)->getText());
}

// --- PDF merge spike: FPDI must accept dompdf output ---

it('merges two dompdf-rendered report PDFs into one document', function () {
    $renderer = app(ReportRenderer::class);

    $a = $renderer->render($this->company, 'reports.income-statement', [
        'preset' => 'custom', 'startDate' => '2026-01-01', 'endDate' => '2026-03-31',
    ], 'pdf');
    $b = $renderer->render($this->company, 'reports.balance-sheet', [
        'asOfPreset' => 'custom', 'asOf' => '2026-03-31',
    ], 'pdf');

    $merger = app(PdfMerger::class);
    $merged = $merger->merge($a->bytes, $b->bytes);

    expect(substr($merged, 0, 4))->toBe('%PDF')
        ->and($merger->pageCount($merged))
        ->toBe($merger->pageCount($a->bytes) + $merger->pageCount($b->bytes));
});

// --- Builder ---

it('builds a package PDF with cover, TOC, and the reports for the period', function () {
    $package = makePackage($this->company, $this->user, ['reports.income-statement', 'reports.balance-sheet']);

    $artifact = app(ManagementReportBuilder::class)->build($package);

    $today = $this->company->currentDateTime();
    [$start, $end] = ReportDatePresets::resolve('last_month', 1, $today);

    expect(substr($artifact->bytes, 0, 4))->toBe('%PDF')
        ->and($artifact->mime)->toBe('application/pdf')
        ->and($artifact->filename)->toBe('board-pack-'.$start->toDateString().'-'.$end->toDateString().'.pdf')
        // Cover + TOC + at least one page per report.
        ->and(app(PdfMerger::class)->pageCount($artifact->bytes))->toBeGreaterThanOrEqual(4);
});

it('adds preliminary and end-notes pages and respects show flags', function () {
    $bare = makePackage($this->company, $this->user, ['reports.income-statement'], [
        'show_cover' => false,
        'show_toc' => false,
    ]);
    $full = makePackage($this->company, $this->user, ['reports.income-statement'], [
        'name' => 'Full Pack',
        'preliminary_text' => 'A note from management.',
        'end_notes' => 'Prepared without audit.',
    ]);

    $merger = app(PdfMerger::class);
    $barePages = $merger->pageCount(app(ManagementReportBuilder::class)->build($bare)->bytes);
    $fullPages = $merger->pageCount(app(ManagementReportBuilder::class)->build($full)->bytes);

    // Full adds cover + TOC + preliminary + end notes = 4 extra pages.
    expect($fullPages)->toBe($barePages + 4);
});

it('skips non-renderable items but throws when nothing is renderable', function () {
    // general-ledger is xlsx-only; nonexistent key is unknown — both skipped.
    $mixed = makePackage($this->company, $this->user, [
        'reports.general-ledger', 'reports.income-statement', 'reports.nonexistent',
    ]);

    $artifact = app(ManagementReportBuilder::class)->build($mixed);
    expect(substr($artifact->bytes, 0, 4))->toBe('%PDF');

    $hopeless = makePackage($this->company, $this->user, ['reports.general-ledger'], ['name' => 'Hopeless']);

    expect(fn () => app(ManagementReportBuilder::class)->build($hopeless))
        ->toThrow(RuntimeException::class);
});

it('overlays the package period and comparison onto stale memorized settings', function () {
    $settings = [
        'preset' => 'last_fiscal_year',
        'startDate' => '2020-01-01',
        'endDate' => '2020-12-31',
        'asOf' => '2020-12-31',
        'asOfPreset' => 'last_fiscal_year',
        'comparisonBasis' => ComparisonPeriod::PriorYear,
        'classId' => 5,
    ];

    $result = app(ManagementReportBuilder::class)->overlayPeriod(
        $settings,
        'last_month',
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-05-31'),
        ComparisonPeriod::PriorPeriod,
    );

    expect($result['startDate'])->toBe('2026-05-01')
        ->and($result['endDate'])->toBe('2026-05-31')
        // The real preset key is kept (not 'custom') so a "prior period"
        // comparison resolves to the true preceding month.
        ->and($result['preset'])->toBe('last_month')
        ->and($result['asOf'])->toBe('2026-05-31')
        ->and($result['asOfPreset'])->toBe('last_month')
        ->and($result['comparisonBasis'])->toBe(ComparisonPeriod::PriorPeriod)
        // Non-date settings (filters, presentation) survive the overlay.
        ->and($result['classId'])->toBe(5);
});

it('builds a legacy This Fiscal Year package as year-to-date, so aging is as of today', function () {
    // August fiscal year: "This Fiscal Year" would end 2027-07-31 — eleven
    // months out — and age every open invoice into 90+.
    $this->travelTo(CarbonImmutable::parse('2026-09-01 10:00:00'));
    $this->company->update(['fiscal_year_start_month' => 8]);

    $package = makePackage($this->company, $this->user, ['reports.ar-aging'], ['period_preset' => 'this_fiscal_year']);

    $artifact = app(ManagementReportBuilder::class)->build($package);
    $text = pdfPackageText($artifact->bytes);

    expect($artifact->filename)->toBe('board-pack-2026-08-01-2026-09-01.pdf')
        ->and($text)->toContain('as of 2026-09-01')
        ->and($text)->not->toContain('2027-07-31');
});

it('passes the comparison basis and real preset through so prior dates resolve per report', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 10:00:00'));

    $package = makePackage($this->company, $this->user, ['reports.income-statement', 'reports.balance-sheet'], [
        'period_preset' => 'last_month',
        'comparison_basis' => ComparisonPeriod::PriorPeriod,
    ]);
    $build = fn (): string => pdfPackageText(app(ManagementReportBuilder::class)->build($package)->bytes);

    // Last month is August; the preceding period is July, and the balance
    // sheet compares against the July month-end.
    expect($build())
        ->toContain('Compared to prior period')
        ->toContain('compared to 2026-07-01 to 2026-07-31 (prior period)')
        ->toContain('compared to 2026-07-31 (prior period)');

    $package->update(['comparison_basis' => ComparisonPeriod::PriorYear]);

    expect($build())
        ->toContain('Compared to prior year')
        ->toContain('compared to 2025-08-01 to 2025-08-31 (prior year)')
        ->toContain('compared to 2025-08-31 (prior year)');

    $package->update(['comparison_basis' => ComparisonPeriod::Off]);

    expect($build())->not->toContain('ompared to');
});

it('puts the document logo on the cover', function () {
    Storage::fake('public');

    // Companies typically upload only a document logo; the sidebar logo stays empty.
    $this->company->update([
        'logo_path' => null,
        'document_logo_path' => UploadedFile::fake()->image('doc-logo.png')->store('company-logos', 'public'),
    ]);

    $images = fn (ReportPackage $package): int => count(
        (new Parser)->parseContent(app(ManagementReportBuilder::class)->build($package)->bytes)->getObjectsByType('XObject', 'Image')
    );

    $withLogo = makePackage($this->company, $this->user, ['reports.income-statement']);
    $withoutLogo = makePackage($this->company, $this->user, ['reports.income-statement'], ['name' => 'Plain', 'show_logo' => false]);

    // Nothing else in a package embeds an image, so the cover logo is the only one.
    expect($images($withLogo))->toBeGreaterThanOrEqual(1)
        ->and($images($withoutLogo))->toBe(0);
});

// --- Page CRUD ---

it('creates a package with ordered items through the page', function () {
    Livewire::actingAs($this->user)
        ->test('pages::reports.management', ['company' => $this->company])
        ->call('openCreate')
        ->set('name', 'Month End')
        ->set('periodPreset', 'last_month')
        ->set('comparisonBasis', ComparisonPeriod::PriorYear)
        ->set('newItemKey', 'reports.income-statement')
        ->call('addItem')
        ->set('newItemKey', 'reports.balance-sheet')
        ->call('addItem')
        ->call('save')
        ->assertHasNoErrors();

    $package = ReportPackage::query()->where('user_id', $this->user->id)->with('items')->first();

    expect($package)->not->toBeNull()
        ->and($package->name)->toBe('Month End')
        ->and($package->comparison_basis)->toBe(ComparisonPeriod::PriorYear)
        ->and($package->items->pluck('report_key')->all())->toBe(['reports.income-statement', 'reports.balance-sheet'])
        ->and($package->items->pluck('sort_order')->all())->toBe([0, 1]);
});

it('offers only completed and to-date periods and rejects an unknown comparison basis', function () {
    $component = Livewire::actingAs($this->user)
        ->test('pages::reports.management', ['company' => $this->company]);

    expect(array_keys($component->instance()->presetOptions()))->toBe([
        'last_month', 'last_fiscal_quarter', 'last_fiscal_year',
        'this_month_to_date', 'this_fiscal_quarter_to_date', 'this_fiscal_year_to_date',
    ]);

    $component->call('openCreate')
        ->set('name', 'Bad')
        ->set('periodPreset', 'this_fiscal_year')
        ->set('comparisonBasis', 'budget')
        ->call('save')
        ->assertHasErrors(['periodPreset', 'comparisonBasis']);
});

it('opens a legacy This Fiscal Year package as year-to-date and shows its comparison', function () {
    $package = makePackage($this->company, $this->user, ['reports.income-statement'], [
        'period_preset' => 'this_fiscal_year',
        'comparison_basis' => ComparisonPeriod::PriorYear,
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::reports.management', ['company' => $this->company])
        ->assertSee('This Fiscal Year-to-date · vs prior year')
        ->call('openEdit', $package->id)
        ->assertSet('periodPreset', 'this_fiscal_year_to_date')
        ->assertSet('comparisonBasis', ComparisonPeriod::PriorYear)
        ->call('save')
        ->assertHasNoErrors();

    expect($package->refresh()->period_preset)->toBe('this_fiscal_year_to_date');
});

it('offers the Membership List report only when the company tracks membership', function () {
    $this->company->update(['features_membership' => false]);

    $options = Livewire::actingAs($this->user)
        ->test('pages::reports.management', ['company' => $this->company])
        ->instance()->reportOptions();

    expect($options)->not->toHaveKey('reports.membership-roster');

    $this->company->update(['features_membership' => true]);

    $options = Livewire::actingAs($this->user)
        ->test('pages::reports.management', ['company' => $this->company->fresh()])
        ->instance()->reportOptions();

    expect($options)->toHaveKey('reports.membership-roster')
        ->and($options['reports.membership-roster'])->toBe('Membership List');
});

it('reorders items in the editor and persists the new order', function () {
    $package = makePackage($this->company, $this->user, ['reports.income-statement', 'reports.balance-sheet']);

    Livewire::actingAs($this->user)
        ->test('pages::reports.management', ['company' => $this->company])
        ->call('openEdit', $package->id)
        ->assertSet('editItems', ['reports.income-statement', 'reports.balance-sheet'])
        ->call('moveItemUp', 1)
        ->call('save')
        ->assertHasNoErrors();

    expect($package->refresh()->items->pluck('report_key')->all())
        ->toBe(['reports.balance-sheet', 'reports.income-statement']);
});

it('deletes a package and its items', function () {
    $package = makePackage($this->company, $this->user, ['reports.income-statement']);

    Livewire::actingAs($this->user)
        ->test('pages::reports.management', ['company' => $this->company])
        ->call('delete', $package->id);

    expect(ReportPackage::query()->find($package->id))->toBeNull()
        ->and(ReportPackageItem::query()->where('report_package_id', $package->id)->count())->toBe(0);
});

it('will not edit or delete another user\'s package', function () {
    $other = User::factory()->create();
    $package = makePackage($this->company, $other, ['reports.income-statement'], ['name' => 'Theirs']);

    Livewire::actingAs($this->user)
        ->test('pages::reports.management', ['company' => $this->company])
        ->call('openEdit', $package->id)
        ->assertSet('editingId', null)
        ->call('delete', $package->id);

    expect(ReportPackage::query()->find($package->id))->not->toBeNull();
});

// --- Download action ---

it('downloads a package as a single PDF', function () {
    $package = makePackage($this->company, $this->user, ['reports.income-statement', 'reports.balance-sheet']);

    $component = Livewire::actingAs($this->user)
        ->test('pages::reports.management', ['company' => $this->company])
        ->call('download', $package->id)
        ->assertHasNoErrors();

    expect(data_get($component->effects, 'download.name'))->toEndWith('.pdf');
});

it('surfaces a toast instead of crashing when nothing in the package is renderable', function () {
    $package = makePackage($this->company, $this->user, ['reports.general-ledger'], ['name' => 'Hopeless']);

    $component = Livewire::actingAs($this->user)
        ->test('pages::reports.management', ['company' => $this->company])
        ->call('download', $package->id)
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    expect(data_get($component->effects, 'download'))->toBeNull();
});

// --- Catalog ---

it('lists Management Reports in the report catalog', function () {
    $catalog = ReportCatalog::flatten($this->company, $this->user);

    expect($catalog)->toHaveKey('reports.management')
        ->and($catalog['reports.management']['label'])->toBe('Management Reports');
});
