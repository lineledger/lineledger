<?php

use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\Posting\BillPoster;
use App\Services\Posting\InvoicePoster;
use Carbon\CarbonImmutable;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();
    $company = $user->currentCompany;

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $company = $user->currentCompany;

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});

test('the dashboard surfaces live financial figures', function () {
    $user = User::factory()->create();
    $company = $user->currentCompany;

    app()->instance('current_company', $company);

    $customer = Contact::create(['display_name' => 'Northwind LLC', 'is_customer' => true]);
    $vendor = Contact::create(['display_name' => 'Office Lessor', 'is_vendor' => true]);

    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
    $gst = TaxCode::where('code', 'GST')->firstOrFail();

    // Open invoice: $1,000 + 5% GST = $1,050 → accounts receivable.
    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-1042',
        'invoice_date' => CarbonImmutable::now()->subDays(2),
        'due_date' => CarbonImmutable::now()->addDays(28),
        'subtotal_cents' => 100000,
        'tax_cents' => 5000,
        'total_cents' => 105000,
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 100000,
        'tax_code_id' => $gst->id,
        'line_subtotal_cents' => 100000,
        'line_tax_cents' => 5000,
        'line_total_cents' => 105000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    // Open bill: $300 + 5% GST = $315 → accounts payable, due within the week.
    $bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-77',
        'bill_date' => CarbonImmutable::now()->subDays(1),
        'due_date' => CarbonImmutable::now()->addDays(3),
        'subtotal_cents' => 30000,
        'tax_cents' => 1500,
        'total_cents' => 31500,
    ]);
    $bill->lines()->create([
        'account_id' => $expense->id,
        'description' => 'Office lease',
        'quantity' => '1',
        'unit_price_cents' => 30000,
        'tax_code_id' => $gst->id,
        'line_subtotal_cents' => 30000,
        'line_tax_cents' => 1500,
        'line_total_cents' => 31500,
        'line_order' => 0,
    ]);
    app(BillPoster::class)->post($bill);

    app()->forgetInstance('current_company');

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Financial overview')
        ->assertSee('Cash flow')
        ->assertSee('Accounts receivable')
        ->assertSee('$1,050')                 // AR outstanding
        ->assertSee('1 open invoice')         // AR count
        ->assertSee('$315')                   // AP outstanding
        ->assertSee('due this week')          // AP soon-due flag
        ->assertSee('Northwind LLC');         // recent-transactions feed
});
