<?php

use App\Actions\Sales\SaveInvoice;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->contact = Contact::factory()->customer()->create();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('applies a per-line markup to the line subtotal', function () {
    $invoice = app(SaveInvoice::class)->handle([
        'contact_id' => $this->contact->id,
        'invoice_date' => '2026-06-01',
        'due_date' => '2026-06-30',
        'lines' => [[
            'account_id' => $this->income->id,
            'quantity' => '1',
            'unit_price_cents' => 10000,
            'line_markup_pct' => '10',
        ]],
    ]);

    expect($invoice->subtotal_cents)->toBe(11000)
        ->and($invoice->lines->first()->line_markup_cents)->toBe(1000);
});

it('applies a document discount and posts it to a Sales Discounts contra-revenue account', function () {
    $invoice = app(SaveInvoice::class)->handle([
        'contact_id' => $this->contact->id,
        'invoice_date' => '2026-06-01',
        'due_date' => '2026-06-30',
        'document_discount_pct' => '10',
        'lines' => [[
            'account_id' => $this->income->id,
            'quantity' => '1',
            'unit_price_cents' => 100000,
        ]],
    ]);

    expect($invoice->subtotal_cents)->toBe(100000)
        ->and($invoice->document_discount_cents)->toBe(10000)
        ->and($invoice->total_cents)->toBe(90000);

    $entry = app(InvoicePoster::class)->post($invoice);
    $entry->load('lines');

    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $discount = Account::query()->where('name', 'Sales Discounts')->first();

    expect($entry->isBalanced())->toBeTrue()
        ->and($discount)->not->toBeNull()
        ->and($entry->lines->firstWhere('account_id', $ar->id)->debit_cents)->toBe(90000)
        ->and($entry->lines->firstWhere('account_id', $discount->id)->debit_cents)->toBe(10000)
        ->and($entry->lines->firstWhere('account_id', $this->income->id)->credit_cents)->toBe(100000);
});

it('reflects markup + document discount through the invoice form', function () {
    $component = Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('contact_id', $this->contact->id)
        ->set('lines.0.account_id', $this->income->id)
        ->set('lines.0.quantity', '1')
        ->set('lines.0.unit_price', '100.00')
        ->set('lines.0.markup_pct', '20')
        ->set('document_discount_pct', '10')
        ->call('postInvoice')
        ->assertHasNoErrors();

    $invoice = Invoice::query()->latest('id')->firstOrFail();

    // line: 100 + 20% markup = 120 subtotal; doc discount 10% of 120 = 12; total 108.
    expect($invoice->subtotal_cents)->toBe(12000)
        ->and($invoice->document_discount_cents)->toBe(1200)
        ->and($invoice->total_cents)->toBe(10800);
});
