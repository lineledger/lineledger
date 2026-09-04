<?php

use App\Actions\Banking\SaveCheque;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * @return array<string, mixed>
 */
function payeeComboChequeLine(int $accountId): array
{
    return [
        'account_id' => $accountId,
        'description' => 'Prize',
        'amount' => '50.00',
        'tax_code_id' => null,
        'tax_override' => '',
        'class_id' => null,
        'location_id' => null,
        'auto_tax_cents' => 0,
        'tax_cents' => 0,
        'total' => 0,
    ];
}

/**
 * A Custom member whose only section is Banking: may write cheques, cannot open
 * the Vendors / Customers / Employees pages.
 */
function payeeComboBankingOnlyMember(Company $company): User
{
    $user = User::factory()->create();

    $company->memberships()->create([
        'user_id' => $user->id,
        'role' => CompanyRole::Custom,
        'sections' => [Section::Banking->value],
    ]);

    return $user;
}

it('offers every active payable role and nothing else', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Alpha Vendor']);
    $customer = Contact::factory()->customer()->create(['display_name' => 'Alpha Customer']);
    $employee = Contact::factory()->create(['display_name' => 'Alpha Employee', 'is_employee' => true]);
    $other = Contact::factory()->otherName()->create(['display_name' => 'Alpha Other']);
    $inactive = Contact::factory()->vendor()->create(['display_name' => 'Alpha Inactive', 'is_active' => false]);
    $donorOnly = Contact::factory()->donor()->create(['display_name' => 'Alpha Donor']);

    $foreignCompany = Company::factory()->create();
    app()->instance('current_company', $foreignCompany);
    $foreign = Contact::factory()->vendor()->create(['display_name' => 'Alpha Foreign']);
    app()->instance('current_company', $this->company);

    expect($foreign->company_id)->toBe($foreignCompany->id);

    $ids = Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->get('payeeOptions')
        ->pluck('id')
        ->all();

    expect($ids)->toContain($vendor->id, $customer->id, $employee->id, $other->id)
        ->and($ids)->not->toContain($inactive->id, $donorOnly->id, $foreign->id);
});

it('filters the options by the typed query and caps them at 50', function () {
    Contact::factory()->vendor()->create(['display_name' => 'Alpha Supply']);
    Contact::factory()->customer()->create(['display_name' => 'Beta Holdings']);

    foreach (range(1, 55) as $i) {
        Contact::factory()->vendor()->create(['display_name' => sprintf('Cap %02d', $i)]);
    }

    $component = Livewire::test('pages::cheques.form', ['company' => $this->company]);

    $filtered = $component->set('payee_query', 'alpha')->get('payeeOptions')->pluck('display_name')->all();

    expect($filtered)->toBe(['Alpha Supply']);

    expect($component->set('payee_query', 'Cap')->get('payeeOptions'))->toHaveCount(50);
});

it('renders a badge for every held role in the search panel', function () {
    Contact::factory()->otherName()->create(['display_name' => 'Alpha Raffle']);
    Contact::factory()->vendor()->customer()->create(['display_name' => 'Alpha Both']);

    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('payee_query', 'Alpha')
        ->assertSeeHtml('data-test="cheque-payee-combo-role"')
        ->assertSee('Other name')
        ->assertSee('Vendor')
        ->assertSee('Customer');
});

it('selects a payee, fills the name and defaults the memo from the account number', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Acme Supply', 'account_no' => 'ACCT-7788']);

    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('payee_query', 'Acme')
        ->call('selectPayee', $vendor->id)
        ->assertSet('payee_contact_id', $vendor->id)
        ->assertSet('payee_name', 'Acme Supply')
        ->assertSet('memo', 'ACCT-7788')
        ->assertSet('payee_query', '')
        ->assertSet('payee_creating', false)
        ->assertSeeHtml('data-test="cheque-payee-combo-selected"')
        ->assertSee('Vendor');
});

it('does not clobber a memo the user already typed when selecting', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Acme Supply', 'account_no' => 'ACCT-7788']);

    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('memo', 'Office chairs')
        ->call('selectPayee', $vendor->id)
        ->assertSet('memo', 'Office chairs');
});

