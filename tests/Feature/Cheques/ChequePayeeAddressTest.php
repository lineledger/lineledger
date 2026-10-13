<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Services\Posting\ChequePoster;
use App\Services\Printing\ChequePdfRenderer;
use App\Support\Contacts\AddressLines;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The address a cheque is mailed to. Seeded from the payee's record, editable
 * for this cheque, snapshotted so a reprint shows where the cheque actually
 * went, and offered back to the payee's record when it changes.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA']);
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
 * ContactFactory sets no address fields, so every fixture spells them out.
 */
function addressedVendor(array $overrides = []): Contact
{
    return Contact::factory()->vendor()->create(array_merge([
        'company_id' => test()->company->id,
        'display_name' => 'Gina Pichaloff',
        'email' => 'gina@example.test',
        'phone' => '555-0100',
        'billing_line1' => '12 Old Street',
        'billing_line2' => 'Unit 4',
        'billing_city' => 'Winnipeg',
        'billing_region' => 'MB',
        'billing_postal_code' => 'R3C 1A1',
        'billing_country' => 'CA',
    ], $overrides));
}

function addressChequeForm(): Testable
{
    return Livewire::test('pages::cheques.form', ['company' => test()->company]);
}

function fillChequeBody(Testable $component): Testable
{
    return $component
        ->set('lines.0.account_id', test()->expense->id)
        ->set('lines.0.amount', '100.00');
}

it('fills the address from the payee on selection', function () {
    $vendor = addressedVendor();

    addressChequeForm()
        ->call('selectPayee', $vendor->id)
        ->assertSet('payee_line1', '12 Old Street')
        ->assertSet('payee_line2', 'Unit 4')
        ->assertSet('payee_city', 'Winnipeg')
        ->assertSet('payee_region', 'MB')
        ->assertSet('payee_postal_code', 'R3C 1A1')
        ->assertSet('payee_country', 'CA');
});

it('replaces rather than merges the address when the payee changes', function () {
    $first = addressedVendor();
    $second = addressedVendor([
        'display_name' => 'Second Vendor',
        'billing_line1' => '99 New Road',
        'billing_line2' => null,
        'billing_city' => 'Brandon',
    ]);

    addressChequeForm()
        ->call('selectPayee', $first->id)
        ->call('clearPayee')
        ->call('selectPayee', $second->id)
        ->assertSet('payee_line1', '99 New Road')
        // The first payee's unit number must not survive the switch.
        ->assertSet('payee_line2', '')
        ->assertSet('payee_city', 'Brandon');
});

it('clears the address when the payee is cleared', function () {
    $vendor = addressedVendor();

    addressChequeForm()
        ->call('selectPayee', $vendor->id)
        ->call('clearPayee')
        ->assertSet('payee_line1', '')
        ->assertSet('payee_city', '');
});

it('saves without prompting when the address is untouched', function () {
    $vendor = addressedVendor();

    fillChequeBody(addressChequeForm()->call('selectPayee', $vendor->id))
        ->call('postCheque')
        ->assertHasNoErrors();

    expect(Cheque::query()->firstOrFail()->payee_line1)->toBe('12 Old Street');
});

it('never prompts for a payee with no contact record', function () {
    fillChequeBody(addressChequeForm()->set('payee_name', 'Walk-in payee'))
        ->set('payee_line1', '5 Somewhere Lane')
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect(Cheque::query()->firstOrFail()->payee_line1)->toBe('5 Somewhere Lane');
});

it('writes the edited address back to the payee when asked, touching nothing else', function () {
    $vendor = addressedVendor();

    fillChequeBody(addressChequeForm()->call('selectPayee', $vendor->id))
        ->set('payee_line1', '500 New Avenue')
        // The guard parks the save and opens the modal instead.
        ->call('postCheque')
        ->assertHasNoErrors()
        ->call('confirmAddressWriteBack', true)
        ->assertHasNoErrors();

    $vendor->refresh();

    expect($vendor->billing_line1)->toBe('500 New Avenue')
        ->and($vendor->billing_city)->toBe('Winnipeg')
        // The SaveContact trap: a partial write must not null the rest.
        ->and($vendor->email)->toBe('gina@example.test')
        ->and($vendor->phone)->toBe('555-0100')
        ->and($vendor->is_vendor)->toBeTrue();

    expect(Cheque::query()->firstOrFail()->payee_line1)->toBe('500 New Avenue');
});

it('parks the save until the prompt is answered', function () {
    $vendor = addressedVendor();

    fillChequeBody(addressChequeForm()->call('selectPayee', $vendor->id))
        ->set('payee_line1', '500 New Avenue')
        ->call('postCheque');

    expect(Cheque::query()->count())->toBe(0);
});

