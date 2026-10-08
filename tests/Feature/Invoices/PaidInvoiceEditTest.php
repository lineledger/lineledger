<?php

use App\Actions\Sales\SaveInvoice;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\ReceiptPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->firstOrFail();
    $this->contact = Contact::factory()->customer()->create();

    // A $100.00 invoice, paid in full by one receipt.
    $this->invoice = app(SaveInvoice::class)->handle([
        'contact_id' => $this->contact->id,
        'invoice_date' => now()->toDateString(),
        'lines' => [[
            'account_id' => $this->income->id,
            'description' => 'Service',
            'quantity' => '1',
            'unit_price_cents' => 10000,
        ]],
    ]);
    app(InvoicePoster::class)->post($this->invoice);

    $receipt = CustomerReceipt::create([
        'contact_id' => $this->contact->id,
        'receipt_no' => 'REC-1',
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->value('id'),
        'amount_cents' => 10000,
    ]);
    $receipt->applications()->create(['invoice_id' => $this->invoice->id, 'amount_cents' => 10000]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    $this->invoice->refresh();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('refuses to lower a paid invoice below its receipts and saves nothing', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice])
        ->set('lines.0.unit_price', '60.00')
        ->set('memo', 'Price adjusted')
        ->call('postInvoice')
        ->assertHasErrors('lines')
        ->assertNoRedirect();

    $invoice = $this->invoice->fresh('lines');
    expect($invoice->total_cents)->toBe(10000)
        ->and($invoice->amount_paid_cents)->toBe(10000)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->memo)->not->toBe('Price adjusted')
        ->and($invoice->lines->sole()->unit_price_cents)->toBe(10000);

    expect($this->ar->fresh()->balance_cents)->toBe(0);
});

it('still saves edits to a paid invoice that keep the total at or above its receipts', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $this->invoice])
        ->set('memo', 'Thanks for the prompt payment')
        ->call('postInvoice')
        ->assertHasNoErrors()
        ->assertRedirect();

    $invoice = $this->invoice->fresh();
    expect($invoice->memo)->toBe('Thanks for the prompt payment')
        ->and($invoice->total_cents)->toBe(10000)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid);
});
