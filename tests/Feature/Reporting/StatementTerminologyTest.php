<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Post one revenue and one expense entry so the equity / net-income lines have
 * figures to display on the standard Balance Sheet and Income Statement.
 */
function termCompany(string $organizationType): Company
{
    $company = Company::factory()->create([
        'fiscal_year_start_month' => 1,
        'address_country' => 'CA',
        'organization_type' => $organizationType,
    ]);
    app()->instance('current_company', $company);

    $bank = Account::query()->where('type', AccountType::Asset->value)->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $income = Account::query()->where('type', AccountType::Income->value)->orderBy('code')->first();
    $expense = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->first();

    $revenue = JournalEntry::create(['entry_no' => 'JE-R', 'entry_date' => '2026-03-01', 'is_posted' => true]);
    $revenue->lines()->create(['account_id' => $bank->id, 'debit_cents' => 50000, 'credit_cents' => 0, 'line_order' => 0]);
    $revenue->lines()->create(['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => 50000, 'line_order' => 1]);

    $cost = JournalEntry::create(['entry_no' => 'JE-E', 'entry_date' => '2026-04-01', 'is_posted' => true]);
    $cost->lines()->create(['account_id' => $expense->id, 'debit_cents' => 20000, 'credit_cents' => 0, 'line_order' => 0]);
    $cost->lines()->create(['account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => 20000, 'line_order' => 1]);

    return $company;
}

afterEach(function () {
    app()->forgetInstance('current_company');
});

test('the standard balance sheet speaks net assets for a non-profit', function () {
    $company = termCompany('charity');

    Livewire::test('pages::reports.balance-sheet', ['company' => $company])
        ->assertOk()
        ->assertSee('Net Assets')
        ->assertSee('Total Liabilities & Net Assets')
        ->assertSee('Excess (deficiency) of revenue over expenses')
        ->assertDontSee('Total Liabilities & Equity');
});

test('the standard income statement speaks the excess of revenue over expenses for a non-profit', function () {
    $company = termCompany('charity');

    Livewire::test('pages::reports.income-statement', ['company' => $company])
        ->assertOk()
        ->assertSee('Excess (deficiency) of revenue over expenses')
        ->assertDontSee('Net Income');
});

test('the standard balance sheet keeps entity-specific equity wording for a corporation', function () {
    $company = termCompany('corporation');

    Livewire::test('pages::reports.balance-sheet', ['company' => $company])
        ->assertOk()
        ->assertSee("Shareholders' Equity")
        ->assertSee('Total Liabilities & Equity')
        ->assertDontSee('Net Assets');
});

test('the shared PDF and XLSX templates build for a non-profit balance sheet', function () {
    // Regression guard: the for-profit and non-profit balance sheets share these
    // templates, which now consume StatementLabels — building them must not throw.
    $company = termCompany('charity');

    $component = Livewire::test('pages::reports.balance-sheet', ['company' => $company]);

    expect($component->instance()->exportPdf())->toBeInstanceOf(BinaryFileResponse::class)
        ->and($component->instance()->exportXlsx())->toBeInstanceOf(BinaryFileResponse::class);
});
