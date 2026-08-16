<?php

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Actions\Contacts\SaveContact;
use App\Models\Company;
use App\Models\Invoice;

beforeEach(function () {
    $this->company = Company::factory()->create(['currency_code' => 'CAD']);
    app()->instance('current_company', $this->company);
    app(EnableCompanyCurrency::class)->handle($this->company, 'USD');
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('stores a foreign currency on a new contact', function () {
    $contact = app(SaveContact::class)->handle(
        ['display_name' => 'US Co', 'currency_code' => 'USD'],
        'is_customer',
    );

    expect($contact->currency_code)->toBe('USD');
});

it('normalizes the home currency to null', function () {
    $contact = app(SaveContact::class)->handle(
        ['display_name' => 'Local Co', 'currency_code' => 'CAD'],
        'is_customer',
    );

    expect($contact->currency_code)->toBeNull();
});

it('locks the currency once the contact has transactions', function () {
    $contact = app(SaveContact::class)->handle(
        ['display_name' => 'US Co', 'currency_code' => 'USD'],
        'is_customer',
    );

    Invoice::create([
        'contact_id' => $contact->id,
        'invoice_no' => 'INV-1',
        'invoice_date' => '2026-03-01',
        'due_date' => '2026-03-31',
        'currency_code' => 'USD',
    ]);

    expect($contact->fresh()->canChangeCurrency())->toBeFalse();

    app(SaveContact::class)->handle(
        ['display_name' => 'US Co', 'currency_code' => 'EUR'],
        'is_customer',
        $contact->fresh(),
    );

    expect($contact->fresh()->currency_code)->toBe('USD'); // unchanged
});
