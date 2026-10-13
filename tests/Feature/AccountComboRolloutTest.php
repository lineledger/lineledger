<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\InboxItemSource;
use App\Enums\InboxItemStatus;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\InboxItem;
use App\Models\TaxCode;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function comboAccount(string $code, string $name, AccountSubtype $subtype = AccountSubtype::Expense, array $extra = []): Account
{
    return Account::create([
        'code' => $code,
        'name' => $name,
        'subtype' => $subtype->value,
        'type' => $subtype->type()->value,
        'normal_balance' => $subtype->type()->normalBalance()->value,
        ...$extra,
    ]);
}

/**
 * The page compiled (a literal "<flux:" means Blade's component compiler choked
 * and the field silently vanished), the typeable combo is wired to the right
 * Livewire path, and the options payload it reads is on the page exactly once.
 */
function expectAccountCombo(string $html, string $dataTest, string $key, string $model): void
{
    expect($html)
        ->not->toContain('<flux:')
        ->toContain('x-data="accountCombo"')
        ->toContain('role="combobox"')
        ->toContain('data-test="'.$dataTest.'"')
        ->toContain('data-model="'.$model.'"')
        ->toContain('data-options="'.$key.'"')
        ->toContain('data-test="account-combo-list"')
        ->and(substr_count($html, 'data-account-options="'.$key.'"'))->toBe(1);
}

/**
 * Every line "Account" column is now the typeable <x-account-combo> rather than
 * a native <select>, so a GL number can be typed.
 */
it('renders the account combo on every converted form', function (string $route, string $dataTest, string $key, string $model) {
    $html = $this->get(route($route, ['company' => $this->company->slug]))
        ->assertOk()
        ->getContent();

    expectAccountCombo($html, $dataTest, $key, $model);

    // The old select is gone.
    expect($html)->not->toContain('wire:model.live="'.$model.'"')
        ->not->toContain('wire:model="'.$model.'"');
})->with([
    'cheques' => ['cheques.create', 'line-account', 'lineAccounts', 'lines.0.account_id'],
    'expenses' => ['expenses.create', 'line-account', 'lineAccounts', 'lines.0.account_id'],
    'bills' => ['bills.create', 'line-account', 'expenseAccounts', 'lines.0.account_id'],
    'journal' => ['journal.create', 'line-account', 'accounts', 'lines.0.account_id'],
    'invoices' => ['invoices.create', 'line-account', 'accounts', 'lines.0.account_id'],
    'credit memos' => ['credit-memos.create', 'line-account', 'accounts', 'lines.0.account_id'],
    'vendor credits' => ['vendor-credits.create', 'line-account', 'accounts', 'lines.0.account_id'],
    'sales receipts' => ['sales-receipts.create', 'sr-line-account', 'accounts', 'lines.0.account_id'],
    'purchase orders' => ['purchase-orders.create', 'line-account', 'accounts', 'lines.0.account_id'],
    'estimates' => ['estimates.create', 'line-account', 'accounts', 'lines.0.account_id'],
    'sales orders' => ['sales-orders.create', 'line-account', 'accounts', 'lines.0.account_id'],
    'recurring documents' => ['recurring.create', 'line-account', 'accounts', 'lines.0.account_id'],
    'recurring journal' => ['recurring-journal.create', 'line-account', 'accounts', 'lines.0.account_id'],
    'journal entry templates' => ['journal-entry-templates.create', 'line-account', 'accounts', 'lines.0.account_id'],
    'invoice templates' => ['invoice-templates.create', 'line-account', 'accounts', 'lines.0.account_id'],
    'reimbursements' => ['reimbursements.create', 'line-account', 'expenseAccounts', 'lines.0.account_id'],
    'budgets' => ['budgets.create', 'budget-row-account', 'accounts', 'rows.0.account_id'],
]);

it('keeps the line-first focus hook on the cheque and expense account inputs', function (string $route) {
    $html = $this->get(route($route, ['company' => $this->company->slug]))->assertOk()->getContent();

    expect($html)->toMatch('/<input[^>]*data-test="line-account"[^>]*data-line-first="0"|<input[^>]*data-line-first="0"[^>]*data-test="line-account"/');
})->with(['cheques.create', 'expenses.create']);

it('binds a deferred combo where the select used plain wire:model', function () {
    $html = $this->get(route('journal-entry-templates.create', ['company' => $this->company->slug]))->getContent();
    expect($html)->toContain('data-live="false"');

    $html = $this->get(route('cheques.create', ['company' => $this->company->slug]))->getContent();
    expect($html)->toContain('data-live="true"')->not->toContain('data-live="false"');
});

