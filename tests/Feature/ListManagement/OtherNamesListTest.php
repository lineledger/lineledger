<?php

use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Models\Account;
use App\Models\AccountingAuditLog;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Support\Contacts\ContactLinkResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Settings → Lists → Other names: the role-filtered view of the contacts
 * table for QuickBooks-style one-time payees, with the one-way Convert.
 */
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

/**
 * A Custom-role member of the company limited to the given sections.
 */
function otherNamesListMember(Company $company, array $sections): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company->memberships()->create([
        'user_id' => $user->id,
        'role' => CompanyRole::Custom,
        'sections' => $sections,
    ]);

    return $user;
}

function otherNamesListPage(Company $company): Testable
{
    return Livewire::test('pages::settings.lists.other-names', ['company' => $company]);
}

function otherNameUpdatedAuditRows(Contact $contact): int
{
    return AccountingAuditLog::query()
        ->withoutGlobalScopes()
        ->where('company_id', $contact->company_id)
        ->where('action', AuditAction::ContactUpdated)
        ->where('auditable_id', $contact->id)
        ->count();
}

/**
 * An other name owned by a different company — created while that company is
 * bound, because the BelongsToCompany creating guard forces company_id.
 */
function otherNamesForeignContact(Company $home, string $name): Contact
{
    $foreignCompany = Company::factory()->create();
    app()->instance('current_company', $foreignCompany);
    $contact = Contact::factory()->otherName()->create(['display_name' => $name]);
    app()->instance('current_company', $home);

    return $contact;
}

