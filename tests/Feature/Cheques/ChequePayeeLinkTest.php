<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\User;
use App\Support\Contacts\ContactLinkResolver;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Payee drill-through on the cheque and expense pages
|--------------------------------------------------------------------------
| A linked payee's name is an anchor to its home page (statement, employee
| editor, or the all-time Transactions report for an Other name) — but only
| where the viewer can reach that page's section, and only in the desktop
| table cell: the mobile card is itself an <a>, so nesting would be invalid.
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expenseAccount = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->resolver = app(ContactLinkResolver::class);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * A draft cheque with one line, to a linked payee or (when null) a free-text name.
 */
function payeeLinkCheque(Account $bank, Account $expense, ?Contact $payee, string $name): Cheque
{
    $cheque = Cheque::create([
        'bank_account_id' => $bank->id,
        'cheque_no' => 'CHQ-PL-1',
        'cheque_date' => '2026-04-15',
        'payee_contact_id' => $payee?->id,
        'payee_name' => $name,
    ]);

    $cheque->lines()->create([
        'account_id' => $expense->id,
        'description' => 'Supplies',
        'amount_cents' => 12500,
        'tax_cents' => 0,
        'line_order' => 0,
    ]);

    // create() leaves the DB-default status unhydrated on the in-memory model.
    return $cheque->refresh();
}

/**
 * A draft expense with one line, to a linked payee or (when null) a free-text name.
 */
function payeeLinkExpense(Account $bank, Account $expense, ?Contact $payee, string $name): Expense
{
    $record = Expense::create([
        'payment_account_id' => $bank->id,
        'expense_date' => '2026-04-15',
        'reference' => 'EXP-PL-1',
        'payee_contact_id' => $payee?->id,
        'payee_name' => $name,
    ]);

    $record->lines()->create([
        'account_id' => $expense->id,
        'description' => 'Supplies',
        'amount_cents' => 12500,
        'tax_cents' => 0,
        'line_order' => 0,
    ]);

    return $record->refresh();
}

/**
 * A Custom-role member limited to the given sections.
 *
 * @param  list<string>  $sections
 */
function payeeLinkMember(Company $company, array $sections): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company->memberships()->create([
        'user_id' => $user->id,
        'role' => CompanyRole::Custom,
        'sections' => $sections,
    ]);

    return $user;
}

it('links the payee in the cheque index table for an owner', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Acme Supplies']);
    payeeLinkCheque($this->bank, $this->expenseAccount, $vendor, 'Acme Supplies');
    $url = $this->resolver->urlFor($vendor, $this->company);

    $component = Livewire::test('pages::cheques.index', ['company' => $this->company])
        ->assertSeeHtml('data-test="cheque-payee-link"')
        ->assertSee($url);

    // Desktop cell only — the mobile card is already an anchor.
    expect(substr_count($component->html(), 'data-test="cheque-payee-link"'))->toBe(1)
        ->and($component->instance()->allCheques()->first()['payee_url'])->toBe($url);
});

it('renders the payee as plain text for a Banking-only member who cannot open the statement', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Acme Supplies']);
    payeeLinkCheque($this->bank, $this->expenseAccount, $vendor, 'Acme Supplies');
    $member = payeeLinkMember($this->company, [Section::Banking->value]);

    $component = Livewire::actingAs($member)
        ->test('pages::cheques.index', ['company' => $this->company])
        ->assertSee('Acme Supplies')
        ->assertDontSeeHtml('data-test="cheque-payee-link"');

    expect($component->instance()->allCheques()->first()['payee_url'])->toBeNull();
});

it('renders a free-text-only cheque payee as plain text', function () {
    payeeLinkCheque($this->bank, $this->expenseAccount, null, 'Cash');

    $component = Livewire::test('pages::cheques.index', ['company' => $this->company])
        ->assertSee('Cash')
        ->assertDontSeeHtml('data-test="cheque-payee-link"');

    expect($component->instance()->allCheques()->first()['payee_url'])->toBeNull();

    $cheque = Cheque::firstOrFail();

    Livewire::test('pages::cheques.show', ['company' => $this->company, 'cheque' => $cheque])
        ->assertSee('Cash')
        ->assertDontSeeHtml('data-test="cheque-payee-link"');
});

