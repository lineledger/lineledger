<?php

use App\Actions\Accounting\SaveStatutoryMapping;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\Country;
use App\Enums\Section;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Pdf\PdfMerger;
use App\Services\Posting\JournalPoster;
use App\Services\Reporting\UkStatutoryAccountsBuilder;
use App\Services\Reporting\UkStatutoryAccountsDocx;
use App\Services\Reporting\UkStatutoryAccountsPdf;
use App\Support\Reporting\ReportCatalog;
use App\Support\Statutory\StatutoryAccountsProfile;
use App\Support\Statutory\UkStatutoryCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mockery\MockInterface;
use Smalot\PdfParser\Parser;

/*
|--------------------------------------------------------------------------
| UK statutory accounts (FRS 102 Section 1A / FRS 105)
|--------------------------------------------------------------------------
|
| A small UK company on a 5 April year end trades through two years. Every
| figure below is worked by hand; the pence are there to prove each line is
| rounded on its own and the statements still cast.
|
| Year to 5 April 2024 (prior)            Year to 5 April 2025 (current)
|   Shares issued        100.00             Sales (on credit) 20,000.00 + VAT 4,000
|   Sales             10,000.40             Cash received     20,000.00
|   Rent               1,000.00             Wages              6,000.49
|   Computer bought    3,000.00             Rent               1,200.00
|   Depreciation       1,000.00             Computer bought    1,500.00
|   Dividend           2,000.00             Depreciation       1,000.00
|   Director's loan in 5,000.00             Bank interest         12.50
|                                           Dividend           5,000.00
|                                           (and a sale on 6 April 2025 — next year)
*/

beforeEach(function () {
    $this->company = Company::factory()->forCountry(Country::UnitedKingdom)->create([
        'name' => 'Ramsey Web Ltd',
        'address_region' => 'ENG',
        'fiscal_year_start_month' => 4,
    ]);
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $make = function (string $code, string $name, AccountSubtype $subtype, array $extra = []): Account {
        return Account::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'code' => $code,
            'name' => $name,
            'type' => $subtype->type(),
            'subtype' => $subtype,
            'normal_balance' => $subtype->type()->normalBalance(),
            'is_active' => true,
        ], $extra));
    };

    $this->a = [
        'bank' => $make('91000', 'Business Current', AccountSubtype::Bank),
        'debtors' => $make('91100', 'Trade Debtors', AccountSubtype::AccountsReceivable),
        'vat' => $make('92100', 'VAT Control', AccountSubtype::TaxPayable),
        'loan' => $make('92200', "Director's loan account", AccountSubtype::CurrentLiability, ['statutory_line' => 'creditors_after', 'statutory_label' => "Director's loan"]),
        'shares' => $make('93000', 'Ordinary share capital', AccountSubtype::Equity),
        'sales' => $make('94000', 'Web design sales', AccountSubtype::Income, ['statutory_label' => 'Turnover']),
        'interest' => $make('97000', 'Bank interest received', AccountSubtype::OtherIncome),
        'rent' => $make('96000', 'Rent', AccountSubtype::Expense),
        'wages' => $make('96100', 'Wages and salaries', AccountSubtype::Expense),
        'depreciation' => $make('96200', 'Depreciation', AccountSubtype::Expense),
        'cost' => $make('91500', 'Computer equipment cost', AccountSubtype::FixedAsset),
        'accdep' => $make('91510', 'Computer equipment accumulated depreciation', AccountSubtype::FixedAsset),
        'dividends' => $make('98000', 'Dividends paid', AccountSubtype::OtherExpense),
    ];

    $n = 0;
    $this->post = function (string $date, array $lines) use (&$n): void {
        $entry = JournalEntry::create(['entry_no' => 'JE-T'.(++$n), 'entry_date' => $date, 'memo' => 'Test']);
        foreach ($lines as $i => [$key, $dr, $cr]) {
            $entry->lines()->create(['account_id' => $this->a[$key]->id, 'debit_cents' => $dr, 'credit_cents' => $cr, 'line_order' => $i]);
        }
        app(JournalPoster::class)->post($entry);
    };
    $p = $this->post;

    // ── Prior year: 6 April 2023 – 5 April 2024 ──
    $p('2023-04-06', [['bank', 10000, 0], ['shares', 0, 10000]]);
    $p('2023-06-01', [['bank', 1000040, 0], ['sales', 0, 1000040]]);
    $p('2023-07-01', [['rent', 100000, 0], ['bank', 0, 100000]]);
    $p('2023-08-01', [['cost', 300000, 0], ['bank', 0, 300000]]);
    $p('2023-09-01', [['bank', 500000, 0], ['loan', 0, 500000]]);
    $p('2024-03-31', [['dividends', 200000, 0], ['bank', 0, 200000]]);
    $p('2024-04-05', [['depreciation', 100000, 0], ['accdep', 0, 100000]]);

    // ── Current year: 6 April 2024 – 5 April 2025 ──
    $p('2024-05-01', [['debtors', 2400000, 0], ['sales', 0, 2000000], ['vat', 0, 400000]]);
    $p('2024-06-01', [['bank', 2000000, 0], ['debtors', 0, 2000000]]);
    $p('2024-07-01', [['wages', 600049, 0], ['bank', 0, 600049]]);
    $p('2024-08-01', [['rent', 120000, 0], ['bank', 0, 120000]]);
    $p('2024-09-01', [['cost', 150000, 0], ['bank', 0, 150000]]);
    $p('2025-01-01', [['bank', 1250, 0], ['interest', 0, 1250]]);
    $p('2025-03-01', [['dividends', 500000, 0], ['bank', 0, 500000]]);
    $p('2025-04-05', [['depreciation', 100000, 0], ['accdep', 0, 100000]]);

    // The day after the year end belongs to the next year.
    $p('2025-04-06', [['bank', 99900, 0], ['sales', 0, 99900]]);

    $this->build = fn () => app(UkStatutoryAccountsBuilder::class)->build(
        $this->company->fresh(),
        CarbonImmutable::parse('2024-04-06'),
        CarbonImmutable::parse('2025-04-05'),
    );
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('builds the income statement in whole pounds for a 5 April year', function () {
    $r = ($this->build)();
    $c = $r['sci']['current'];
    $p = $r['sci']['prior'];

    // Rounded line by line the year's profit is £11,813, but retained earnings
    // only move from £6,000 to £12,812 with £5,000 of dividends — £11,812. The
    // pound is taken up on the largest expense line, wages (£6,000.49), so the
    // income statement casts to the balance sheets either side of it.
    expect($r['has_prior'])->toBeTrue()
        ->and($c['turnover'])->toBe(20000)            // the 6 April sale is next year's
        ->and($c['expenses'])->toEqualCanonicalizing(['Depreciation' => 1000, 'Rent' => 1200, 'Wages and salaries' => 6001])
        ->and($c['expense_total'])->toBe(8201)
        ->and($c['operating_profit'])->toBe(11799)
        ->and($c['interest_receivable'])->toBe(13)    // £12.50 rounds up
        ->and($c['profit_after_tax'])->toBe(11812)
        ->and($c['rounding_adjustment'])->toBe(-1)
        ->and($c['dividends'])->toBe(5000)            // a distribution, not an expense
        ->and($c['staff_costs'])->toBe(6000)
        ->and($p['turnover'])->toBe(10000)
        ->and($p['profit_after_tax'])->toBe(8000)
        ->and($p['rounding_adjustment'])->toBe(0)
        ->and($p['dividends'])->toBe(2000)
        ->and($r['captions']['turnover'])->toBe('Turnover');

    expect(collect($r['sci']['expense_rows'])->firstWhere('label', 'Wages and salaries'))
        ->toBe(['label' => 'Wages and salaries', 'current' => 6001, 'prior' => 0]);
});

