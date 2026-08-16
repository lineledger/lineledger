<?php

use App\Actions\Accounting\SaveJournalEntry;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\TaxAppliesTo;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\TaxCode;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->gst = TaxCode::where('code', 'GST')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function cascadeAccount(AccountSubtype $subtype): Account
{
    return Account::query()->where('subtype', $subtype->value)->orderBy('code')->firstOrFail();
}

function cascadeSecondTaxCode(): TaxCode
{
    return TaxCode::create([
        'code' => 'CSCD',
        'name' => 'Cascade Tax',
        'rate_basis_points' => 700,
        'applies_to' => TaxAppliesTo::Both,
        'is_active' => true,
    ]);
}

dataset('cascade forms', [
    'invoice form' => ['pages::invoices.form', AccountSubtype::Income],
    'bill form' => ['pages::bills.form', AccountSubtype::Expense],
    'cheque form' => ['pages::cheques.form', AccountSubtype::Expense],
    'journal form' => ['pages::journal.form', AccountSubtype::Expense],
    'estimate form' => ['pages::estimates.form', AccountSubtype::Income],
    'sales order form' => ['pages::sales-orders.form', AccountSubtype::Income],
    'credit memo form' => ['pages::credit-memos.form', AccountSubtype::Income],
    'purchase order form' => ['pages::purchase-orders.form', AccountSubtype::Expense],
    'vendor credit form' => ['pages::vendor-credits.form', AccountSubtype::Expense],
    'recurring form' => ['pages::recurring.form', AccountSubtype::Income],
]);

it('fills a blank line tax code from the account default when an account is picked', function (string $component, AccountSubtype $subtype) {
    $account = cascadeAccount($subtype);
    $account->update(['default_tax_code_id' => $this->gst->id]);

    Livewire::test($component, ['company' => $this->company])
        ->set('lines.0.account_id', $account->id)
        ->assertSet('lines.0.tax_code_id', $this->gst->id);
})->with('cascade forms');

it('never overwrites a tax code already on the line when the account changes', function (string $component, AccountSubtype $subtype) {
    $other = cascadeSecondTaxCode();
    $account = cascadeAccount($subtype);
    $account->update(['default_tax_code_id' => $this->gst->id]);

    Livewire::test($component, ['company' => $this->company])
        ->set('lines.0.tax_code_id', $other->id)
        ->set('lines.0.account_id', $account->id)
        ->assertSet('lines.0.tax_code_id', $other->id);
})->with([
    'invoice form' => ['pages::invoices.form', AccountSubtype::Income],
    'bill form' => ['pages::bills.form', AccountSubtype::Expense],
    'cheque form' => ['pages::cheques.form', AccountSubtype::Expense],
    'journal form' => ['pages::journal.form', AccountSubtype::Expense],
]);

it('leaves the tax code blank when the picked account has no default', function (string $component, AccountSubtype $subtype) {
    $account = cascadeAccount($subtype);
    expect($account->default_tax_code_id)->toBeNull();

    Livewire::test($component, ['company' => $this->company])
        ->set('lines.0.account_id', $account->id)
        ->assertSet('lines.0.tax_code_id', null);
})->with([
    'invoice form' => ['pages::invoices.form', AccountSubtype::Income],
    'bill form' => ['pages::bills.form', AccountSubtype::Expense],
    'cheque form' => ['pages::cheques.form', AccountSubtype::Expense],
    'journal form' => ['pages::journal.form', AccountSubtype::Expense],
]);

it('recalculates the invoice line with the account-default tax code', function () {
    $income = cascadeAccount(AccountSubtype::Income);
    $income->update(['default_tax_code_id' => $this->gst->id]);

    // GST is 5%, so a $100.00 line picks up $5.00 of tax once the account fills it.
    expect($this->gst->taxFor(10000))->toBe(500);

    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('lines.0.unit_price', '100.00')
        ->assertSet('lines.0.tax', 0)
        ->set('lines.0.account_id', $income->id)
        ->assertSet('lines.0.tax_code_id', $this->gst->id)
        ->assertSet('lines.0.tax', 500)
        ->assertSet('lines.0.total', 10500);
});