it('links an other name to its all-time transactions on the index and the cheque page', function () {
    $other = Contact::factory()->otherName()->create(['display_name' => 'Raffle winner']);
    $cheque = payeeLinkCheque($this->bank, $this->expenseAccount, $other, 'Raffle winner');
    $url = $this->resolver->transactionsUrl($other, $this->company);

    expect($url)->toContain('range=all')
        ->and($url)->toContain('start=1970-01-01');

    Livewire::test('pages::cheques.index', ['company' => $this->company])
        ->assertSeeHtml('data-test="cheque-payee-link"')
        ->assertSee($url);

    Livewire::test('pages::cheques.show', ['company' => $this->company, 'cheque' => $cheque])
        ->assertSeeHtml('data-test="cheque-payee-link"')
        ->assertSee($url);
});

it('links the payee on the cheque page for an owner but not for a Banking-only member', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Acme Supplies']);
    $cheque = payeeLinkCheque($this->bank, $this->expenseAccount, $vendor, 'Acme Supplies');

    Livewire::test('pages::cheques.show', ['company' => $this->company, 'cheque' => $cheque])
        ->assertSeeHtml('data-test="cheque-payee-link"')
        ->assertSee($this->resolver->urlFor($vendor, $this->company));

    $member = payeeLinkMember($this->company, [Section::Banking->value]);

    Livewire::actingAs($member)
        ->test('pages::cheques.show', ['company' => $this->company, 'cheque' => $cheque])
        ->assertSee('Acme Supplies')
        ->assertDontSeeHtml('data-test="cheque-payee-link"');
});

it('sends an employee payee to the employee editor', function () {
    $employee = Contact::factory()->create(['display_name' => 'Pat Staff', 'is_employee' => true]);
    payeeLinkCheque($this->bank, $this->expenseAccount, $employee, 'Pat Staff');

    Livewire::test('pages::cheques.index', ['company' => $this->company])
        ->assertSeeHtml('data-test="cheque-payee-link"')
        ->assertSee(route('employees.index', ['company' => $this->company->slug, 'edit' => $employee->id]));
});

it('links the payee in the expense index table and on the expense page', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Acme Supplies']);
    $expense = payeeLinkExpense($this->bank, $this->expenseAccount, $vendor, 'Acme Supplies');
    $url = $this->resolver->urlFor($vendor, $this->company);

    $component = Livewire::test('pages::expenses.index', ['company' => $this->company])
        ->assertSeeHtml('data-test="expense-payee-link"')
        ->assertSee($url);

    // Desktop cell only — the mobile card is already an anchor.
    expect(substr_count($component->html(), 'data-test="expense-payee-link"'))->toBe(1);

    Livewire::test('pages::expenses.show', ['company' => $this->company, 'expense' => $expense])
        ->assertSeeHtml('data-test="expense-payee-link"')
        ->assertSee($url);
});

it('renders expense payees as plain text for a Vendors-only member and for free-text payees', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Acme Supplies']);
    $linked = payeeLinkExpense($this->bank, $this->expenseAccount, $vendor, 'Acme Supplies');
    $member = payeeLinkMember($this->company, [Section::Vendors->value]);

    // The statement lives under Reports, which this member cannot reach.
    Livewire::actingAs($member)
        ->test('pages::expenses.index', ['company' => $this->company])
        ->assertSee('Acme Supplies')
        ->assertDontSeeHtml('data-test="expense-payee-link"');

    Livewire::actingAs($member)
        ->test('pages::expenses.show', ['company' => $this->company, 'expense' => $linked])
        ->assertSee('Acme Supplies')
        ->assertDontSeeHtml('data-test="expense-payee-link"');

    $linked->delete();
    $freeText = payeeLinkExpense($this->bank, $this->expenseAccount, null, 'Corner Store');

    Livewire::test('pages::expenses.index', ['company' => $this->company])
        ->assertSee('Corner Store')
        ->assertDontSeeHtml('data-test="expense-payee-link"');

    Livewire::test('pages::expenses.show', ['company' => $this->company, 'expense' => $freeText])
        ->assertSee('Corner Store')
        ->assertDontSeeHtml('data-test="expense-payee-link"');
});