it('builds a balance sheet that balances, with retained earnings as the balancing figure', function () {
    $r = ($this->build)();
    $s = $r['sfp']['current'];
    $p = $r['sfp']['prior'];

    expect($s['tangible_assets'])->toBe(2500)
        ->and($s['debtors'])->toBe(4000)
        ->and($s['cash'])->toBe(15412)
        ->and($s['current_total'])->toBe(19412)
        ->and($s['creditors_within'])->toBe(4000)
        ->and($s['creditors_after'])->toBe(5000)
        ->and($s['net_assets'])->toBe(12912)
        ->and($s['share_capital'])->toBe(100)
        ->and($s['retained_earnings'])->toBe(12812)
        ->and($s['equity_total'])->toBe($s['net_assets'])
        // The comparative is last year's balance sheet exactly as it rounds on
        // its own — what last year's accounts said — not restated to cast.
        ->and($p['retained_earnings'])->toBe(6000)
        ->and($p['net_assets'])->toBe(6100)
        ->and($p['cash'])->toBe(9100)
        ->and($p)->not->toHaveKey('rounding_adjustment');

    expect($r['analysis']['creditors_within'])->toBe([['label' => 'Taxation and social security', 'current' => 4000, 'prior' => 0]])
        ->and($r['analysis']['creditors_after'])->toBe([['label' => "Director's loan", 'current' => 5000, 'prior' => 5000]])
        ->and($r['analysis']['debtors'])->toBe([['label' => 'Trade debtors', 'current' => 4000, 'prior' => 0]]);
});