it('renders over HTTP under Settings → Lists', function () {
    Contact::factory()->otherName()->create(['display_name' => 'Raffle winner — J. Chen']);

    $this->get(route('lists.other-names', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('Other names')
        ->assertSee('QuickBooks calls these Other Names')
        ->assertSee('Raffle winner — J. Chen')
        ->assertSee('data-test="new-other-name-button"', false);
});

it('lists only contacts flagged as other names, in name order', function () {
    Contact::factory()->otherName()->create(['display_name' => 'Walk-in refund']);
    Contact::factory()->otherName()->create(['display_name' => 'Old payee', 'is_active' => false]);
    Contact::factory()->vendor()->create(['display_name' => 'Hydro Supplies']);
    Contact::factory()->customer()->create(['display_name' => 'Acme Industries']);
    Contact::factory()->create(['display_name' => 'Flagless Contact']);
    otherNamesForeignContact($this->company, 'Foreign payee');

    $component = otherNamesListPage($this->company);

    expect($component->instance()->otherNames->pluck('display_name')->all())
        ->toBe(['Old payee', 'Walk-in refund']);

    $component
        ->assertSeeHtml('data-test="other-name-row"')
        ->assertSee('Walk-in refund')
        ->assertSee('Old payee')
        ->assertSee('Inactive')
        ->assertDontSee('Hydro Supplies')
        ->assertDontSee('Acme Industries')
        ->assertDontSee('Flagless Contact')
        ->assertDontSee('Foreign payee');
});

it('shows the empty state when there are no other names', function () {
    otherNamesListPage($this->company)
        ->assertDontSeeHtml('data-test="other-name-row"')
        ->assertSee('No other names yet.');
});

it('creates an other name carrying only that role flag', function () {
    otherNamesListPage($this->company)
        ->call('openCreate')
        ->set('f_display_name', '  Raffle winner — J. Chen ')
        ->set('f_notes', 'Spring gala 2026')
        ->call('save')
        ->assertHasNoErrors();

    $contact = Contact::query()->where('display_name', 'Raffle winner — J. Chen')->firstOrFail();

    expect($contact->is_other_name)->toBeTrue()
        ->and($contact->is_customer)->toBeFalse()
        ->and($contact->is_vendor)->toBeFalse()
        ->and($contact->is_employee)->toBeFalse()
        ->and($contact->is_donor)->toBeFalse()
        ->and($contact->is_active)->toBeTrue()
        ->and($contact->notes)->toBe('Spring gala 2026')
        ->and($contact->company_id)->toBe($this->company->id);
});

it('requires a name', function () {
    otherNamesListPage($this->company)
        ->call('openCreate')
        ->set('f_display_name', '')
        ->call('save')
        ->assertHasErrors(['f_display_name' => 'required']);

    expect(Contact::query()->otherNames()->count())->toBe(0);
});

it('warns about a case-insensitive duplicate of an active contact of any role', function () {
    Contact::factory()->vendor()->create(['display_name' => 'Hydro Supplies']);

    otherNamesListPage($this->company)
        ->call('openCreate')
        ->set('f_display_name', 'hydro SUPPLIES')
        ->assertSeeHtml('data-test="duplicate-name-warning"')
        ->assertSee(__('Another contact already uses this name.'));
});

it('does not warn about the other name being edited', function () {
    $other = Contact::factory()->otherName()->create(['display_name' => 'Walk-in refund']);

    otherNamesListPage($this->company)
        ->call('openEdit', $other->id)
        ->assertSet('editingId', $other->id)
        ->assertSet('f_display_name', 'Walk-in refund')
        ->assertDontSeeHtml('data-test="duplicate-name-warning"');
});

it('renames an other name without touching its other profile fields', function () {
    $other = Contact::factory()->otherName()->create([
        'display_name' => 'J Chen',
        'company_name' => 'Chen Holdings',
        'email' => 'j.chen@example.com',
        'notes' => 'old note',
    ]);

    otherNamesListPage($this->company)
        ->call('openEdit', $other->id)
        ->assertSet('f_notes', 'old note')
        ->assertSet('f_is_active', true)
        ->set('f_display_name', 'Jane Chen')
        ->set('f_notes', '')
        ->set('f_is_active', false)
        ->call('save')
        ->assertHasNoErrors();

    $other->refresh();

    expect($other->display_name)->toBe('Jane Chen')
        ->and($other->notes)->toBeNull()
        ->and($other->is_active)->toBeFalse()
        ->and($other->email)->toBe('j.chen@example.com')
        ->and($other->company_name)->toBe('Chen Holdings')
        ->and($other->is_other_name)->toBeTrue()
        ->and($other->is_vendor)->toBeFalse();
});

it('refuses to open a contact that is not an other name in the editor', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Hydro Supplies']);

    expect(fn () => otherNamesListPage($this->company)->call('openEdit', $vendor->id))
        ->toThrow(ModelNotFoundException::class);
});

it('converts an other name to a vendor, keeping its id on cheques and writing an audit row', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $other = Contact::factory()->otherName()->create(['display_name' => 'Acme (one-off)']);

    $cheque = Cheque::create([
        'bank_account_id' => $bank->id,
        'cheque_no' => '3001',
        'cheque_date' => now()->toDateString(),
        'payee_contact_id' => $other->id,
        'payee_name' => 'Acme (one-off)',
    ]);

    $auditBefore = otherNameUpdatedAuditRows($other);

    otherNamesListPage($this->company)
        ->assertSeeHtml('data-test="other-name-row"')
        ->call('convert', $other->id, 'is_vendor')
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-test="other-name-row"');

    $other->refresh();

    expect($other->is_vendor)->toBeTrue()
        ->and($other->is_other_name)->toBeFalse()
        ->and($other->is_customer)->toBeFalse()
        ->and($other->is_employee)->toBeFalse()
        ->and($cheque->fresh()->payee_contact_id)->toBe($other->id)
        ->and(otherNameUpdatedAuditRows($other))->toBe($auditBefore + 1);

    Livewire::test('pages::vendors.index', ['company' => $this->company])
        ->assertSee('Acme (one-off)');
});

it('converts an other name to a customer or an employee', function (string $role, string $flag) {
    $other = Contact::factory()->otherName()->create(['display_name' => 'Walk-in refund']);

    otherNamesListPage($this->company)
        ->call('convert', $other->id, $role)
        ->assertHasNoErrors();

    $other->refresh();

    expect($other->{$flag})->toBeTrue()
        ->and($other->is_other_name)->toBeFalse();
})->with([
    'customer' => ['is_customer', 'is_customer'],
    'employee' => ['is_employee', 'is_employee'],
]);

