<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\McpProposalStatus;
use App\Mcp\Tools\Write\ConfirmProposalTool;
use App\Mcp\Tools\Write\ProposeExpenseTool;
use App\Mcp\Tools\Write\ProposeInvoiceTool;
use App\Mcp\Tools\Write\ProposeJournalEntryTool;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\McpWriteProposal;
use Laravel\Mcp\Request;

/*
 | Drives the write-enabled MCP tools directly (instantiate the Tool, call handle()
 | with a Request), mirroring the read-tool tests. The propose tools must write
 | NOTHING; only ConfirmProposal commits, exactly once, idempotently.
 */

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

/** Turn on the doubly-opt-in gate: operator config flag + per-company toggle. */
function enableAgenticWrites(Company $company): void
{
    config(['mcp.write_enabled' => true]);

    $settings = $company->settings ?? [];
    $settings['mcp'] = array_merge($settings['mcp'] ?? [], ['agentic_writes' => true]);
    $company->forceFill(['settings' => $settings])->save();
}

function firstAccount(AccountType $type): Account
{
    return Account::query()
        ->where('type', $type->value)
        ->selectableForItemAccount()
        ->orderBy('code')
        ->firstOrFail();
}

function mcpBankAccount(): Account
{
    return Account::query()
        ->where('subtype', AccountSubtype::Bank->value)
        ->orderBy('code')
        ->firstOrFail();
}

/** A bound, write-enabled tenant with a full-access API key. */
function writableTenant(): Company
{
    $company = Company::factory()->create();
    enableAgenticWrites($company);
    bindMcpTenant($company);

    return $company;
}

it('Propose: stages an invoice and writes nothing to the ledger', function () {
    $company = writableTenant();
    $customer = Contact::factory()->customer()->create();
    $income = firstAccount(AccountType::Income);

    $response = (new ProposeInvoiceTool)->handle(new Request([
        'contact_id' => $customer->id,
        'invoice_date' => '2026-03-01',
        'lines' => [
            ['account' => $income->id, 'description' => 'Consulting', 'quantity' => 2, 'unit_price' => 100],
        ],
    ]));

    expect($response->isError())->toBeFalse();
    expect(Invoice::count())->toBe(0);
    expect(JournalEntry::count())->toBe(0);
    expect(McpWriteProposal::count())->toBe(1);

    $proposal = McpWriteProposal::firstOrFail();
    expect($proposal->status)->toBe(McpProposalStatus::Pending);
    expect((string) $response->content())->toContain($proposal->idempotency_key);
    expect((string) $response->content())->toContain('200.00'); // 2 x $100 subtotal
});

it('Confirm: posts exactly once and a replay is a no-op', function () {
    $company = writableTenant();
    $customer = Contact::factory()->customer()->create();
    $income = firstAccount(AccountType::Income);

    $propose = (new ProposeInvoiceTool)->handle(new Request([
        'contact_id' => $customer->id,
        'invoice_date' => '2026-03-01',
        'lines' => [
            ['account' => $income->id, 'description' => 'Consulting', 'quantity' => 1, 'unit_price' => 500],
        ],
    ]));
    expect($propose->isError())->toBeFalse();

    $token = McpWriteProposal::firstOrFail()->idempotency_key;

    $confirm = (new ConfirmProposalTool)->handle(new Request(['token' => $token]));

    expect($confirm->isError())->toBeFalse();
    expect(Invoice::count())->toBe(1);
    expect(JournalEntry::count())->toBe(1);

    $proposal = McpWriteProposal::firstOrFail();
    expect($proposal->status)->toBe(McpProposalStatus::Confirmed);
    expect($proposal->confirmed_journal_entry_id)->not->toBeNull();

    // Replay: same token, nothing changes.
    $again = (new ConfirmProposalTool)->handle(new Request(['token' => $token]));

    expect($again->isError())->toBeFalse();
    expect((string) $again->content())->toContain('already confirmed');
    expect(Invoice::count())->toBe(1);
    expect(JournalEntry::count())->toBe(1);
});

