<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Mcp\Tools\AccountBalanceTool;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Laravel\Mcp\Request;

/*
 | These tests avoid hardcoding any specific AccountSubtype case name. Instead
 | they derive a valid subtype for the required AccountType at runtime from
 | AccountSubtype::cases(), so the suite never fatals on an unexpected enum name.
 */

it('AccountBalance: reports balance and recent ledger activity for a matched account', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company);

    $assetSubtype = collect(AccountSubtype::cases())
        ->first(fn (AccountSubtype $s): bool => $s->type() === AccountType::Asset);
    $equitySubtype = collect(AccountSubtype::cases())
        ->first(fn (AccountSubtype $s): bool => $s->type() === AccountType::Equity);

    $cash = Account::create([
        'company_id' => $company->id,
        'code' => 'ZZ-1001',
        'name' => 'Test Chequing Account',
        'type' => AccountType::Asset,
        'subtype' => $assetSubtype,
        'normal_balance' => NormalBalance::Debit,
    ]);

    $equity = Account::create([
        'company_id' => $company->id,
        'code' => 'ZZ-3001',
        'name' => 'Test Opening Equity',
        'type' => AccountType::Equity,
        'subtype' => $equitySubtype,
        'normal_balance' => NormalBalance::Credit,
    ]);

    $entry = JournalEntry::create([
        'company_id' => $company->id,
        'entry_no' => 'JE-AB-1',
        'entry_date' => '2026-01-15',
        'memo' => 'Initial funding',
        'is_posted' => true,
    ]);

    JournalLine::create([
        'journal_entry_id' => $entry->id,
        'account_id' => $cash->id,
        'debit_cents' => 50000,
        'credit_cents' => 0,
        'memo' => 'Deposit',
        'line_order' => 0,
    ]);

    JournalLine::create([
        'journal_entry_id' => $entry->id,
        'account_id' => $equity->id,
        'debit_cents' => 0,
        'credit_cents' => 50000,
        'line_order' => 1,
    ]);

    $request = new Request([
        'account' => 'Test Chequing',
        'period' => 'this_year',
    ]);

    $response = (new AccountBalanceTool)->handle($request);

    $content = (string) $response->content();

    expect($response->isError())->toBeFalse();
    expect($content)->toContain('Test Chequing Account');
    expect($content)->toContain('ZZ-1001');
    expect($content)->toContain('JE-AB-1');
    expect($content)->toContain('500.00');
});

it('AccountBalance: asks to disambiguate when multiple accounts match', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company);

    $expenseSubtype = collect(AccountSubtype::cases())
        ->first(fn (AccountSubtype $s): bool => $s->type() === AccountType::Expense);

    Account::create([
        'company_id' => $company->id,
        'code' => 'ZZ-4100',
        'name' => 'Zzmarketing Spend',
        'type' => AccountType::Expense,
        'subtype' => $expenseSubtype,
        'normal_balance' => NormalBalance::Debit,
    ]);

    Account::create([
        'company_id' => $company->id,
        'code' => 'ZZ-4200',
        'name' => 'Zzmarketing Software',
        'type' => AccountType::Expense,
        'subtype' => $expenseSubtype,
        'normal_balance' => NormalBalance::Debit,
    ]);

    $request = new Request(['account' => 'Zzmarketing']);

    $response = (new AccountBalanceTool)->handle($request);

    $content = (string) $response->content();

    expect($response->isError())->toBeFalse();
    expect($content)->toContain('Several accounts match');
    expect($content)->toContain('ZZ-4100');
    expect($content)->toContain('ZZ-4200');
});

it('AccountBalance: returns a friendly message when no account matches', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company);

    $request = new Request(['account' => 'Nonexistent Account ZZQQ']);

    $response = (new AccountBalanceTool)->handle($request);

    expect($response->isError())->toBeFalse();
    expect((string) $response->content())->toContain('No account matched');
});

it('AccountBalance: errors when the accounting:read ability is missing', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company, ['sales:read']);

    $request = new Request(['account' => 'Cash']);

    $response = (new AccountBalanceTool)->handle($request);

    expect($response->isError())->toBeTrue();
    expect((string) $response->content())->toContain('accounting:read');
});
