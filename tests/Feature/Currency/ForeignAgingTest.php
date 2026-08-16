<?php

use App\Actions\Accounting\EnableCompanyCurrency;
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
    $this->company = Company::factory()->create(['currency_code' => 'CAD']);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    app(EnableCompanyCurrency::class)->handle($this->company, 'USD');
    $this->company->refresh();

    $customer = Contact::create(['display_name' => 'US Co', 'is_customer' => true, 'currency_code' => 'USD']);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $invoice = Invoice::create([
        'contact_id' => $customer->id, 'invoice_no' => 'INV-1',
        'invoice_date' => '2026-03-01', 'due_date' => '2026-03-31',
        'currency_code' => 'USD', 'fx_rate' => '1.35',
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id, 'description' => 'Sale', 'quantity' => '1',
        'unit_price_cents' => 100_000, 'line_subtotal_cents' => 100_000,
        'line_tax_cents' => 0, 'line_total_cents' => 100_000, 'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('reports a foreign customer balance in home cents on the AR aging', function () {
    $report = Livewire::test('pages::reports.ar-aging', ['company' => $this->company])
        ->set('asOf', '2026-04-15')
        ->instance()->report();

    // 1,000 USD @ 1.35 = 1,350.00 CAD, and the grand total ties to the home AR control.
    expect($report['totals']['total'])->toBe(135_000);

    $usCo = collect($report['rows'])->firstWhere('name', 'US Co');
    expect($usCo['total'])->toBe(135_000);
});