it('renders the account combo on the deposit other-lines table', function () {
    $html = Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->call('addOtherLine')
        ->html();

    expectAccountCombo($html, 'other-line-account', 'otherAccounts', 'otherLines.0.account_id');
    expect($html)->toContain('data-live="false"');
});

it('renders the account combo on the tax return adjustments', function () {
    $gst = TaxCode::where('code', 'GST')->firstOrFail();

    $html = Livewire::test('pages::tax-returns.form', ['company' => $this->company])
        ->set('tax_agency_id', $gst->agency_id)
        ->set('period_start', '2026-08-01')
        ->set('period_end', '2026-08-31')
        ->call('addAdjustment')
        ->assertSet('adjustments.0.account_id', $gst->agency->payableAccount->id)
        ->html();

    expectAccountCombo($html, 'adjustment-account-0', 'adjustmentAccounts', 'adjustments.0.account_id');
    // The agency's payable account keeps its "(no ledger entry)" note.
    expect($html)->toContain(__('(no ledger entry)'));
});

it('renders the account combo on the inbox review lines', function () {
    Storage::fake('local');

    $item = InboxItem::create([
        'source' => InboxItemSource::Upload,
        'status' => InboxItemStatus::NeedsReview,
        'original_filename' => 'receipt.jpg',
        'mime' => 'image/jpeg',
        'created_by_user_id' => $this->user->id,
    ]);
    $path = 'attachments/'.$this->company->id.'/inbox_items/'.$item->id.'/receipt.jpg';
    Storage::disk('local')->put($path, 'fake-bytes');
    $attachment = Attachment::create([
        'attachable_type' => $item->getMorphClass(),
        'attachable_id' => $item->id,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'receipt.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 10,
        'uploaded_by_id' => $this->user->id,
    ]);
    $item->forceFill([
        'attachment_id' => $attachment->id,
        'suggested_document_type' => 'bill',
        'extracted' => ['vendor' => 'Some Vendor', 'amount_cents' => 6000, 'currency' => 'CAD', 'date' => '2026-06-20'],
    ])->save();

    $html = Livewire::test('pages::inbox.show', ['company' => $this->company, 'item' => $item->fresh()])->html();

    expectAccountCombo($html, 'inbox-line-account', 'accounts', 'lines.0.account_id');
});

it('renders the account combo on the banking review rows and split modal from one payload', function () {
    $bank = Account::query()
        ->where('subtype', AccountSubtype::Bank->value)
        ->where('is_active', true)
        ->orderBy('code')
        ->firstOrFail();
    $suggested = comboAccount('5990', 'Bank Charges Suggested');
    $import = BankStatementImport::factory()->create(['account_id' => $bank->id]);
    $lines = collect([10000, -2500])->map(fn (int $cents) => BankStatementLine::factory()->create([
        'bank_statement_import_id' => $import->id,
        'account_id' => $bank->id,
        'txn_date' => '2026-06-10',
        'amount_cents' => $cents,
        'description' => 'TXN '.$cents,
        'match_status' => 'unmatched',
        'suggested_account_id' => $cents < 0 ? $suggested->id : null,
        'created_journal_entry_id' => null,
    ]));

    $component = Livewire::test('pages::banking.review', ['company' => $this->company]);
    $html = $component->html();

    expectAccountCombo($html, 'review-category', 'categories', 'categories.'.$lines[0]->id);
    expect($html)
        ->toContain('data-model="categories.'.$lines[1]->id.'"')
        // A suggestion shows as the empty combo's placeholder, as it did as the select's empty option.
        ->toContain('placeholder="5990 — Bank Charges Suggested"')
        ->toContain('data-live="false"');

    $html = $component->call('openSplit', $lines[0]->id)->html();

    expect($html)
        ->not->toContain('<flux:')
        ->toContain('data-test="split-account"')
        ->toContain('data-model="splits.0.account_id"')
        ->and(substr_count($html, 'data-account-options="categories"'))->toBe(1);
});

it('emits the account list once per page, not once per row', function () {
    comboAccount('5995', 'Zyx Unique Supplies');

    $component = Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->call('addLine')
        ->call('addLine')
        ->call('addLine');

    $rows = count($component->get('lines'));
    $html = $component->html();

    expect($rows)->toBeGreaterThanOrEqual(4)
        ->and(substr_count($html, 'x-data="accountCombo"'))->toBe($rows)
        ->and(substr_count($html, 'data-account-options="lineAccounts"'))->toBe(1)
        ->and(substr_count($html, 'Zyx Unique Supplies'))->toBe(1);
});