it('keeps an edited address on the cheque only when declined', function () {
    $vendor = addressedVendor();

    fillChequeBody(addressChequeForm()->call('selectPayee', $vendor->id))
        ->set('payee_line1', '500 New Avenue')
        ->call('postCheque')
        ->call('confirmAddressWriteBack', false)
        ->assertHasNoErrors();

    expect($vendor->fresh()->billing_line1)->toBe('12 Old Street')
        ->and(Cheque::query()->firstOrFail()->payee_line1)->toBe('500 New Avenue');
});

it('prompts for an Other name like any other contact', function () {
    $other = Contact::factory()->create([
        'company_id' => $this->company->id,
        'display_name' => 'One-off payee',
        'is_customer' => false,
        'is_vendor' => false,
        'is_other_name' => true,
        'billing_line1' => '1 First Street',
    ]);

    fillChequeBody(addressChequeForm()->call('selectPayee', $other->id))
        ->set('payee_line1', '2 Second Street')
        ->call('postCheque')
        ->call('confirmAddressWriteBack', true)
        ->assertHasNoErrors();

    expect($other->fresh()->billing_line1)->toBe('2 Second Street');
});

it('freezes the cheque address against a later change to the contact', function () {
    $vendor = addressedVendor();

    fillChequeBody(addressChequeForm()->call('selectPayee', $vendor->id))
        ->call('postCheque')
        ->assertHasNoErrors();

    $vendor->update(['billing_line1' => 'Moved Since']);

    $cheque = Cheque::query()->firstOrFail();

    expect($cheque->payee_line1)->toBe('12 Old Street')
        ->and(app(ChequePdfRenderer::class)->dataFor($cheque)['address_lines'])
        ->toContain('12 Old Street');
});

it('prints the address lines, suppressing a domestic country', function () {
    $vendor = addressedVendor();

    fillChequeBody(addressChequeForm()->call('selectPayee', $vendor->id))
        ->call('postCheque')
        ->assertHasNoErrors();

    $lines = app(ChequePdfRenderer::class)->dataFor(Cheque::query()->firstOrFail())['address_lines'];

    expect($lines)->toBe(['12 Old Street', 'Unit 4', 'Winnipeg, MB  R3C 1A1']);
});

it('prints a foreign country as its name', function () {
    $vendor = addressedVendor([
        'billing_line2' => null,
        'billing_city' => 'Fargo',
        'billing_region' => 'ND',
        'billing_postal_code' => '58102',
        'billing_country' => 'US',
    ]);

    fillChequeBody(addressChequeForm()->call('selectPayee', $vendor->id))
        ->call('postCheque')
        ->assertHasNoErrors();

    $lines = app(ChequePdfRenderer::class)->dataFor(Cheque::query()->firstOrFail())['address_lines'];

    expect($lines)->toBe(['12 Old Street', 'Fargo, ND  58102', 'United States']);
});

it('falls back to the contact address for a cheque written before the snapshot', function () {
    $vendor = addressedVendor();

    // A cheque from before the address columns existed: payee linked, snapshot null.
    $cheque = Cheque::create([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '9001',
        'cheque_date' => '2026-05-20',
        'payee_contact_id' => $vendor->id,
        'payee_name' => $vendor->display_name,
    ]);

    $cheque->lines()->create([
        'account_id' => $this->expense->id,
        'amount_cents' => 1000,
        'line_order' => 0,
    ]);

    app(ChequePoster::class)->post($cheque);

    expect(app(ChequePdfRenderer::class)->dataFor($cheque->fresh())['address_lines'])
        ->toBe(['12 Old Street', 'Unit 4', 'Winnipeg, MB  R3C 1A1']);
});

it('prints no address for a free-text payee with no contact', function () {
    $cheque = Cheque::create([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '9002',
        'cheque_date' => '2026-05-20',
        'payee_name' => 'Jane Doe',
    ]);

    $cheque->lines()->create([
        'account_id' => $this->expense->id,
        'amount_cents' => 1000,
        'line_order' => 0,
    ]);

    app(ChequePoster::class)->post($cheque);

    expect(app(ChequePdfRenderer::class)->dataFor($cheque->fresh())['address_lines'])->toBe([]);
});

it('carries the address onto a duplicated cheque', function () {
    $vendor = addressedVendor();

    fillChequeBody(addressChequeForm()->call('selectPayee', $vendor->id))
        ->call('postCheque')
        ->assertHasNoErrors();

    $source = Cheque::query()->firstOrFail();

    Livewire::withQueryParams(['from' => $source->id])
        ->test('pages::cheques.form', ['company' => $this->company])
        ->assertSet('payee_line1', '12 Old Street')
        ->assertSet('payee_city', 'Winnipeg');
});

/*
 * The Address block folds to a one-line summary with a Show / Hide toggle, so
 * the fields only take room when the operator opens them to change something.
 */

/**
 * The text of the folded block's summary line, read off the rendered form.
 */
function chequeAddressSummary(string $html): ?string
{
    return preg_match('/data-test="cheque-address-summary"[^>]*>(.*?)</s', $html, $match)
        ? trim(html_entity_decode($match[1], ENT_QUOTES))
        : null;
}