it('rejects an unknown conversion role with a field error', function () {
    $other = Contact::factory()->otherName()->create(['display_name' => 'Walk-in refund']);

    otherNamesListPage($this->company)
        ->call('convert', $other->id, 'is_active')
        ->assertHasErrors('convert')
        ->assertSeeHtml('data-test="other-name-convert-error"')
        ->assertSee(__('Choose vendor, customer or employee.'));

    expect($other->fresh()->is_other_name)->toBeTrue();
});

it('refuses to convert a contact that is not an other name', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Hydro Supplies']);

    otherNamesListPage($this->company)
        ->call('convert', $vendor->id, 'is_customer')
        ->assertHasErrors('convert');

    expect($vendor->fresh()->is_customer)->toBeFalse()
        ->and($vendor->fresh()->is_vendor)->toBeTrue();
});

it('404s when converting another company\'s other name', function () {
    $foreign = otherNamesForeignContact($this->company, 'Foreign payee');

    expect(fn () => otherNamesListPage($this->company)->call('convert', $foreign->id, 'is_vendor'))
        ->toThrow(ModelNotFoundException::class);

    expect($foreign->fresh()->is_other_name)->toBeTrue()
        ->and($foreign->fresh()->is_vendor)->toBeFalse();
});

it('links each row to the all-time Transactions report for the owner', function () {
    $other = Contact::factory()->otherName()->create(['display_name' => 'Walk-in refund']);

    $expected = app(ContactLinkResolver::class)->transactionsUrl($other, $this->company);

    expect($expected)
        ->toContain('contact='.$other->id)
        ->toContain('range=all')
        ->toContain('start=1970-01-01')
        ->toContain('end='.$this->company->currentDateTime()->toDateString());

    otherNamesListPage($this->company)
        ->assertSeeHtml('data-test="other-name-transactions"')
        ->assertSeeHtml('href="'.e($expected).'"');
});

it('shows the owner every conversion', function () {
    Contact::factory()->otherName()->create(['display_name' => 'Walk-in refund']);

    otherNamesListPage($this->company)
        ->assertSeeHtml('data-test="other-name-convert-vendor"')
        ->assertSeeHtml('data-test="other-name-convert-customer"')
        ->assertSeeHtml('data-test="other-name-convert-employee"');
});

it('hides the Transactions link and every conversion from a Lists-only member', function () {
    Contact::factory()->otherName()->create(['display_name' => 'Walk-in refund']);

    $this->actingAs(otherNamesListMember($this->company, [Section::Lists->value]));

    otherNamesListPage($this->company)
        ->assertSee('Walk-in refund')
        ->assertDontSeeHtml('data-test="other-name-transactions"')
        ->assertDontSeeHtml('data-test="other-name-actions-button"')
        ->assertDontSeeHtml('data-test="other-name-convert-vendor"')
        ->assertDontSeeHtml('data-test="other-name-convert-customer"')
        ->assertDontSeeHtml('data-test="other-name-convert-employee"');
});

it('shows the Transactions link to a member who can open reports', function () {
    Contact::factory()->otherName()->create(['display_name' => 'Walk-in refund']);

    $this->actingAs(otherNamesListMember($this->company, [Section::Lists->value, Section::Reports->value]));

    otherNamesListPage($this->company)
        ->assertSeeHtml('data-test="other-name-transactions"');
});

it('offers only the conversions whose section the viewer can reach', function () {
    Contact::factory()->otherName()->create(['display_name' => 'Walk-in refund']);

    $this->actingAs(otherNamesListMember($this->company, [Section::Lists->value, Section::Vendors->value]));

    otherNamesListPage($this->company)
        ->assertSeeHtml('data-test="other-name-convert-vendor"')
        ->assertDontSeeHtml('data-test="other-name-convert-customer"')
        ->assertDontSeeHtml('data-test="other-name-convert-employee"');
});

it('refuses a conversion into a section the viewer cannot reach', function () {
    $other = Contact::factory()->otherName()->create(['display_name' => 'Walk-in refund']);

    $this->actingAs(otherNamesListMember($this->company, [Section::Lists->value]));

    otherNamesListPage($this->company)
        ->call('convert', $other->id, 'is_vendor')
        ->assertHasErrors('convert');

    $other->refresh();

    expect($other->is_vendor)->toBeFalse()
        ->and($other->is_other_name)->toBeTrue();
});
