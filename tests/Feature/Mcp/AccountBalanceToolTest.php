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
use Tests\Support\Mcp\StatementOfAccountFixture;

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

it('AccountBalance: pages through the ledger and narrows it by reference or contact', function () {
    $soa = new StatementOfAccountFixture;
    $fullService = $soa->fullServiceInvoice();
    $repatriation = $soa->repatriationInvoice();
    overpaymentReceipt($fullService->contact, 100000, $fullService, '2026-10-15');
    bindMcpTenant($soa->company);

    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->orderBy('code')->firstOrFail();
    $ask = fn (array $args): string => (string) (new AccountBalanceTool)->handle(new Request([
        'account' => $ar->code, 'start' => '2026-01-01', 'end' => '2026-12-31', ...$args,
    ]))->content();

    expect($ask(['limit' => 1]))
        ->toContain('most recent first (1–1 of 3)')
        ->toContain('2 more line(s) not shown — pass offset=1');

    expect($ask(['limit' => 1, 'offset' => 2]))
        ->toContain('(3–3 of 3)')
        ->toContain($fullService->journalEntry->entry_no)
        ->not->toContain('more line(s) not shown');

    // The invoice's AR line carries "AR — <customer>"; its entry memo names the invoice.
    expect($ask(['reference' => 'inv 90/001']))
        ->toContain('(1–1 of 1)')
        ->toContain($repatriation->journalEntry->entry_no);

    expect($ask(['contact' => 'Example, Robin']))
        ->toContain('for contact Example, Robin - 90/002, most recent first (1–2 of 2)')
        ->not->toContain($repatriation->journalEntry->entry_no);
});