it('escapes account names inside the options payload', function () {
    comboAccount('5996', '</script><script>alert(1)</script>');

    $html = $this->get(route('cheques.create', ['company' => $this->company->slug]))->assertOk()->getContent();

    expect($html)
        ->not->toContain('<script>alert(1)</script>')
        ->toContain('\u003Cscript\u003Ealert(1)');
});

it('leaves the options payload off the invoice form when the account column is hidden', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->assertSeeHtml('data-account-options="accounts"')
        ->set('fieldVisibility.account_column', false)
        ->assertDontSeeHtml('data-account-options="accounts"')
        ->assertDontSeeHtml('data-test="line-account"');
});

/*
 * Picking in the combo writes through $wire.$set() with the id as a string (as
 * the select sent it), so the server's updated*() hooks still run.
 */
it('fills a blank tax code from the picked account on a bill line', function () {
    $tax = TaxCode::where('code', 'GST')->firstOrFail();
    $account = comboAccount('5997', 'Taxed Supplies', extra: ['default_tax_code_id' => $tax->id]);

    Livewire::test('pages::bills.form', ['company' => $this->company])
        ->set('lines.0.account_id', (string) $account->id)
        ->assertSet('lines.0.tax_code_id', $tax->id)
        ->assertSeeHtml('data-model="lines.0.account_id"');
});

it('asks for the customer when a cheque or journal line picks the receivable account', function (string $component) {
    $receivable = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->orderBy('code')->firstOrFail();

    Livewire::test($component, ['company' => $this->company])
        ->assertDontSeeHtml('data-test="line-customer-combo"')
        ->set('lines.0.account_id', (string) $receivable->id)
        ->assertSeeHtml('data-test="line-customer-combo"')
        // The "—" choice sends '' (the select's empty option), which clears it again.
        ->set('lines.0.account_id', '')
        ->assertDontSeeHtml('data-test="line-customer-combo"')
        ->assertHasNoErrors();
})->with(['pages::cheques.form', 'pages::journal.form']);

it('keeps a deferred pick on a deposit other line', function () {
    $equity = Account::query()->where('subtype', AccountSubtype::Equity->value)->orderBy('code')->first()
        ?? comboAccount('3999', 'Owner Contribution', AccountSubtype::Equity);

    Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->call('addOtherLine')
        ->set('otherLines.0.account_id', (string) $equity->id)
        ->assertSet('otherLines.0.account_id', (string) $equity->id)
        ->assertSeeHtml('data-model="otherLines.0.account_id"');
});

/*
 * Clearing is one keystroke away in a typeable box (empty it and Tab), so every
 * converted form must take the "—" value ('') after a real pick without falling
 * over — the cheque form used to throw on it.
 */
it('clears a picked account back to "—" without breaking the form', function (string $component, string $model) {
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();

    $html = Livewire::test($component, ['company' => $this->company])
        ->set($model, (string) $expense->id)
        ->set($model, '')
        ->assertSet($model, '')
        ->html();

    expect($html)->toContain('data-model="'.$model.'"');
})->with([
    'cheques' => ['pages::cheques.form', 'lines.0.account_id'],
    'expenses' => ['pages::expenses.form', 'lines.0.account_id'],
    'bills' => ['pages::bills.form', 'lines.0.account_id'],
    'journal' => ['pages::journal.form', 'lines.0.account_id'],
    'invoices' => ['pages::invoices.form', 'lines.0.account_id'],
    'credit memos' => ['pages::credit-memos.form', 'lines.0.account_id'],
    'vendor credits' => ['pages::vendor-credits.form', 'lines.0.account_id'],
    'sales receipts' => ['pages::sales-receipts.form', 'lines.0.account_id'],
    'purchase orders' => ['pages::purchase-orders.form', 'lines.0.account_id'],
    'estimates' => ['pages::estimates.form', 'lines.0.account_id'],
    'sales orders' => ['pages::sales-orders.form', 'lines.0.account_id'],
    'recurring documents' => ['pages::recurring.form', 'lines.0.account_id'],
    'recurring journal' => ['pages::recurring-journal.form', 'lines.0.account_id'],
    'journal entry templates' => ['pages::journal-entry-templates.form', 'lines.0.account_id'],
    'invoice templates' => ['pages::invoice-templates.form', 'lines.0.account_id'],
    'reimbursements' => ['pages::reimbursements.form', 'lines.0.account_id'],
    'budgets' => ['pages::budgets.form', 'rows.0.account_id'],
]);