it('Propose: rejects a line targeting a system/control account', function () {
    $company = writableTenant();
    $income = firstAccount(AccountType::Income);
    $ar = Account::query()
        ->where('subtype', AccountSubtype::AccountsReceivable->value)
        ->firstOrFail();

    $response = (new ProposeJournalEntryTool)->handle(new Request([
        'entry_date' => '2026-03-01',
        'lines' => [
            ['account' => $ar->id, 'debit' => 100],
            ['account' => $income->id, 'credit' => 100],
        ],
    ]));

    expect($response->isError())->toBeTrue();
    expect((string) $response->content())->toContain('system/control');
    expect(McpWriteProposal::count())->toBe(0);
    expect(JournalEntry::count())->toBe(0);
});

it('Confirm: a locked period blocks the post with no partial write', function () {
    $company = writableTenant();
    $company->forceFill(['lock_date' => '2026-12-31'])->save();

    $expense = firstAccount(AccountType::Expense);
    $bank = mcpBankAccount();

    $propose = (new ProposeExpenseTool)->handle(new Request([
        'expense_account' => $expense->id,
        'paid_from' => $bank->id,
        'amount' => 75,
        'date' => '2026-06-01', // on/before the lock date
    ]));

    expect($propose->isError())->toBeFalse();
    expect((string) $propose->content())->toContain('lock'); // preview warns up front

    $token = McpWriteProposal::firstOrFail()->idempotency_key;
    $jeBefore = JournalEntry::count();

    $confirm = (new ConfirmProposalTool)->handle(new Request(['token' => $token]));

    expect($confirm->isError())->toBeTrue();
    expect((string) $confirm->content())->toContain('locked');
    // No partial write: the draft entry rolled back with the failed post.
    expect(JournalEntry::count())->toBe($jeBefore);

    $proposal = McpWriteProposal::firstOrFail();
    expect($proposal->status)->toBe(McpProposalStatus::Pending);
    expect($proposal->confirmed_journal_entry_id)->toBeNull();
});

it('Confirm: a token cannot commit another company\'s proposal', function () {
    // Company A stages a proposal.
    $companyA = writableTenant();
    $customer = Contact::factory()->customer()->create();
    $income = firstAccount(AccountType::Income);

    (new ProposeInvoiceTool)->handle(new Request([
        'contact_id' => $customer->id,
        'invoice_date' => '2026-03-01',
        'lines' => [['account' => $income->id, 'quantity' => 1, 'unit_price' => 100]],
    ]));

    $token = McpWriteProposal::withoutGlobalScopes()->firstOrFail()->idempotency_key;

    // Switch to company B and try to confirm A's token.
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');

    $companyB = writableTenant();

    $response = (new ConfirmProposalTool)->handle(new Request(['token' => $token]));

    expect($response->isError())->toBeTrue();
    expect((string) $response->content())->toContain('No proposal');

    // A's proposal is untouched and nothing was posted anywhere.
    $proposal = McpWriteProposal::withoutGlobalScopes()->firstOrFail();
    expect($proposal->status)->toBe(McpProposalStatus::Pending);
    expect(Invoice::withoutGlobalScopes()->count())->toBe(0);
    expect(JournalEntry::withoutGlobalScopes()->count())->toBe(0);
});

it('Propose: refused when agentic writes are not enabled for the company', function () {
    $company = Company::factory()->create();
    config(['mcp.write_enabled' => true]); // operator on, company OFF
    bindMcpTenant($company);

    $income = firstAccount(AccountType::Income);
    $customer = Contact::factory()->customer()->create();

    $response = (new ProposeInvoiceTool)->handle(new Request([
        'contact_id' => $customer->id,
        'invoice_date' => '2026-03-01',
        'lines' => [['account' => $income->id, 'quantity' => 1, 'unit_price' => 100]],
    ]));

    expect($response->isError())->toBeTrue();
    expect((string) $response->content())->toContain('not enabled agentic writes');
    expect(McpWriteProposal::count())->toBe(0);
});
