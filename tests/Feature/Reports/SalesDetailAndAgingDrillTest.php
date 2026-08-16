<?php

use App\Actions\Sales\SaveInvoice;
use App\Actions\Sales\SaveSalesReceipt;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\SalesReceiptPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->uf = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->firstOrFail();
    $this->contact = Contact::factory()->customer()->create(['display_name' => 'Detail Co']);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function postOpenInvoice(int $cents = 50000): Invoice
{
    $invoice = app(SaveInvoice::class)->handle([
        'contact_id' => test()->contact->id,
        'invoice_date' => '2026-06-01',
        'due_date' => '2026-06-30',
        'lines' => [['account_id' => test()->income->id, 'quantity' => '1', 'unit_price_cents' => $cents]],
    ]);
    app(InvoicePoster::class)->post($invoice);

    return $invoice->fresh();
}

it('lists every sales document per customer in the detail report', function () {
    $invoice = postOpenInvoice(50000);

    $sr = app(SaveSalesReceipt::class)->handle([
        'contact_id' => $this->contact->id,
        'receipt_date' => '2026-06-02',
        'deposit_to_account_id' => $this->uf->id,
        'lines' => [['account_id' => $this->income->id, 'quantity' => '1', 'unit_price_cents' => 30000]],
    ]);
    app(SalesReceiptPoster::class)->post($sr);

    Livewire::test('pages::reports.sales-by-customer-detail', ['company' => $this->company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->assertSee('Detail Co')
        ->assertSee($invoice->invoice_no)
        ->assertSee($sr->sales_receipt_no);
});

it('renders clickable AR aging bucket cells', function () {
    postOpenInvoice(50000);

    Livewire::test('pages::reports.ar-aging', ['company' => $this->company])
        ->assertSeeHtml('data-test="aging-cell-link"');
});