it('keeps item-default precedence: picking an item overwrites the tax code', function () {
    $other = cascadeSecondTaxCode();
    $income = cascadeAccount(AccountSubtype::Income);
    $income->update(['default_tax_code_id' => $this->gst->id]);

    $item = Item::create([
        'name' => 'Cascade Widget',
        'income_account_id' => $income->id,
        'default_price_cents' => 5000,
        'default_tax_code_id' => $other->id,
        'is_active' => true,
    ]);

    Livewire::test('pages::invoices.form', ['company' => $this->company])
        // The account default fills the blank line first...
        ->set('lines.0.account_id', $income->id)
        ->assertSet('lines.0.tax_code_id', $this->gst->id)
        // ...then the item's own default still wins on item pick.
        ->set('lines.0.item_id', $item->id)
        ->assertSet('lines.0.tax_code_id', $other->id);
});

it('keeps contact-default fill of blank lines without clobbering account-filled ones', function () {
    $other = cascadeSecondTaxCode();
    $expense = cascadeAccount(AccountSubtype::Expense);
    $expense->update(['default_tax_code_id' => $this->gst->id]);

    $vendor = Contact::factory()->vendor()->create(['default_tax_code_id' => $other->id]);

    Livewire::test('pages::bills.form', ['company' => $this->company])
        // Line 0 gets the account default; line 1 stays blank.
        ->set('lines.0.account_id', $expense->id)
        ->call('addLine')
        ->call('selectContact', $vendor->id)
        ->assertSet('lines.0.tax_code_id', $this->gst->id)
        ->assertSet('lines.1.tax_code_id', $other->id);
});

it('persists journal line tax codes from the form and reloads them when editing', function () {
    $expense = cascadeAccount(AccountSubtype::Expense);
    $bank = cascadeAccount(AccountSubtype::Bank);
    $expense->update(['default_tax_code_id' => $this->gst->id]);

    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('lines.0.account_id', $expense->id)
        ->set('lines.0.debit', '50.00')
        ->set('lines.1.account_id', $bank->id)
        ->set('lines.1.credit', '50.00')
        ->call('saveDraft')
        ->assertHasNoErrors();

    $entry = JournalEntry::firstOrFail();
    $lines = $entry->lines()->orderBy('line_order')->get();

    expect($lines[0]->tax_code_id)->toBe($this->gst->id)
        ->and($lines[1]->tax_code_id)->toBeNull();

    Livewire::test('pages::journal.form', ['company' => $this->company, 'entry' => $entry])
        ->assertSet('lines.0.tax_code_id', $this->gst->id)
        ->assertSet('lines.1.tax_code_id', null);
});

it('rejects another company\'s tax code on a journal form line', function () {
    app()->forgetInstance('current_company');
    $other = Company::factory()->create();
    $foreign = TaxCode::withoutGlobalScopes()->where('company_id', $other->id)->firstOrFail();
    app()->instance('current_company', $this->company);

    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('lines.0.account_id', cascadeAccount(AccountSubtype::Expense)->id)
        ->set('lines.0.debit', '50.00')
        ->set('lines.0.tax_code_id', $foreign->id)
        ->set('lines.1.account_id', cascadeAccount(AccountSubtype::Bank)->id)
        ->set('lines.1.credit', '50.00')
        ->call('saveDraft')
        ->assertHasErrors('lines.0.tax_code_id');
});