it('reconciles the change in equity for both years without a rounding line', function () {
    $r = ($this->build)();

    foreach (['prior', 'current'] as $year) {
        $b = $r['soce'][$year];

        expect($b['other_movements'])->toBe(0)
            ->and($b['opening']['retained_earnings'] + $b['profit'] - $b['dividends'])->toBe($b['closing']['retained_earnings']);
    }

    expect($r['soce']['prior']['shares_issued'])->toBe(100)
        ->and($r['soce']['current']['closing']['retained_earnings'])->toBe(12812);
});

it('analyses the tangible fixed assets by class', function () {
    $r = ($this->build)();

    expect($r['tangible']['classes'])->toHaveKey('Computer equipment')
        ->and($r['tangible']['classes']['Computer equipment'])->toMatchArray([
            'cost_open' => 3000, 'additions' => 1500, 'disposals' => 0, 'cost_close' => 4500,
            'dep_open' => 1000, 'charge' => 1000, 'eliminated' => 0, 'dep_close' => 2000,
            'nbv_close' => 2500, 'nbv_open' => 2000,
        ]);
});

it('maps accounts to sensible statutory lines by default', function () {
    expect(UkStatutoryCatalog::defaultLine(AccountSubtype::Bank, 'Current account'))->toBe('cash')
        ->and(UkStatutoryCatalog::defaultLine(AccountSubtype::FixedAsset, 'Vans accumulated depreciation'))->toBe('tangible_depreciation')
        ->and(UkStatutoryCatalog::defaultLine(AccountSubtype::OtherExpense, 'Dividend'))->toBe('dividends')
        ->and(UkStatutoryCatalog::defaultLine(AccountSubtype::Expense, 'UK Corporation Tax'))->toBe('taxation')
        ->and(UkStatutoryCatalog::defaultLine(AccountSubtype::Expense, "Directors' remuneration"))->toBe('staff_costs')
        ->and(UkStatutoryCatalog::defaultLine(AccountSubtype::OtherIncome, 'Bank interest - received'))->toBe('interest_receivable')
        ->and(UkStatutoryCatalog::defaultLine(AccountSubtype::Equity, 'Ordinary share capital'))->toBe('share_capital')
        ->and(UkStatutoryCatalog::defaultLine(AccountSubtype::Equity, 'Opening Balance Equity'))->toBe('retained_earnings')
        ->and(UkStatutoryCatalog::assetClassFromName('Computer equipment cost brought forward'))->toBe('Computer equipment')
        ->and(UkStatutoryCatalog::assetClassFromName('Computer equipment depreciation charge for year'))->toBe('Computer equipment')
        ->and(UkStatutoryCatalog::assetClassFromName('Furniture and Fixtures'))->toBe('Furniture and Fixtures');
});

it('works out the last completed year from the year-end date', function () {
    $profile = StatutoryAccountsProfile::fromArray(['year_end_day' => 5, 'year_end_month' => 4]);

    [$start, $end] = $profile->lastCompletedYear(CarbonImmutable::parse('2026-10-21'));
    expect($start->toDateString())->toBe('2025-04-06')->and($end->toDateString())->toBe('2026-04-05');

    [$start, $end] = $profile->lastCompletedYear(CarbonImmutable::parse('2026-04-05'));
    expect($start->toDateString())->toBe('2024-04-06')->and($end->toDateString())->toBe('2025-04-05');
});

it('defaults an Isle of Man 1931 Act company to its regime and nil tax note', function () {
    $this->company->forceFill(['company_registration_number' => '128897C', 'address_region' => 'IM'])->save();

    $profile = StatutoryAccountsProfile::forCompany($this->company->fresh());

    expect($profile->regime)->toBe(StatutoryAccountsProfile::REGIME_IOM_1931)
        ->and($profile->legislation())->toBe('Isle of Man Companies Acts 1931 to 2004')
        ->and($profile->taxationNote)->toBe('Taxation for companies in the Isle of Man is 0%.')
        ->and($profile->yearEndMonth)->toBe(3)
        ->and($profile->yearEndDay)->toBe(31);
});

it('renders both frameworks as a paginated PDF with the figures in it', function (string $framework, array $expected) {
    StatutoryAccountsProfile::fromArray([
        'framework' => $framework,
        'year_end_day' => 5,
        'year_end_month' => 4,
        'directors' => [['name' => 'Ms A Kinvig', 'shares_current' => '100', 'shares_prior' => '100']],
        'authorised_shares' => '1,000 ordinary shares of £1 each',
        'issued_shares' => '100 ordinary shares of £1 each',
    ])->save($this->company);

    $company = $this->company->fresh();
    $bytes = app(UkStatutoryAccountsPdf::class)->render($company, StatutoryAccountsProfile::forCompany($company), ($this->build)());

    expect(substr($bytes, 0, 4))->toBe('%PDF');

    $text = preg_replace('/\s+/', ' ', (new Parser)->parseContent($bytes)->getText());

    foreach ($expected as $needle) {
        expect($text)->toContain($needle);
    }
})->with([
    'FRS 102 Section 1A' => [StatutoryAccountsProfile::FRAMEWORK_FRS102_1A, [
        'RAMSEY WEB LTD', 'Page 1', 'Statement of Financial Position', 'Statement of Changes in Equity',
        '12,912', '11,812', '(5,000)', 'Tangible fixed assets', 'section 477', 'FRS 102 Section 1A',
    ]],
    'FRS 105 micro-entity' => [StatutoryAccountsProfile::FRAMEWORK_FRS105, [
        'Balance Sheet', '12,912', 'micro-entity provisions', 'Advances, credits and guarantees granted to directors',
    ]],
]);