it('ignores an inactive or unknown id passed to selectPayee', function () {
    $inactive = Contact::factory()->vendor()->create(['display_name' => 'Gone Ltd', 'is_active' => false]);

    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->call('selectPayee', $inactive->id)
        ->assertSet('payee_contact_id', null)
        ->assertSet('payee_name', '')
        ->call('selectPayee', 999999)
        ->assertSet('payee_contact_id', null);
});

it('flips between the selected and search states as the payee is picked and cleared', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Acme Supply']);

    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->assertSeeHtml('data-test="cheque-payee-combo-search"')
        ->assertDontSeeHtml('data-test="cheque-payee-combo-selected"')
        ->call('selectPayee', $vendor->id)
        ->assertSeeHtml('data-test="cheque-payee-combo-selected"')
        ->assertSeeHtml('data-test="cheque-payee-combo-clear"')
        ->assertDontSeeHtml('data-test="cheque-payee-combo-search"')
        ->call('clearPayee')
        ->assertSet('payee_contact_id', null)
        ->assertSet('payee_name', '')
        ->assertSeeHtml('data-test="cheque-payee-combo-search"')
        ->assertDontSeeHtml('data-test="cheque-payee-combo-selected"');
});

it('quick-adds an Other name, selects it, and stamps it on every GL leg when posted', function () {
    $component = Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('payee_query', 'Raffle winner')
        ->assertSeeHtml('data-test="cheque-payee-combo-add-other-name"')
        ->call('startNewOtherName')
        ->assertSet('payee_creating', true)
        ->assertSet('new_payee_name', 'Raffle winner')
        ->assertSet('payee_query', '')
        ->assertSet('payee_contact_id', null)
        ->assertSeeHtml('data-test="cheque-payee-combo-new-name"')
        ->assertSeeHtml('data-test="cheque-payee-combo-create"')
        ->call('createOtherName')
        ->assertHasNoErrors();

    $contact = Contact::query()->where('display_name', 'Raffle winner')->firstOrFail();

    expect($contact->is_other_name)->toBeTrue()
        ->and($contact->is_vendor)->toBeFalse()
        ->and($contact->is_customer)->toBeFalse()
        ->and($contact->is_employee)->toBeFalse()
        ->and($contact->is_active)->toBeTrue()
        ->and($contact->company_id)->toBe($this->company->id);

    $component
        ->assertSet('payee_contact_id', $contact->id)
        ->assertSet('payee_name', 'Raffle winner')
        ->assertSet('payee_creating', false)
        ->assertSet('new_payee_name', '')
        ->assertSeeHtml('data-test="cheque-payee-combo-selected"')
        ->assertSee('Other name')
        ->set('lines', [payeeComboChequeLine($this->expense->id)])
        ->call('postCheque')
        ->assertHasNoErrors();

    $cheque = Cheque::firstOrFail();

    expect($cheque->payee_contact_id)->toBe($contact->id)
        ->and($cheque->payee_name)->toBe('Raffle winner')
        ->and($cheque->journal_entry_id)->not->toBeNull();

    $legs = $cheque->journalEntry->lines;

    expect($legs->count())->toBeGreaterThanOrEqual(2)
        ->and($legs->pluck('contact_id')->unique()->all())->toBe([$contact->id]);
});

it('takes the live input value from the Enter key so the debounce cannot race', function () {
    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('payee_query', 'Raffle')
        ->call('startNewOtherName')
        ->call('createOtherName', 'Raffle winner — J. Chen')
        ->assertHasNoErrors()
        ->assertSet('payee_name', 'Raffle winner — J. Chen');

    expect(Contact::query()->where('display_name', 'Raffle winner — J. Chen')->where('is_other_name', true)->exists())->toBeTrue();
});

it('selects the existing contact instead of minting a duplicate Other name', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'acme']);

    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('payee_query', 'Acme')
        // An exact (case-insensitive) match hides the quick-add footer entirely.
        ->assertDontSeeHtml('data-test="cheque-payee-combo-add-other-name"')
        ->call('startNewOtherName')
        ->call('createOtherName', 'Acme')
        ->assertHasNoErrors()
        ->assertSet('payee_contact_id', $vendor->id)
        ->assertSet('payee_name', 'acme')
        ->assertSet('payee_creating', false);

    expect(Contact::query()->count())->toBe(1)
        ->and($vendor->fresh()->is_other_name)->toBeFalse();
});

