<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\OrganizationType;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Reporting\GifiFileExporter;
use App\Services\Reporting\GifiStatementBuilder;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * The GIFI Statement written as a standard .gfi file for tax software (DT Max,
 * Taxprep, ProFile, TaxCycle): a "GIFI01" header line, then one "code",amount
 * line per GIFI line, CRLF. See GifiFileExporter for the layout.
 */

beforeEach(function () {
    $this->company = Company::factory()->create([
        'name' => 'Gfi Co',
        'legal_name' => 'Société Gfi Inc.',
        'fiscal_year_start_month' => 1,
        'organization_type' => OrganizationType::Corporation->value,
        'tax_number' => '123456789 RT 0001',
        'address_line1' => '100 Rue "Principale"',
        'address_city' => 'Montréal',
        'address_region' => 'qc',
        'address_postal_code' => 'h2x 1y4',
        'address_country' => 'CA',
    ]);
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $first = fn (AccountSubtype $subtype) => Account::query()->where('subtype', $subtype->value)->orderBy('code')->first();
    $create = fn (string $code, string $name, AccountSubtype $subtype, ?string $gifi = null) => Account::create(array_filter([
        'code' => $code,
        'name' => $name,
        'subtype' => $subtype->value,
        'type' => $subtype->type()->value,
        'normal_balance' => $subtype->type()->normalBalance()->value,
        'gifi_code' => $gifi,
    ]));

    $this->bank = $first(AccountSubtype::Bank);
    $this->income = $first(AccountSubtype::Income);
    $this->expense = $first(AccountSubtype::Expense);
    $this->payable = $first(AccountSubtype::AccountsPayable);
    $this->shares = $first(AccountSubtype::Equity);
    $this->equipment = $create('1705', 'Equipment', AccountSubtype::FixedAsset);
    $this->accumulated = $create('1706', 'Accumulated amortization - Equipment', AccountSubtype::FixedAsset, '1741');
    $this->amortization = $create('6905', 'Amortization', AccountSubtype::Expense, '8670');

    // Last year: shares issued, equipment bought, $1,000 of profit.
    gfiEntry($this->bank, $this->shares, 500000, '2025-01-10');
    gfiEntry($this->equipment, $this->bank, 300000, '2025-02-01');
    gfiEntry($this->bank, $this->income, 100000, '2025-06-01');

    // This year: $500 of sales, $250 of expenses (one unpaid), $300 amortization.
    gfiEntry($this->bank, $this->income, 50000, '2026-03-15');
    gfiEntry($this->expense, $this->bank, 20000, '2026-04-01');
    gfiEntry($this->expense, $this->payable, 5000, '2026-05-01');
    gfiEntry($this->amortization, $this->accumulated, 30000, '2026-12-31');
});

afterEach(fn () => app()->forgetInstance('current_company'));

function gfiEntry(Account $debit, Account $credit, int $cents, string $date): void
{
    $entry = JournalEntry::create(['entry_no' => uniqid('JE-'), 'entry_date' => $date, 'is_posted' => true]);
    $entry->lines()->create(['account_id' => $debit->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $credit->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'line_order' => 1]);
}

/** @return array<string, mixed> */
function gfiReport(Company $company, string $start = '2026-01-01', string $end = '2026-12-31'): array
{
    return app(GifiStatementBuilder::class)->build($company, CarbonImmutable::parse($start), CarbonImmutable::parse($end));
}

function gfiContent(Company $company): string
{
    return app(GifiFileExporter::class)->content(
        $company,
        gfiReport($company),
        CarbonImmutable::parse('2026-12-31'),
        CarbonImmutable::parse('2027-02-03 14:05'),
    );
}

it('writes the GIFI01 header with the corporation, its BN and the year-end', function () {
    $header = explode("\r\n", gfiContent($this->company))[0];

    expect(str_getcsv($header, ',', '"', ''))->toBe([
        'GIFI01', '0001', 'ASCII', '-', 'L', 'LineLedger', '', '', '', '', 'LineLedger', (string) config('version.app'), '02',
        '123456789RC0001', 'Societe Gfi Inc.', '', '100 Rue Principale', 'Montreal', 'QC', 'H2X 1Y4', '',
        '202612310900', '202702031405', '',
    ]);

    // Every field is quoted, and the file is plain ASCII.
    expect(substr_count($header, '"'))->toBe(48)
        ->and(mb_check_encoding(gfiContent($this->company), 'ASCII'))->toBeTrue();
});