it('lists the report for UK companies only', function () {
    expect(ReportCatalog::flatten($this->company, $this->user))->toHaveKey('reports.statutory-accounts');

    $canadian = Company::factory()->create();
    $canadian->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    expect(ReportCatalog::flatten($canadian, $this->user))->not->toHaveKey('reports.statutory-accounts');

    $this->get(route('reports.statutory-accounts.pdf', ['company' => $canadian->slug]))->assertNotFound();
});

it('serves the PDF inline and as a download', function () {
    $this->get(route('reports.statutory-accounts.pdf', ['company' => $this->company->slug, 'start' => '2024-04-06', 'end' => '2025-04-05']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename="Ramsey-Web-Ltd-Accounts-2025.pdf"');

    $this->get(route('reports.statutory-accounts.pdf', ['company' => $this->company->slug, 'start' => '2024-04-06', 'end' => '2025-04-05', 'download' => 1]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="Ramsey-Web-Ltd-Accounts-2025.pdf"');
});

it('lets the mapping and company details be edited on the report page', function () {
    $page = Livewire::test('pages::reports.statutory-accounts', ['company' => $this->company])
        ->set('startDate', '2024-04-06')
        ->set('endDate', '2025-04-05')
        ->assertSee('Statutory Accounts')
        ->assertSeeHtml('data-test="statutory-open-pdf"')
        ->assertSeeHtml('data-test="statutory-summary"')
        ->assertDontSeeHtml('<iframe');

    $page->set('tab', 'mapping')
        ->call('setLine', $this->a['rent']->id, 'administrative_expenses')
        ->call('setLabel', $this->a['rent']->id, 'Premises costs');

    expect($this->a['rent']->fresh())
        ->statutory_line->toBe('administrative_expenses')
        ->statutory_label->toBe('Premises costs');

    expect(($this->build)()['sci']['current']['expenses'])->toHaveKey('Premises costs');

    $page->set('tab', 'details')
        ->set('form.principal_activity', 'Web design.')
        ->set('form.year_end_day', 5)
        ->set('form.year_end_month', 4)
        ->set('form.signatories', "Ms A Kinvig\nMr B Quayle")
        ->call('addDirector')
        ->set('form.directors.0.name', 'Ms A Kinvig')
        ->call('saveDetails')
        ->assertHasNoErrors();

    $profile = StatutoryAccountsProfile::forCompany($this->company->fresh());

    expect($profile->principalActivity)->toBe('Web design.')
        ->and($profile->signatories)->toBe(['Ms A Kinvig', 'Mr B Quayle'])
        ->and($profile->directors[0]['name'])->toBe('Ms A Kinvig');

    // The framework switch saves straight away.
    $page->set('form.framework', StatutoryAccountsProfile::FRAMEWORK_FRS105);
    expect(StatutoryAccountsProfile::forCompany($this->company->fresh())->isMicro())->toBeTrue();
});

it('lets a member without Accounting view the accounts but not change them', function () {
    $viewer = User::factory()->create();
    $this->company->memberships()->create([
        'user_id' => $viewer->id,
        'role' => CompanyRole::Custom,
        'sections' => [Section::Reports->value],
    ]);
    $this->actingAs($viewer);

    $page = fn () => Livewire::test('pages::reports.statutory-accounts', ['company' => $this->company]);

    $page()->assertOk()->assertSeeHtml('data-test="statutory-summary"');
    $page()->set('tab', 'mapping')->assertSeeHtml('data-test="statutory-read-only"');
    $page()->set('tab', 'details')
        ->assertSeeHtml('data-test="statutory-read-only"')
        ->assertDontSeeHtml('data-test="statutory-save"');

    $page()->call('setLine', $this->a['rent']->id, 'administrative_expenses')->assertForbidden();
    $page()->call('setLabel', $this->a['rent']->id, 'Premises costs')->assertForbidden();
    $page()->set('form.principal_activity', 'Web design.')->call('saveDetails')->assertForbidden();
    $page()->set('form.framework', StatutoryAccountsProfile::FRAMEWORK_FRS105)->assertForbidden();

    expect($this->a['rent']->fresh())
        ->statutory_line->toBeNull()
        ->statutory_label->toBeNull()
        ->and(($this->company->fresh()->settings ?? [])['statutory_accounts'] ?? null)->toBeNull();
});

it('lets a member with Accounting change the mapping and details', function () {
    $preparer = User::factory()->create();
    $this->company->memberships()->create([
        'user_id' => $preparer->id,
        'role' => CompanyRole::Custom,
        'sections' => [Section::Reports->value, Section::Accounting->value],
    ]);
    $this->actingAs($preparer);

    Livewire::test('pages::reports.statutory-accounts', ['company' => $this->company])
        ->set('tab', 'mapping')
        ->assertDontSeeHtml('data-test="statutory-read-only"')
        ->call('setLine', $this->a['rent']->id, 'administrative_expenses')
        ->set('tab', 'details')
        ->assertSeeHtml('data-test="statutory-save"')
        ->set('form.principal_activity', 'Web design.')
        ->call('saveDetails')
        ->assertHasNoErrors();

    expect($this->a['rent']->fresh()->statutory_line)->toBe('administrative_expenses')
        ->and(StatutoryAccountsProfile::forCompany($this->company->fresh())->principalActivity)->toBe('Web design.');
});

it('refuses the report page to a non-UK company', function () {
    $canadian = Company::factory()->create();
    $canadian->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $canadian);

    Livewire::test('pages::reports.statutory-accounts', ['company' => $canadian])->assertForbidden();
});

it('warns when an income statement account is mapped onto the balance sheet', function () {
    $this->a['rent']->forceFill(['statutory_line' => 'debtors'])->save();

    expect(($this->build)()['warnings'])->toHaveCount(1);
});

it('shows VAT due back as a debtor and an overdraft as a creditor', function () {
    // Pay HMRC £5,000 against a £4,000 liability: £1,000 is now due back.
    ($this->post)('2025-03-31', [['vat', 500000, 0], ['bank', 0, 500000]]);

    $r = ($this->build)();

    expect($r['analysis']['creditors_within'])->toBe([])
        ->and(collect($r['analysis']['debtors'])->firstWhere('label', 'Taxation and social security'))
        ->toBe(['label' => 'Taxation and social security', 'current' => 1000, 'prior' => 0])
        ->and($r['sfp']['current']['net_assets'])->toBe(12912);

    // Spend the rest of the bank and £100 more.
    ($this->post)('2025-04-01', [['rent', 1051241, 0], ['bank', 0, 1051241]]);

    $s = ($this->build)()['sfp']['current'];

    expect($s['cash'])->toBe(0)
        ->and($s['creditors_within_by_label'])->toBe(['Bank loans and overdrafts' => 100]);
});

it('exports the same accounts as an editable Word document', function (string $framework) {
    StatutoryAccountsProfile::fromArray([
        'framework' => $framework,
        'year_end_day' => 5,
        'year_end_month' => 4,
        'directors' => [['name' => 'Ms A Kinvig & Co', 'shares_current' => '100', 'shares_prior' => '100']],
        'issued_shares' => '100 ordinary shares of £1 each',
    ])->save($this->company);

    $company = $this->company->fresh();
    $profile = StatutoryAccountsProfile::forCompany($company);
    $report = ($this->build)();

    $docx = app(UkStatutoryAccountsDocx::class)->render($company, $profile, $report);
    expect(substr($docx, 0, 2))->toBe('PK');

    $word = docxText($docx);
    $pdf = preg_replace('/\s+/', ' ', (new Parser)->parseContent(app(UkStatutoryAccountsPdf::class)->render($company, $profile, $report))->getText());

    // Free text is escaped, not left to break the XML.
    expect($word)->toContain('Ms A Kinvig & Co');

    // Every heading and figure in the PDF is in the Word document too.
    $shared = $framework === StatutoryAccountsProfile::FRAMEWORK_FRS105
        ? ['RAMSEY WEB LTD', 'Balance Sheet', 'Profit and Loss Account', '12,912', '11,812', 'micro-entity provisions', 'Advances, credits and guarantees granted to directors']
        : ['RAMSEY WEB LTD', "Directors' Report", 'Statement of Comprehensive Income', 'Statement of Financial Position', 'Statement of Changes in Equity',
            'Notes to the financial statements', 'Tangible fixed assets', '12,912', '11,812', '(5,000)', '12,812', '4,500', 'section 477', 'FRS 102 Section 1A Small Entities'];

    foreach ($shared as $needle) {
        expect($pdf)->toContain($needle)
            ->and($word)->toContain($needle);
    }

    // Page numbers are live fields: PAGE in the running header, PAGEREF on the contents page.
    $zip = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($zip, $docx);
    $archive = new ZipArchive;
    $archive->open($zip);
    $document = $archive->getFromName('word/document.xml');
    $archive->close();
    @unlink($zip);

    expect($document)->toContain('PAGEREF ll_notes')
        ->and($document)->toContain('w:name="ll_balance_sheet"');
})->with([
    'FRS 102 Section 1A' => [StatutoryAccountsProfile::FRAMEWORK_FRS102_1A],
    'FRS 105 micro-entity' => [StatutoryAccountsProfile::FRAMEWORK_FRS105],
]);

it('serves the Word document as a download', function () {
    $this->get(route('reports.statutory-accounts.pdf', ['company' => $this->company->slug, 'start' => '2024-04-06', 'end' => '2025-04-05', 'format' => 'docx']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
        ->assertHeader('Content-Disposition', 'attachment; filename="Ramsey-Web-Ltd-Accounts-2025.docx"');
});

it('offers PDF and Word from one Download menu', function () {
    Livewire::test('pages::reports.statutory-accounts', ['company' => $this->company])
        ->set('startDate', '2024-04-06')
        ->set('endDate', '2025-04-05')
        ->assertSeeHtml('data-test="statutory-download-pdf"')
        ->assertSeeHtml('data-test="statutory-download-docx"')
        ->assertSeeHtml('format=docx');
});

it('shows every year from the selected one to the latest in the summary', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00'));

    $page = Livewire::test('pages::reports.statutory-accounts', ['company' => $this->company])
        ->set('startDate', '2023-04-06')
        ->set('endDate', '2024-04-05');

    $years = $page->instance()->summaryYears;

    expect(array_column($years, 'label'))->toBe(['2024', '2025', '2026'])
        ->and($years[0]['sci']['turnover'])->toBe(10000)
        ->and($years[1]['sci']['turnover'])->toBe(20000)
        ->and($years[2]['sci']['turnover'])->toBe(999)
        ->and($years[1]['sfp']['net_assets'])->toBe(12912);

    // On the latest year, it sits beside the year before.
    $page->set('startDate', '2025-04-06')->set('endDate', '2026-04-05');

    expect(array_column($page->instance()->summaryYears, 'label'))->toBe(['2025', '2026']);
});

it('splits a range of several years into a column per year and files the latest', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00'));
    StatutoryAccountsProfile::fromArray(['year_end_day' => 5, 'year_end_month' => 4])->save($this->company);

    $page = Livewire::test('pages::reports.statutory-accounts', ['company' => $this->company])
        ->set('startDate', '2023-04-06')
        ->set('endDate', '2026-04-05');

    // Three statutory years, labelled by year end — not one three-year lump.
    expect(array_column($page->instance()->summaryYears, 'label'))->toBe(['2024', '2025', '2026'])
        ->and($page->instance()->summaryYears[0]['sci']['turnover'])->toBe(10000)
        ->and($page->instance()->summaryYears[1]['sci']['turnover'])->toBe(20000);

    // The tiles and documents are for the latest year in the range.
    expect($page->instance()->report['start']->toDateString())->toBe('2025-04-06')
        ->and($page->instance()->report['end']->toDateString())->toBe('2026-04-05')
        ->and($page->instance()->report['sci']['current']['turnover'])->toBe(999);
    $page->assertSee('the year ended 5th April 2026');

    $this->get(route('reports.statutory-accounts.pdf', ['company' => $this->company->slug, 'start' => '2023-04-06', 'end' => '2025-04-05']))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename="Ramsey-Web-Ltd-Accounts-2025.pdf"');
});

it('finds the whole statutory years inside a range', function () {
    $profile = StatutoryAccountsProfile::fromArray(['year_end_day' => 5, 'year_end_month' => 4]);
    $years = fn (string $a, string $b) => array_map(
        fn ($y) => $y[0]->toDateString().'..'.$y[1]->toDateString(),
        $profile->yearsWithin(CarbonImmutable::parse($a), CarbonImmutable::parse($b)),
    );

    expect($years('2023-04-06', '2026-04-05'))->toBe(['2023-04-06..2024-04-05', '2024-04-06..2025-04-05', '2025-04-06..2026-04-05'])
        ->and($years('2023-04-06', '2024-04-05'))->toBe(['2023-04-06..2024-04-05'])
        ->and($years('2023-05-01', '2025-04-05'))->toBe(['2024-04-06..2025-04-05'])
        ->and($profile->documentPeriod(CarbonImmutable::parse('2023-06-01'), CarbonImmutable::parse('2023-12-31'))[1]->toDateString())->toBe('2023-12-31');
});

it('keeps an end-of-February year end on 29 February in leap years', function () {
    $profile = StatutoryAccountsProfile::fromArray(['year_end_day' => 29, 'year_end_month' => 2]);

    expect($profile->yearEndDay)->toBe(29)
        ->and($profile->yearEndIn(2028)->toDateString())->toBe('2028-02-29')
        ->and($profile->yearEndIn(2027)->toDateString())->toBe('2027-02-28')
        // A March fiscal start defaults to the end of February, not the 28th.
        ->and(StatutoryAccountsProfile::defaultsFor(Company::factory()->make(['fiscal_year_start_month' => 3]))['year_end_day'])->toBe(29);

    $dates = fn (array $period) => array_map(fn (CarbonImmutable $d) => $d->toDateString(), $period);

    $year = $profile->lastCompletedYear(CarbonImmutable::parse('2028-06-01'));
    expect($dates($year))->toBe(['2027-03-01', '2028-02-29']);

    // A year either way stays on the year end, leap day and all.
    $next = $profile->shiftPeriod($year[0], $year[1], 1);
    expect($dates($next))->toBe(['2028-03-01', '2029-02-28'])
        ->and($dates($profile->shiftPeriod($next[0], $next[1], -1)))->toBe(['2027-03-01', '2028-02-29']);

    // Dates off the year end move by calendar years, without running into March.
    $april = StatutoryAccountsProfile::fromArray(['year_end_day' => 5, 'year_end_month' => 4]);
    expect($dates($april->shiftPeriod(CarbonImmutable::parse('2023-06-15'), CarbonImmutable::parse('2024-02-29'), 1)))
        ->toBe(['2024-06-15', '2025-02-28']);
});

it('steps an end-of-February year a year at a time on the page', function () {
    StatutoryAccountsProfile::fromArray(['year_end_day' => 29, 'year_end_month' => 2])->save($this->company);

    Livewire::test('pages::reports.statutory-accounts', ['company' => $this->company])
        ->set('startDate', '2027-03-01')
        ->set('endDate', '2028-02-29')
        ->call('shiftYear', 1)
        ->assertSet('startDate', '2028-03-01')
        ->assertSet('endDate', '2029-02-28')
        ->call('shiftYear', -1)
        ->assertSet('startDate', '2027-03-01')
        ->assertSet('endDate', '2028-02-29');
});

it('presents last year as its own accounts said, and opens a first year at nil', function () {
    // Two 50p items in the first year each round up to £1 on the income
    // statement but add only £1 to the bank: year one has a pound to cast too.
    ($this->post)('2023-12-01', [['bank', 50, 0], ['sales', 0, 50]]);
    ($this->post)('2023-12-02', [['bank', 50, 0], ['interest', 0, 50]]);

    $year1 = app(UkStatutoryAccountsBuilder::class)->build($this->company->fresh(), CarbonImmutable::parse('2023-04-06'), CarbonImmutable::parse('2024-04-05'));
    $year2 = ($this->build)();

    expect($year1['has_prior'])->toBeFalse()
        ->and($year1['sci']['current']['rounding_adjustment'])->toBe(-1)
        ->and($year1['soce']['current']['opening'])->toBe(['share_capital' => 0, 'other_reserves' => 0, 'retained_earnings' => 0])
        ->and($year1['soce']['current']['other_movements'])->toBe(0)
        // Next year's comparatives are this year's figures, unrestated.
        ->and($year2['sfp']['prior'])->toEqual($year1['sfp']['current'])
        ->and($year2['sci']['prior'])->toEqual($year1['sci']['current'])
        ->and(collect($year2['sci']['expense_rows'])->pluck('prior', 'label')->filter()->all())->toEqual($year1['sci']['current']['expenses']);
});

it('warns about an excluded account that still holds a balance', function () {
    // Neither moves this year, but the loan still holds £5,000 and the share
    // capital £100: both would drop silently into retained earnings.
    $this->a['loan']->forceFill(['statutory_line' => 'excluded'])->save();
    $this->a['shares']->forceFill(['statutory_line' => 'excluded'])->save();

    $warnings = ($this->build)()['warnings'];

    expect($warnings)->toHaveCount(2)
        ->each->toContain('is excluded from the statutory accounts but has a balance or activity this year');
});

it('shows each overdrawn bank account as an overdraft, not netted against the others', function () {
    $this->a['reserve'] = Account::withoutGlobalScopes()->create([
        'company_id' => $this->company->id,
        'code' => '91010',
        'name' => 'Business Reserve',
        'type' => AccountSubtype::Bank->type(),
        'subtype' => AccountSubtype::Bank,
        'normal_balance' => AccountSubtype::Bank->type()->normalBalance(),
        'is_active' => true,
    ]);

    // Sweep £20,000 into the reserve: the current account (£15,412.41) is
    // overdrawn by £4,587.59 while the reserve holds £20,000.
    ($this->post)('2025-04-01', [['reserve', 2000000, 0], ['bank', 0, 2000000]]);

    $s = ($this->build)()['sfp']['current'];

    expect($s['cash'])->toBe(20000)
        ->and($s['creditors_within_by_label'])->toBe(['Bank loans and overdrafts' => 4588, 'Taxation and social security' => 4000])
        ->and($s['net_assets'])->toBe(12912);

    // Accounts captioned alike — a set-off arrangement — net as one.
    $this->a['bank']->forceFill(['statutory_label' => 'Pooled'])->save();
    $this->a['reserve']->forceFill(['statutory_label' => 'Pooled'])->save();

    $s = ($this->build)()['sfp']['current'];

    expect($s['cash'])->toBe(15412)
        ->and($s['creditors_within_by_label'])->toBe(['Taxation and social security' => 4000]);
});

it('shares ledger reads across the years one builder builds', function () {
    $company = $this->company->fresh();
    $builder = app(UkStatutoryAccountsBuilder::class);
    $builder->build($company, CarbonImmutable::parse('2024-04-06'), CarbonImmutable::parse('2025-04-05'));

    DB::flushQueryLog();
    DB::enableQueryLog();
    $builder->build($company, CarbonImmutable::parse('2023-04-06'), CarbonImmutable::parse('2024-04-05'));
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // The year before shares its balance sheets and profit with the year
    // after; only its fixed asset movements are read afresh.
    expect($queries)->toBeLessThanOrEqual(2);
});

it('builds no accounts for the mapping and details tabs', function () {
    $this->mock(UkStatutoryAccountsBuilder::class, function (MockInterface $mock) {
        $mock->shouldReceive('mapping')->andReturn([]);
        $mock->shouldNotReceive('build');
    });

    Livewire::withQueryParams(['tab' => 'mapping', 'start' => '2024-04-06', 'end' => '2025-04-05'])
        ->test('pages::reports.statutory-accounts', ['company' => $this->company])
        ->assertOk()
        ->set('tab', 'details')
        ->assertOk();
});

it('re-renders the PDF until the page numbers settle', function () {
    // Filling in the page references pushes the balance sheet onto a second
    // page, so every later page number has to move with it.
    $this->mock(PdfMerger::class, function (MockInterface $mock) {
        $mock->shouldReceive('pageCount')->times(15)->andReturn(
            1, 1, 1, 1, 1,      // with stand-in references
            1, 1, 2, 1, 1,      // with the real ones: the balance sheet grows
            1, 1, 2, 1, 1,      // settled
        );
    });

    $company = $this->company->fresh();
    $bytes = app(UkStatutoryAccountsPdf::class)->render($company, StatutoryAccountsProfile::forCompany($company), ($this->build)());
    $text = preg_replace('/\s+/', ' ', (new Parser)->parseContent($bytes)->getText());

    expect($text)->toContain('Statement of Changes in Equity 5')
        ->toContain('The notes on page 6 form part of these financial statements.');
});

it('keeps only recognised statutory lines and trims captions', function () {
    $save = app(SaveStatutoryMapping::class);
    $rent = $this->a['rent'];

    $save->handle($rent, ['statutory_line' => 'administrative_expenses', 'statutory_label' => '  Premises costs  ']);
    expect($rent->fresh())->statutory_line->toBe('administrative_expenses')->statutory_label->toBe('Premises costs');

    $save->handle($rent, ['statutory_line' => 'not_a_line', 'statutory_label' => str_repeat('x', 200)]);
    expect($rent->fresh()->statutory_line)->toBeNull()
        ->and(mb_strlen($rent->fresh()->statutory_label))->toBe(SaveStatutoryMapping::LABEL_MAX);

    // A key left out keeps its value; a blank caption clears it.
    $save->handle($rent, ['statutory_line' => 'staff_costs']);
    $save->handle($rent, ['statutory_label' => ' ']);
    expect($rent->fresh())->statutory_line->toBe('staff_costs')->statutory_label->toBeNull();

    // Outside the UK there are no statutory accounts to map onto.
    $canadian = Company::factory()->create();
    app()->instance('current_company', $canadian);
    $cash = Account::where('subtype', AccountSubtype::Bank)->firstOrFail();
    $save->handle($cash, ['statutory_line' => 'cash']);

    expect($cash->fresh()->statutory_line)->toBeNull();
});

it('downloads the accounts in English whatever language the app is in', function () {
    $bytes = $this->get(route('reports.statutory-accounts.pdf', [
        'company' => $this->company->slug, 'start' => '2024-04-06', 'end' => '2025-04-05', 'lang' => 'fr',
    ]))->assertOk()->getContent();

    $text = preg_replace('/\s+/', ' ', (new Parser)->parseContent($bytes)->getText());

    // lang/fr.json translates this heading for other reports.
    expect($text)->toContain('Statement of Financial Position')
        ->not->toContain('situation financière')
        ->and(app()->getLocale())->toBe('fr');
});
