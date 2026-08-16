<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
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

it('stores the customer business / tax number', function () {
    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_display_name', 'Acme Ltd')
        ->set('f_tax_number', '123456789 RT0001')
        ->call('save')
        ->assertHasNoErrors();

    expect(Contact::query()->where('display_name', 'Acme Ltd')->value('tax_number'))
        ->toBe('123456789 RT0001');
});

it('posts a customer opening balance as an opening invoice (DR AR / CR Opening Balance Equity)', function () {
    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_display_name', 'Opening Co')
        ->set('f_opening_balance', '1250.00')
        ->set('f_opening_balance_date', '2026-06-01')
        ->call('save')
        ->assertHasNoErrors();

    $customer = Contact::query()->where('display_name', 'Opening Co')->firstOrFail();

    $invoice = Invoice::query()
        ->where('contact_id', $customer->id)
        ->where('is_opening_balance', true)
        ->firstOrFail();

    expect($invoice->total_cents)->toBe(125000)
        ->and($invoice->journal_entry_id)->not->toBeNull()
        ->and($customer->recomputeArBalance())->toBe(125000);

    $entry = $invoice->fresh('journalEntry.lines')->journalEntry;
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $obe = Account::query()->where('name', 'Opening Balance Equity')->first();

    expect($entry->isBalanced())->toBeTrue()
        ->and($entry->lines->firstWhere('account_id', $ar->id)->debit_cents)->toBe(125000)
        ->and($entry->lines->firstWhere('account_id', $obe->id)->credit_cents)->toBe(125000);
});

it('creates no opening-balance invoice when the amount is blank', function () {
    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_display_name', 'No OB Co')
        ->call('save')
        ->assertHasNoErrors();

    $customer = Contact::query()->where('display_name', 'No OB Co')->firstOrFail();

    expect(Invoice::query()->where('contact_id', $customer->id)->count())->toBe(0);
});
