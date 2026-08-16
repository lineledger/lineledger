<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Posting\JournalPoster;
use App\Services\Reporting\FinancialMetrics;
use App\Support\Reporting\ReportCatalog;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1]);
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $this->company);

    $this->postToBank = function (string $date, int $cents): JournalEntry {
        $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
        $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

        $entry = JournalEntry::create([
            'company_id' => $this->company->id,
            'entry_no' => 'JE-CASH-'.$date.'-'.$cents,
            'entry_date' => CarbonImmutable::parse($date),
            'memo' => 'Cash on hand test',
        ]);
        $entry->lines()->create(['account_id' => $bank->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'line_order' => 0]);
        $entry->lines()->create(['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'line_order' => 1]);
        app(JournalPoster::class)->post($entry);

        return $entry;
    };
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('lists only bank and undeposited-funds accounts', function () {
    $this->actingAs($this->user);

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();

    $rows = Livewire::actingAs($this->user)
        ->test('pages::reports.cash-on-hand', ['company' => $this->company])
        ->instance()
        ->rows();

    $subtypes = collect($rows)->pluck('subtype')->unique();

    expect(collect($rows)->pluck('id'))->toContain($bank->id)
        ->and(collect($rows)->pluck('id'))->not->toContain($ar->id)
        ->and($subtypes->diff([AccountSubtype::Bank->label(), AccountSubtype::UndepositedFunds->label()]))->toBeEmpty();

    $this->get(route('reports.cash-on-hand', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee($bank->name);
});

it('honors the as-of date on balances and the total', function () {
    ($this->postToBank)('2026-05-10', 5000);
    ($this->postToBank)('2026-06-05', 7000); // after the as-of date — must be excluded

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();

    $component = Livewire::actingAs($this->user)
        ->test('pages::reports.cash-on-hand', ['company' => $this->company])
        ->set('asOf', '2026-05-31')
        ->instance();

    expect(collect($component->rows())->firstWhere('id', $bank->id)['balance'])->toBe(5000)
        ->and($component->total())->toBe(5000);

    $later = Livewire::actingAs($this->user)
        ->test('pages::reports.cash-on-hand', ['company' => $this->company])
        ->set('asOf', '2026-06-30')
        ->instance();

    expect(collect($later->rows())->firstWhere('id', $bank->id)['balance'])->toBe(12000)
        ->and($later->total())->toBe(12000);
});

it('reconciles the total with the dashboard cash-on-hand metric', function () {
    ($this->postToBank)('2026-05-10', 5000);
    ($this->postToBank)('2026-06-05', 7000);

    $today = $this->company->currentDateTime()->toDateString();

    $total = Livewire::actingAs($this->user)
        ->test('pages::reports.cash-on-hand', ['company' => $this->company])
        ->set('asOf', $today)
        ->instance()
        ->total();

    expect($total)->toBe(app(FinancialMetrics::class)->cashOnHand($this->company, CarbonImmutable::parse($today)));
});

it('links each account row to the general ledger drill', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();

    $this->actingAs($this->user);

    $drillUrl = route('reports.general-ledger', [
        'company' => $this->company->slug,
        'account' => $bank->id,
        'start' => '2026-01-01', // fiscal_year_start_month = 1
        'end' => $this->company->currentDateTime()->toDateString(),
    ]);

    $this->get(route('reports.cash-on-hand', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee(e($drillUrl), false);
});

it('excludes unposted draft entries from balances', function () {
    ($this->postToBank)('2026-05-10', 5000);

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $draft = JournalEntry::create([
        'company_id' => $this->company->id,
        'entry_no' => 'JE-CASH-DRAFT',
        'entry_date' => CarbonImmutable::parse('2026-05-11'),
        'memo' => 'Unposted draft',
    ]);
    $draft->lines()->create(['account_id' => $bank->id, 'debit_cents' => 9900, 'credit_cents' => 0, 'line_order' => 0]);
    $draft->lines()->create(['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => 9900, 'line_order' => 1]);

    $component = Livewire::actingAs($this->user)
        ->test('pages::reports.cash-on-hand', ['company' => $this->company])
        ->set('asOf', '2026-05-31')
        ->instance();

    expect($component->total())->toBe(5000);
});

it('appears in the report catalog and dashboard card links to it', function () {
    expect(array_keys(ReportCatalog::flatten($this->company, $this->user)))
        ->toContain('reports.cash-on-hand');

    $this->actingAs($this->user);

    $this->get(route('dashboard', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee(route('reports.cash-on-hand', ['company' => $this->company->slug]), false);
});
