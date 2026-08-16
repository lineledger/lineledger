<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\OrganizationType;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Reporting\ReportCatalog;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1, 'organization_type' => OrganizationType::Corporation->value]);
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function gifiEntry(Account $debit, Account $credit, int $cents, string $date): void
{
    $entry = JournalEntry::create(['entry_no' => uniqid('JE-'), 'entry_date' => $date, 'is_posted' => true]);
    $entry->lines()->create(['account_id' => $debit->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $credit->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'line_order' => 1]);
}

/** Find a GIFI line by code anywhere in a half-set. */
function gifiLine(array $halves, string $code): ?array
{
    foreach ($halves as $half) {
        foreach ($half['sections'] as $section) {
            foreach ($section['lines'] as $line) {
                if ($line['code'] === $code) {
                    return $line;
                }
            }
        }
    }

    return null;
}

it('groups accounts by GIFI line and keeps the balance sheet balanced', function () {
    // Revenue of $1,000 into the bank; $400 expense out of the bank.
    gifiEntry($this->bank, $this->income, 100000, '2026-03-15');
    gifiEntry($this->expense, $this->bank, 40000, '2026-03-20');

    $report = Livewire::test('pages::reports.gifi', ['company' => $this->company])
        ->instance()
        ->report();

    // Bank → GIFI 1001 (current assets), cumulative balance $600.
    $bankLine = gifiLine($report['bs']['halves'], '1001');
    expect($bankLine)->not->toBeNull()
        ->and($bankLine['amount'])->toBe(60000);

    // Revenue → GIFI 8000, expense → GIFI 9270, on the income statement.
    expect(gifiLine($report['is']['halves'], '8000')['amount'])->toBe(100000)
        ->and(gifiLine($report['is']['halves'], '9270')['amount'])->toBe(40000)
        ->and($report['is']['net_income'])->toBe(60000);

    // Assets = Liabilities + Equity (equity carries the year's net income).
    expect($report['bs']['total_assets'])->toBe(60000)
        ->and($report['bs']['total_le'])->toBe(60000)
        ->and($report['bs']['balanced'])->toBeTrue();
});

it('surfaces accounts with no GIFI line in the unassigned bucket', function () {
    gifiEntry($this->bank, $this->income, 50000, '2026-03-15');
    $this->bank->update(['gifi_code' => null]);

    $report = Livewire::test('pages::reports.gifi', ['company' => $this->company])
        ->instance()
        ->report();

    // The nulled bank shows in Unassigned (with its $500 balance) and no longer
    // contributes to its old GIFI line.
    $unassigned = collect($report['unassigned']['lines'])->firstWhere('id', $this->bank->id);
    expect($unassigned)->not->toBeNull()
        ->and($unassigned['amount'])->toBe(50000);

    $cashMembers = collect(gifiLine($report['bs']['halves'], '1001')['accounts'] ?? [])->pluck('id');
    expect($cashMembers)->not->toContain($this->bank->id);
});

it('renders the statement for a Canadian company', function () {
    gifiEntry($this->bank, $this->income, 100000, '2026-03-15');

    Livewire::test('pages::reports.gifi', ['company' => $this->company])
        ->assertOk()
        ->assertSee('Schedule 100')
        ->assertSee('Schedule 125')
        ->assertSee('Cash and deposits');
});

it('reassigns an account to a different GIFI line from the report', function () {
    gifiEntry($this->bank, $this->income, 50000, '2026-03-15');

    Livewire::test('pages::reports.gifi', ['company' => $this->company])
        ->call('reassign', $this->bank->id, '1180');

    expect($this->bank->fresh()->gifi_code)->toBe('1180');
});

it('ignores reassignment to an unknown GIFI code', function () {
    Livewire::test('pages::reports.gifi', ['company' => $this->company])
        ->call('reassign', $this->bank->id, '9999');

    expect($this->bank->fresh()->gifi_code)->toBe('1001');
});

it('is available to Canadian companies and hidden from others', function () {
    expect(ReportCatalog::flatten($this->company, $this->user))->toHaveKey('reports.gifi');

    $us = Company::factory()->create(['address_country' => 'US']);
    $us->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    expect(ReportCatalog::flatten($us, $this->user))->not->toHaveKey('reports.gifi');

    $this->actingAs($this->user)
        ->get(route('reports.gifi', ['company' => $us->slug]))
        ->assertNotFound();
});