it('round-trips a line tax code through SaveJournalEntry on create and edit', function () {
    $other = cascadeSecondTaxCode();
    $expense = cascadeAccount(AccountSubtype::Expense);
    $bank = cascadeAccount(AccountSubtype::Bank);

    $payload = fn (?int $taxCodeId): array => [
        'entry_no' => 'JE-TAX-1',
        'entry_date' => '2026-05-20',
        'memo' => null,
        'lines' => [
            ['account_id' => $expense->id, 'debit_cents' => 5000, 'credit_cents' => 0, 'tax_code_id' => $taxCodeId],
            ['account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => 5000],
        ],
    ];

    $entry = app(SaveJournalEntry::class)->handle($payload($this->gst->id));
    $lines = $entry->lines()->orderBy('line_order')->get();

    expect($lines[0]->tax_code_id)->toBe($this->gst->id)
        ->and($lines[1]->tax_code_id)->toBeNull();

    // Editing rebuilds the lines with the new tag.
    $entry = app(SaveJournalEntry::class)->handle($payload($other->id), $entry);

    expect($entry->lines()->orderBy('line_order')->first()->tax_code_id)->toBe($other->id);
});

it('accepts a line tax code on the journal entry API and persists it', function () {
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $h = ['Authorization' => "Bearer {$plain}"];

    $expense = cascadeAccount(AccountSubtype::Expense);
    $bank = cascadeAccount(AccountSubtype::Bank);

    $id = $this->postJson('/api/v1/journal-entries', [
        'entry_date' => '2026-05-20',
        'memo' => 'Tagged entry',
        'lines' => [
            ['account_id' => $expense->id, 'debit_cents' => 5000, 'credit_cents' => 0, 'tax_code_id' => $this->gst->id],
            ['account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => 5000],
        ],
    ], $h)->assertStatus(201)->json('data.id');

    $lines = JournalEntry::withoutGlobalScopes()->findOrFail($id)->lines()->orderBy('line_order')->get();

    expect($lines[0]->tax_code_id)->toBe($this->gst->id)
        ->and($lines[1]->tax_code_id)->toBeNull();
});

it('rejects another company\'s tax code over the journal entry API with 422', function () {
    app()->forgetInstance('current_company');
    $other = Company::factory()->create();
    $foreign = TaxCode::withoutGlobalScopes()->where('company_id', $other->id)->firstOrFail();
    app()->instance('current_company', $this->company);

    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $h = ['Authorization' => "Bearer {$plain}"];

    $this->postJson('/api/v1/journal-entries', [
        'entry_date' => '2026-05-20',
        'lines' => [
            ['account_id' => cascadeAccount(AccountSubtype::Expense)->id, 'debit_cents' => 5000, 'credit_cents' => 0, 'tax_code_id' => $foreign->id],
            ['account_id' => cascadeAccount(AccountSubtype::Bank)->id, 'debit_cents' => 0, 'credit_cents' => 5000],
        ],
    ], $h)
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines.0.tax_code_id');
});

it('shows the journal show page tax code column only when a line carries one', function () {
    $expense = cascadeAccount(AccountSubtype::Expense);
    $bank = cascadeAccount(AccountSubtype::Bank);

    $tagged = app(SaveJournalEntry::class)->handle([
        'entry_no' => 'JE-TAG-1',
        'entry_date' => '2026-05-20',
        'memo' => null,
        'lines' => [
            ['account_id' => $expense->id, 'debit_cents' => 5000, 'credit_cents' => 0, 'tax_code_id' => $this->gst->id],
            ['account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => 5000],
        ],
    ]);

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $tagged])
        ->assertSeeHtml('data-test="line-tax-code"')
        ->assertSee('Tax code');

    $untagged = app(SaveJournalEntry::class)->handle([
        'entry_no' => 'JE-TAG-2',
        'entry_date' => '2026-05-20',
        'memo' => null,
        'lines' => [
            ['account_id' => $expense->id, 'debit_cents' => 5000, 'credit_cents' => 0],
            ['account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => 5000],
        ],
    ]);

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $untagged])
        ->assertDontSeeHtml('data-test="line-tax-code"');
});