it('rejects a blank Other name and stays in the creating state', function () {
    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('payee_query', 'Someone')
        ->call('startNewOtherName')
        ->set('new_payee_name', '   ')
        ->call('createOtherName')
        ->assertHasErrors(['new_payee_name' => 'required'])
        ->assertSet('payee_creating', true)
        ->assertSet('payee_contact_id', null);

    expect(Contact::query()->count())->toBe(0);
});

it('offers a create-as link for every directory the owner can reach', function () {
    $company = $this->company;

    $component = Livewire::test('pages::cheques.form', ['company' => $company])
        ->set('payee_query', 'New Supplier')
        ->assertSeeHtml('data-test="cheque-payee-combo-create-vendor"')
        ->assertSeeHtml('data-test="cheque-payee-combo-create-customer"')
        ->assertSeeHtml('data-test="cheque-payee-combo-create-employee"')
        ->assertSeeHtml('target="_blank"')
        ->assertSeeHtml('rel="noopener"');

    $links = collect($component->get('payeeCreateLinks'));

    expect($links->pluck('dataTest')->all())->toBe(['create-vendor', 'create-customer', 'create-employee'])
        ->and($links->pluck('url')->all())->toBe([
            route('vendors.index', ['company' => $company->slug, 'new' => 'New Supplier']),
            route('customers.index', ['company' => $company->slug, 'new' => 'New Supplier']),
            route('employees.index', ['company' => $company->slug, 'new' => 'New Supplier']),
        ]);
});

it('hides the create-as links from a Banking-only member but still lets them add an Other name', function () {
    $member = payeeComboBankingOnlyMember($this->company);
    $this->actingAs($member);

    $component = Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('payee_query', 'Walk-in refund')
        ->assertSeeHtml('data-test="cheque-payee-combo-add-other-name"')
        ->assertDontSeeHtml('cheque-payee-combo-create-vendor')
        ->assertDontSeeHtml('cheque-payee-combo-create-customer')
        ->assertDontSeeHtml('cheque-payee-combo-create-employee');

    expect($component->get('payeeCreateLinks'))->toBe([]);

    $component
        ->call('startNewOtherName')
        ->call('createOtherName')
        ->assertHasNoErrors()
        ->assertSet('payee_name', 'Walk-in refund');

    $created = Contact::query()->where('display_name', 'Walk-in refund')->firstOrFail();

    expect($created->is_other_name)->toBeTrue()
        ->and($created->company_id)->toBe($this->company->id);
});

it('shows a legacy free-text payee as Not linked and lets it be cleared', function () {
    $draft = app(SaveCheque::class)->handle([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '2001',
        'cheque_date' => $this->company->currentDateTime()->toDateString(),
        'payee_contact_id' => null,
        'payee_name' => 'Old Free Text Payee',
        'memo' => null,
        'lines' => [[
            'account_id' => $this->expense->id,
            'description' => 'Legacy',
            'amount_cents' => 1000,
            'tax_code_id' => null,
            'tax_override_cents' => null,
            'class_id' => null,
            'location_id' => null,
        ]],
    ]);

    Livewire::test('pages::cheques.form', ['company' => $this->company, 'cheque' => $draft])
        ->assertSet('payee_contact_id', null)
        ->assertSet('payee_name', 'Old Free Text Payee')
        ->assertSee('Not linked')
        ->assertSeeHtml('data-test="cheque-payee-combo-unlinked"')
        ->assertDontSeeHtml('data-test="cheque-payee-combo-search"')
        // The legacy draft still saves as-is: payee_name alone satisfies validation.
        ->call('saveDraft')
        ->assertHasNoErrors()
        ->call('clearPayee')
        ->assertSet('payee_name', '')
        ->assertSeeHtml('data-test="cheque-payee-combo-search"')
        ->call('saveDraft')
        ->assertHasErrors(['payee_name' => 'Choose a payee, or add the name as an Other name.']);
});

it('refuses a new cheque with no payee chosen, using the picker message', function () {
    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('payee_query', 'Typed but never picked')
        ->set('lines', [payeeComboChequeLine($this->expense->id)])
        ->call('saveDraft')
        ->assertHasErrors(['payee_name' => 'Choose a payee, or add the name as an Other name.']);

    expect(Cheque::query()->count())->toBe(0);
});