it('folds the address fields behind a toggle on the full page', function () {
    $html = $this->get(route('cheques.create', ['company' => $this->company->slug]))
        ->assertOk()
        ->getContent();

    // A Blade directive inside a <flux:*> tag leaves it as literal text.
    expect($html)->not->toContain('<flux:');

    expect($html)
        ->toContain('data-test="cheque-address-toggle"')
        ->toContain('x-data="{ open: false }"')
        ->toContain('x-show="open" x-collapse x-cloak')
        ->toContain('aria-controls="cheque-payee-address-fields"')
        ->and(chequeAddressSummary($html))->toBe('No address');

    // Folded, not removed: every field is still on the page and bound.
    foreach (['line1', 'line2', 'city', 'region', 'postal_code', 'country'] as $part) {
        expect($html)->toContain('wire:model.blur="payee_'.$part.'"');
    }

    expect($html)
        ->toContain('data-test="cheque-payee-line1"')
        ->toContain('data-test="cheque-payee-country"')
        // Nothing has failed validation, so nothing forces it open.
        ->not->toContain('data-test="cheque-address-force-open"');
});

it('summarises the payee address once a payee is picked', function () {
    $vendor = addressedVendor();

    $component = addressChequeForm()->call('selectPayee', $vendor->id);

    // The domestic country is implied, exactly as on the printed cheque.
    expect(chequeAddressSummary($component->html()))->toBe('12 Old Street, Unit 4, Winnipeg, MB, R3C 1A1');
});

it('names a foreign country in the summary', function () {
    $vendor = addressedVendor([
        'billing_line2' => null,
        'billing_city' => 'Fargo',
        'billing_region' => 'ND',
        'billing_postal_code' => '58102',
        'billing_country' => 'US',
    ]);

    $component = addressChequeForm()->call('selectPayee', $vendor->id);

    expect(chequeAddressSummary($component->html()))->toBe('12 Old Street, Fargo, ND, 58102, United States');
});

it('keeps the summary in step with an edited field', function () {
    $vendor = addressedVendor();

    $component = addressChequeForm()
        ->call('selectPayee', $vendor->id)
        ->set('payee_line1', '500 New Avenue')
        ->set('payee_line2', '');

    expect(chequeAddressSummary($component->html()))->toBe('500 New Avenue, Winnipeg, MB, R3C 1A1');
});

it('reads No address for a payee with none and after the payee is cleared', function () {
    $bare = addressedVendor([
        'display_name' => 'No Fixed Address',
        'billing_line1' => null,
        'billing_line2' => null,
        'billing_city' => null,
        'billing_region' => null,
        'billing_postal_code' => null,
        'billing_country' => null,
    ]);

    expect(chequeAddressSummary(addressChequeForm()->call('selectPayee', $bare->id)->html()))->toBe('No address');

    $vendor = addressedVendor();

    $cleared = addressChequeForm()
        ->call('selectPayee', $vendor->id)
        ->call('clearPayee');

    expect(chequeAddressSummary($cleared->html()))->toBe('No address');
});

it('opens folded on edit, summarising the address the cheque stored', function () {
    $vendor = addressedVendor();

    fillChequeBody(addressChequeForm()->call('selectPayee', $vendor->id))
        ->set('payee_line1', '500 New Avenue')
        ->call('postCheque')
        ->call('confirmAddressWriteBack', false)
        ->assertHasNoErrors();

    $cheque = Cheque::query()->firstOrFail();

    $html = Livewire::test('pages::cheques.form', ['company' => $this->company, 'cheque' => $cheque])->html();

    expect(chequeAddressSummary($html))->toBe('500 New Avenue, Unit 4, Winnipeg, MB, R3C 1A1')
        ->and($html)->toContain('x-data="{ open: false }"');
});

it('forces the block open when an address field fails validation', function () {
    fillChequeBody(addressChequeForm()->set('payee_name', 'Walk-in payee'))
        ->set('payee_country', 'CAN')
        ->call('saveDraft')
        ->assertHasErrors('payee_country')
        ->assertSeeHtml('data-test="cheque-address-force-open"')
        ->assertSeeHtml('x-init="open = true"');

    expect(Cheque::query()->count())->toBe(0);
});

it('leaves the block folded when only another field fails validation', function () {
    fillChequeBody(addressChequeForm())
        ->call('saveDraft')
        ->assertHasErrors('payee_name')
        ->assertDontSeeHtml('data-test="cheque-address-force-open"');
});

it('formats an address on one line, dropping blank parts', function () {
    expect(AddressLines::oneLine([
        'line1' => ' 116-618 East Kent Avenue South ',
        'line2' => '',
        'city' => 'Vancouver',
        'region' => 'BC',
        'postal_code' => 'V5X 0B1',
        'country' => 'ca',
    ], $this->company))->toBe('116-618 East Kent Avenue South, Vancouver, BC, V5X 0B1')
        ->and(AddressLines::oneLine(['line1' => '', 'city' => null]))->toBe('');
});
