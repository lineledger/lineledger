<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Posting\JournalPoster;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);
    $this->actingAs($this->user);

    $this->ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    // 30 posted entries spread across years; each debits AR $1 and credits income $1.
    for ($i = 0; $i < 30; $i++) {
        $entry = JournalEntry::create([
            'company_id' => $this->company->id,
            'entry_no' => 'JE-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'entry_date' => CarbonImmutable::create(2000, 1, 1)->addMonths($i),
            'memo' => "Entry {$i}",
        ]);
        $entry->lines()->create(['account_id' => $this->ar->id, 'debit_cents' => 100, 'credit_cents' => 0, 'line_order' => 0]);
        $entry->lines()->create(['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => 100, 'line_order' => 1]);
        app(JournalPoster::class)->post($entry);
    }

    $this->wide = fn ($t) => $t->set('startDate', '1990-01-01')->set('endDate', '2030-01-01');
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('caps the all-accounts page to the page size while totalling the whole range', function () {
    $t = Livewire::test('pages::reports.general-ledger', ['company' => $this->company]);
    ($this->wide)($t);

    $report = $t->instance()->report;

    // Page is bounded, but counts/totals reflect all 30 entries.
    expect($report['entries'])->toHaveCount(25)
        ->and($report['paginator']->total())->toBe(30)
        ->and($report['entry_count'])->toBe(30)
        ->and($report['total_debit'])->toBe(3000)
        ->and($report['total_credit'])->toBe(3000);

    $t->call('gotoPage', 2);
    expect($t->instance()->report['entries'])->toHaveCount(5);
});

it('honours the page-size dropdown and clamps a crafted value', function () {
    $t = Livewire::test('pages::reports.general-ledger', ['company' => $this->company]);
    ($this->wide)($t);

    $t->set('perPage', 50);
    expect($t->instance()->report['entries'])->toHaveCount(30)
        ->and($t->instance()->report['paginator']->perPage())->toBe(50);

    // A crafted ?per= can't load an unbounded result — it falls back to 25.
    $t->set('perPage', 999999);
    expect($t->instance()->report['paginator']->perPage())->toBe(25)
        ->and($t->instance()->report['entries'])->toHaveCount(25);
});

it('streams the full single-account ledger for export, in date order with a running balance', function () {
    $r = app(ReportCalculator::class)->generalLedgerStreamReport(
        $this->ar,
        CarbonImmutable::parse('1990-01-01'),
        CarbonImmutable::parse('2030-01-01'),
    );

    expect($r['opening'])->toBe(0)
        ->and($r['closing'])->toBe(3000)
        ->and($r['lines'])->toBeInstanceOf(Generator::class);

    $rows = iterator_to_array($r['lines']);
    expect($rows)->toHaveCount(30)
        ->and($rows[0]['date'])->toBe('2000-01-01')
        ->and($rows[0]['running'])->toBe(100)
        ->and($rows[29]['running'])->toBe(3000);
});

it('streams the full all-accounts ledger for export with aggregate totals', function () {
    $r = app(ReportCalculator::class)->generalLedgerAllAccountsStreamReport(
        CarbonImmutable::parse('1990-01-01'),
        CarbonImmutable::parse('2030-01-01'),
    );

    expect($r['entry_count'])->toBe(30)
        ->and($r['line_count'])->toBe(60)
        ->and($r['total_debit'])->toBe(3000)
        ->and($r['total_credit'])->toBe(3000)
        ->and($r['entries'])->toBeInstanceOf(Generator::class);

    $entries = iterator_to_array($r['entries']);
    expect($entries)->toHaveCount(30)
        ->and($entries[0]['date'])->toBe('2000-01-01')
        ->and($entries[0]['lines'])->toHaveCount(2);
});

it('downloads CSV and Excel over a wide range, and no longer offers PDF', function () {
    $t = Livewire::test('pages::reports.general-ledger', ['company' => $this->company])
        ->set('startDate', '1990-01-01')
        ->set('endDate', '2030-01-01');

    $t->call('exportCsv')->assertFileDownloaded();
    $t->call('exportXlsx')->assertFileDownloaded();

    $t->assertSee('CSV')->assertSee('Excel')->assertDontSee('PDF');
});

it('names the contra account in the single-account Split column, collapsing multi-leg entries to —Split—', function () {
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $ap = Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->first();

    // A three-leg entry: AR debited against two distinct contra accounts.
    $multi = JournalEntry::create([
        'company_id' => $this->company->id,
        'entry_no' => 'JE-MULTI',
        'entry_date' => CarbonImmutable::create(2001, 6, 1),
        'memo' => 'Split entry',
    ]);
    $multi->lines()->create(['account_id' => $this->ar->id, 'debit_cents' => 300, 'credit_cents' => 0, 'line_order' => 0]);
    $multi->lines()->create(['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => 100, 'line_order' => 1]);
    $multi->lines()->create(['account_id' => $ap->id, 'debit_cents' => 0, 'credit_cents' => 200, 'line_order' => 2]);
    app(JournalPoster::class)->post($multi);

    $report = app(ReportCalculator::class)->generalLedgerPaginated(
        $this->ar,
        CarbonImmutable::parse('1990-01-01'),
        CarbonImmutable::parse('2030-01-01'),
        100,
    );

    $splits = $report['lines']->pluck('split');
    expect($splits)->toContain($income->name)   // simple two-leg entries name the income side
        ->and($splits)->toContain('—Split—');   // the three-leg entry collapses

    // On screen the Split column shows, and toggling it off removes the cells
    // (the income name still appears in the account picker, so count the cells).
    $t = Livewire::test('pages::reports.general-ledger', ['company' => $this->company])
        ->set('accountId', (string) $this->ar->id)
        ->set('startDate', '1990-01-01')
        ->set('endDate', '2030-01-01')
        ->set('perPage', 100)
        ->assertSee('Split');

    expect(substr_count($t->html(), 'data-test="gl-split"'))->toBeGreaterThan(0);

    $t->call('toggleColumn', 'split');
    expect(substr_count($t->html(), 'data-test="gl-split"'))->toBe(0);
});

it('keeps the single-account running balance correct across pages', function () {
    $t = Livewire::test('pages::reports.general-ledger', ['company' => $this->company])
        ->set('accountId', (string) $this->ar->id);
    ($this->wide)($t);

    $report = $t->instance()->report;

    // Page 1: 25 of 30 lines, running climbs to $25.00, closing reflects all 30.
    expect($report['lines'])->toHaveCount(25)
        ->and($report['paginator']->total())->toBe(30)
        ->and($report['opening'])->toBe(0)
        ->and($report['closing'])->toBe(3000)
        ->and($report['lines']->last()['running'])->toBe(2500);

    // Page 2: balance brought forward continues from $25.00 to $30.00.
    $t->call('gotoPage', 2);
    $page2 = $t->instance()->report;
    expect($page2['lines'])->toHaveCount(5)
        ->and($page2['page_opening'])->toBe(2500)
        ->and($page2['lines']->last()['running'])->toBe(3000);
});
