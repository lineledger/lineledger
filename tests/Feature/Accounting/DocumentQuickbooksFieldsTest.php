<?php

use App\Actions\Purchasing\SaveBill;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Posting\BillPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->customer = Contact::create(['display_name' => 'Acme Corp', 'is_customer' => true]);
    $this->employee = Contact::create(['display_name' => 'Rep Reppington', 'is_employee' => true]);
    $this->incomeAccount = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->expenseAccount = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('saves QuickBooks header and line fields on an invoice and posts net revenue', function () {
    $component = Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->call('selectContact', $this->customer->id)
        ->set('sales_rep_id', $this->employee->id)
        ->set('customer_po', 'PO-9000')
        ->set('ship_date', '2026-05-20')
        ->set('ship_via', 'UPS Ground')
        ->set('fob', 'Origin')
        ->set('tracking_no', '1Z999')
        ->set('customer_message', 'Thanks for your business!')
        ->set('lines.0.account_id', $this->incomeAccount->id)
        ->set('lines.0.description', 'Consulting')
        ->set('lines.0.service_date', '2026-05-18')
        ->set('lines.0.quantity', '1')
        ->set('lines.0.unit_price', '100.00')
        ->set('lines.0.discount_pct', '10')
        ->call('postInvoice');

    $component->assertHasNoErrors();

    $invoice = Invoice::query()->latest('id')->firstOrFail();

    expect($invoice->sales_rep_id)->toBe($this->employee->id)
        ->and($invoice->customer_po)->toBe('PO-9000')
        ->and($invoice->ship_via)->toBe('UPS Ground')
        ->and($invoice->fob)->toBe('Origin')
        ->and($invoice->tracking_no)->toBe('1Z999')
        ->and($invoice->customer_message)->toBe('Thanks for your business!')
        ->and($invoice->ship_date->toDateString())->toBe('2026-05-20');

    $line = $invoice->lines->firstOrFail();

    expect($line->service_date->toDateString())->toBe('2026-05-18')
        ->and($line->line_discount_cents)->toBe(1000)   // 10% of $100
        ->and($line->line_subtotal_cents)->toBe(9000)   // net of discount
        ->and($invoice->total_cents)->toBe(9000);

    // The GL records the net amount — discounts need no separate account.
    expect($this->incomeAccount->fresh()->balance_cents)->toBe(9000);
});

it('rejects a sales rep that is not an employee', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->call('selectContact', $this->customer->id)
        ->set('sales_rep_id', $this->customer->id) // a customer, not an employee
        ->set('lines.0.account_id', $this->incomeAccount->id)
        ->set('lines.0.quantity', '1')
        ->set('lines.0.unit_price', '50.00')
        ->call('saveDraft')
        ->assertHasErrors('sales_rep_id');
});

it('only offers the sales rep field while the Employees feature is on', function (string $component, string $testId) {
    // Sales reps are employee contacts, so the field is meaningless — and unmanageable —
    // once the Employees feature is switched off.
    $this->company->update(['features_employees' => true]);

    Livewire::test($component, ['company' => $this->company])
        ->assertSeeHtml('data-test="'.$testId.'"');

    $this->company->update(['features_employees' => false]);

    Livewire::test($component, ['company' => $this->company])
        ->assertDontSeeHtml('data-test="'.$testId.'"');
})->with([
    'invoice' => ['pages::invoices.form', 'invoice-sales-rep'],
    'estimate' => ['pages::estimates.form', 'estimate-sales-rep'],
    'sales order' => ['pages::sales-orders.form', 'sales-order-sales-rep'],
    'credit memo' => ['pages::credit-memos.form', 'credit-memo-sales-rep'],
]);

it('reduces the line subtotal by the discount before tax in the form', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('lines.0.unit_price', '100.00')
        ->set('lines.0.quantity', '2')
        ->set('lines.0.discount_pct', '25') // 2 × $100 = $200, less 25% = $150
        ->assertSet('lines.0.subtotal', 15000);
});

it('posts a bill line net of its discount to the expense account', function () {
    $vendor = Contact::create(['display_name' => 'Supplier Inc', 'is_vendor' => true]);

    $bill = app(SaveBill::class)->handle([
        'contact_id' => $vendor->id,
        'bill_no' => 'BILL-100',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'lines' => [[
            'account_id' => $this->expenseAccount->id,
            'description' => 'Materials',
            'quantity' => '1',
            'unit_price_cents' => 10000,
            'line_discount_pct' => '20', // $100 less 20% = $80
        ]],
    ]);

    $line = $bill->lines->firstOrFail();

    expect($line->line_discount_cents)->toBe(2000)
        ->and($line->line_subtotal_cents)->toBe(8000)
        ->and($bill->total_cents)->toBe(8000);

    app(BillPoster::class)->post($bill);

    expect($this->expenseAccount->fresh()->balance_cents)->toBe(8000);
});
