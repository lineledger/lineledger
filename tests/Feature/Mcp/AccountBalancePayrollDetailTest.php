<?php

declare(strict_types=1);

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Mcp\Tools\AccountBalanceTool;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PayrollCheque;
use App\Models\User;
use App\Services\Posting\JournalPoster;
use Laravel\Mcp\Request;

/**
 * The account-activity tool shows payroll lines the way the web app does:
 * "Payroll" in place of the memo (which names the employee) unless the
 * connection may open pay runs (PayrollDetail). Amounts stay.
 */
afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

it('reads payroll memos as "Payroll" for a key whose creator cannot open pay runs', function () {
    $company = Company::factory()->create();
    $creator = User::factory()->create();
    // Legacy Custom sections give Full (View balance included) on Accounts.
    $membership = $company->memberships()->create([
        'user_id' => $creator->id,
        'role' => CompanyRole::Custom,
        'sections' => [Section::Accounting->value, Section::Reports->value],
    ]);

    ['key' => $key] = CompanyApiKey::mint($company->refresh(), 'Ledger key', $creator->id);
    app()->instance('current_company', $company);
    app()->instance('current_api_key', $key);

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();

    $entry = JournalEntry::create([
        'entry_no' => 'PAY-0007',
        'entry_date' => $company->currentDateTime()->toDateString(),
        'memo' => 'Payroll cheque 7 — Jane Employee',
        'source_type' => PayrollCheque::class,
        'is_posted' => true,
    ]);
    JournalLine::create(['journal_entry_id' => $entry->id, 'account_id' => $expense->id, 'debit_cents' => 123_456, 'credit_cents' => 0, 'memo' => 'Net pay', 'line_order' => 0]);
    JournalLine::create(['journal_entry_id' => $entry->id, 'account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => 123_456, 'memo' => 'Payroll cheque 7 — Jane Employee', 'line_order' => 1]);

    // Its void copies the memos but has no source of its own.
    $reversal = app(JournalPoster::class)->void($entry);

    $activity = fn (): string => (string) (new AccountBalanceTool)->handle(new Request(['account' => $bank->code, 'period' => 'ytd']))->content();

    expect($activity())->not->toContain('Jane Employee')
        ->toContain('PAY-0007')
        ->toContain($reversal->entry_no)
        ->toContain('— Payroll');

    $membership->update(['sections' => [Section::Accounting->value, Section::Reports->value, Section::Payroll->value]]);

    expect($activity())->toContain('Payroll cheque 7 — Jane Employee');
});
