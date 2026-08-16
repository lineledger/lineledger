<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Posting\CreditMemoPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\ReceiptPoster;
use Carbon\CarbonImmutable;

it('groups open invoices into aging buckets by days overdue', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $company);

    $customer = Contact::create(['display_name' => 'Aging Test Customer', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    // Helper closure to create a posted invoice with a specific due date
    $create = function (string $no, CarbonImmutable $date, CarbonImmutable $due, int $cents) use ($customer, $income) {
        $inv = Invoice::create([
            'contact_id' => $customer->id,
            'invoice_no' => $no,
            'invoice_date' => $date,
            'due_date' => $due,
        ]);

        $inv->lines()->create([
            'account_id' => $income->id,
            'description' => 'x',
            'quantity' => '1',
            'unit_price_cents' => $cents,
            'line_subtotal_cents' => $cents,
            'line_tax_cents' => 0,
            'line_total_cents' => $cents,
            'line_order' => 0,
        ]);

        app(InvoicePoster::class)->post($inv);
    };

    $today = CarbonImmutable::create(2026, 5, 20);

    // Not yet due (current bucket)
    $create('A', $today->subDays(5), $today->addDays(10), 1000);
    // 5 days overdue
    $create('B', $today->subDays(15), $today->subDays(5), 2000);
    // 45 days overdue
    $create('C', $today->subDays(60), $today->subDays(45), 3000);
    // 100 days overdue
    $create('D', $today->subDays(120), $today->subDays(100), 5000);

    $this->actingAs($user);

    $response = $this->get(route('reports.ar-aging', ['company' => $company->slug, 'as_of' => $today->toDateString()]));

    $response->assertOk();
    $response->assertSee('Aging Test Customer');
    // Buckets are shown as formatted cents — confirm each appears
    $response->assertSee('10.00'); // current
    $response->assertSee('20.00'); // 1-30
    $response->assertSee('30.00'); // 31-60
    $response->assertSee('50.00'); // 90+
    $response->assertSee('110.00'); // total

    app()->forgetInstance('current_company');
});

it('reduces a customer total by unapplied receipts so it reconciles with the GL', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $company);

    $customer = Contact::create(['display_name' => 'Unapplied Customer', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();

    $today = CarbonImmutable::create(2026, 5, 20);

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-U1',
        'invoice_date' => $today,
        'due_date' => $today->addDays(30),
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'x',
        'quantity' => '1',
        'unit_price_cents' => 50000,
        'line_subtotal_cents' => 50000,
        'line_tax_cents' => 0,
        'line_total_cents' => 50000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    // Receipt with no applications — represents an on-account credit / overpayment
    $receipt = CustomerReceipt::create([
        'contact_id' => $customer->id,
        'receipt_no' => 'REC-U1',
        'receipt_date' => $today,
        'deposit_to_account_id' => $undeposited->id,
        'amount_cents' => 500,
    ]);
    app(ReceiptPoster::class)->post($receipt);

    $this->actingAs($user);

    // Unapplied credits are hidden by default now; turn the toggle off to see them.
    $response = $this->get(route('reports.ar-aging', ['company' => $company->slug, 'as_of' => $today->toDateString(), 'open_only' => '0']));

    $response->assertOk();
    $response->assertSee('Unapplied Customer');
    // Net AR = 500.00 invoice - 5.00 unapplied = 495.00, folded into the customer's
    // single row so the total matches the GL / AR statement.
    $response->assertSee('495.00');

    app()->forgetInstance('current_company');
});

it('keeps a voided credit memo tied to the GL so the aging total stays correct', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $company);

    // Freeze "now" to the as-of date. The void reversal is dated company->currentDateTime()
    // (i.e. now()); without this, once the real date passes $today the reversal falls outside
    // the aging window (je.entry_date <= $asOf) and the GL reconcile drops its +50.
    $this->travelTo(CarbonImmutable::create(2026, 5, 24, 12));

    $customer = Contact::create(['display_name' => 'Void Aging Customer', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();

    $today = CarbonImmutable::create(2026, 5, 24);

    // Invoice for 555, due in the future (Current bucket) → DR AR 555.
    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-000001',
        'invoice_date' => $today,
        'due_date' => $today->addDays(30),
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 55500,
        'line_subtotal_cents' => 55500,
        'line_tax_cents' => 0,
        'line_total_cents' => 55500,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    // Credit memo for 50 → CR AR 50.
    $memo = CreditMemo::create([
        'contact_id' => $customer->id,
        'credit_memo_no' => 'CM-000001',
        'credit_memo_date' => $today,
    ]);
    $memo->lines()->create([
        'account_id' => $income->id,
        'description' => 'Credit',
        'quantity' => '1',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 5000,
        'line_tax_cents' => 0,
        'line_total_cents' => 5000,
        'line_order' => 0,
    ]);
    app(CreditMemoPoster::class)->post($memo);

    // Card refund of the credit memo: negative receipt → DR AR 50.
    $refund = CustomerReceipt::create([
        'contact_id' => $customer->id,
        'credit_memo_id' => $memo->id,
        'receipt_no' => 'REC-000001',
        'receipt_date' => $today,
        'deposit_to_account_id' => $undeposited->id,
        'amount_cents' => -5000,
    ]);
    app(ReceiptPoster::class)->post($refund);

    // Void the credit memo → reversing entry DR AR 50.
    app(CreditMemoPoster::class)->void($memo);

    $this->actingAs($user);

    $response = $this->get(route('reports.ar-aging', ['company' => $company->slug, 'as_of' => $today->toDateString()]));

    $response->assertOk();
    $response->assertSee('Void Aging Customer');
    // 555 − 50 (CM) + 50 (refund) + 50 (void) = 605, tying to the GL AR balance — not 655.
    $response->assertSee('605.00');

    app()->forgetInstance('current_company');
});