it('writes one natural-balance line per GIFI code, in code order, with CRLF endings', function () {
    $content = gfiContent($this->company);

    expect($content)->toEndWith("\r\n")
        ->and(substr_count($content, "\n"))->toBe(substr_count($content, "\r\n"));

    $lines = array_slice(explode("\r\n", rtrim($content, "\r\n")), 1);

    expect($lines)->toBe([
        '"1000",3300.00',   // bank: 5,000 + 1,000 − 3,000 + 500 − 200
        '"1740",3000.00',   // equipment
        '"1741",-300.00',   // accumulated amortization: a contra line, negative
        '"2620",50.00',     // the unpaid bill: a credit, positive
        '"3500",5000.00',   // shares
        '"3600",950.00',    // retained earnings at year-end: 1,000 prior − 50 this year
        '"8000",500.00',    // sales
        '"8670",300.00',    // amortization
        '"9270",250.00',    // other expenses
    ]);
});

it('balances: assets equal liabilities plus equity in the written lines', function () {
    $amounts = app(GifiFileExporter::class)->amounts(gfiReport($this->company));

    $assets = array_sum(array_filter($amounts, fn ($cents, $code) => $code < '2600', ARRAY_FILTER_USE_BOTH));
    $liabilitiesAndEquity = array_sum(array_filter($amounts, fn ($cents, $code) => $code >= '2600' && $code < '8000', ARRAY_FILTER_USE_BOTH));

    expect($assets)->toBe($liabilitiesAndEquity);
});

it('writes no CRA total item and no empty line', function () {
    $codes = array_keys(app(GifiFileExporter::class)->amounts(gfiReport($this->company)));

    expect(array_intersect($codes, ['2599', '3499', '3620', '3640', '3849', '8299', '9367', '9368', '9999']))->toBe([])
        ->and($codes)->not->toContain('1060'); // receivables are zero, so left out
});

it('takes the BN from the payroll settings when there is no GST/HST number', function () {
    $this->company->update(['tax_number' => null, 'payroll_business_number' => '987654321']);

    expect(str_getcsv(explode("\r\n", gfiContent($this->company))[0], ',', '"', '')[13])->toBe('987654321RC0001');
});

it('leaves the BN empty rather than guessing one', function () {
    $this->company->update(['tax_number' => 'not a number', 'payroll_business_number' => null]);

    expect(str_getcsv(explode("\r\n", gfiContent($this->company))[0], ',', '"', '')[13])->toBe('');
});

it('refuses a statement with unassigned accounts, an unbalanced sheet, or a partial year', function () {
    $exporter = app(GifiFileExporter::class);
    $start = CarbonImmutable::parse('2026-01-01');
    $end = CarbonImmutable::parse('2026-12-31');

    expect($exporter->refusal($this->company, gfiReport($this->company), $start, $end))->toBeNull();

    expect($exporter->refusal($this->company, gfiReport($this->company, '2026-03-01'), CarbonImmutable::parse('2026-03-01'), $end))
        ->toBe(__('A GIFI file covers one fiscal year. Set the period to start on the first day of the fiscal year.'));

    $this->payable->update(['gifi_code' => null]);

    expect($exporter->refusal($this->company, gfiReport($this->company), $start, $end))
        ->toBe(trans_choice('{1} Give the account under Unassigned accounts a GIFI line first, so the file includes it.|[2,*] Give the :count accounts under Unassigned accounts a GIFI line first, so the file includes them.', 1, ['count' => 1]));

    $unbalanced = gfiReport($this->company);
    $unbalanced['unassigned']['lines'] = [];
    $unbalanced['bs']['balanced'] = false;

    expect($exporter->refusal($this->company, $unbalanced, $start, $end))
        ->toBe(__('The balance sheet is out of balance, so it cannot be written as a GIFI file.'));
});

it('downloads the .gfi file from the GIFI Statement', function () {
    Livewire::test('pages::reports.gifi', ['company' => $this->company])
        ->set('preset', 'custom')
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->assertSee(__('GIFI file for tax software (.gfi)'))
        ->call('exportGfi')
        ->assertFileDownloaded("{$this->company->slug}-gifi-2026-12-31.gfi");
});

it('explains instead of downloading when the file would be wrong', function () {
    $this->payable->update(['gifi_code' => null]);

    Livewire::test('pages::reports.gifi', ['company' => $this->company])
        ->set('preset', 'custom')
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->call('exportGfi')
        ->assertNoFileDownloaded()
        ->assertDispatched('toast-show');
});
