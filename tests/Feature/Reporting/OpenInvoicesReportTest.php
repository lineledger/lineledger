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
use App\Services\Posting\InvoiceReconciler;
use App\Services\Posting\ReceiptPoster;
use Carbon\CarbonImmutable;

it('lists only posted invoices with a balance owing as of the date', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $company);

    $customer = Contact::create(['display_name' => 'Open Inv Customer', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $today = CarbonImmutable::create(2026, 5, 20);

    $create = function (string $no, CarbonImmutable $date, CarbonImmutable $due, int $cents) use ($customer, $income): Invoice {
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

        return $inv->fresh();
    };

    // Open: fully unpaid → appears.
    $create('OPEN-1', $today->subDays(10), $today->addDays(20), 10000);

    // Paid in full → excluded.
    $paid = $create('PAID-1', $today->subDays(10), $today->addDays(20), 5000);

    // Future-dated → excluded by as-of.
    $create('FUTURE-1', $today->addDays(5), $today->addDays(35), 7000);

    // Settle PAID-1 in full via a receipt so its balance is zero.
    $receipt = CustomerReceipt::create([
        'contact_id' => $customer->id,
        'receipt_no' => 'RCPT-1',
        'receipt_date' => $today->subDays(1),
        'amount_cents' => 5000,
        'deposit_to_account_id' => Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->value('id'),
    ]);
    $receipt->applications()->create(['invoice_id' => $paid->id, 'amount_cents' => 5000]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    $this->actingAs($user);

    $response = $this->get(route('reports.open-invoices', ['company' => $company->slug, 'as_of' => $today->toDateString()]));

    $response->assertOk();
    $response->assertSee('OPEN-1');
    $response->assertSee('100.00');     // open balance
    $response->assertDontSee('PAID-1');
    $response->assertDontSee('FUTURE-1');

    // Excel and PDF exports stream without error.
    foreach (['exportXlsx' => '.xlsx', 'exportPdf' => '.pdf'] as $method => $ext) {
        $component = Livewire\Livewire::test('pages::reports.open-invoices', [
            'company' => $company,
        ])->set('asOf', $today->toDateString())->call($method);

        expect(data_get($component->effects, 'download.name'))->toEndWith($ext);
    }
});

it('surfaces an outstanding customer credit memo and nets the open balance', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $company);

    $customer = Contact::create(['display_name' => 'Credited Co', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $today = CarbonImmutable::create(2026, 5, 20);

    // A $100 open invoice.
    $inv = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'OPEN-CR',
        'invoice_date' => $today->subDays(5),
        'due_date' => $today->addDays(25),
    ]);
    $inv->lines()->create([
        'account_id' => $income->id, 'description' => 'x', 'quantity' => '1',
        'unit_price_cents' => 10000, 'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0, 'line_total_cents' => 10000, 'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($inv);

    // A $30 posted credit memo for the same customer.
    $memo = CreditMemo::create([
        'contact_id' => $customer->id,
        'credit_memo_no' => 'CM-1',
        'credit_memo_date' => $today->subDays(2),
    ]);
    $memo->lines()->create([
        'account_id' => $income->id, 'description' => 'refund', 'quantity' => '1',
        'unit_price_cents' => 3000, 'line_subtotal_cents' => 3000,
        'line_tax_cents' => 0, 'line_total_cents' => 3000, 'line_order' => 0,
    ]);
    app(CreditMemoPoster::class)->post($memo);

    app()->forgetInstance('current_company');

    $report = Livewire\Livewire::test('pages::reports.open-invoices', ['company' => $company])
        ->set('asOf', $today->toDateString())
        ->get('report');

    $creditRow = collect($report['rows'])->firstWhere('type', 'credit');

    expect($creditRow)->not->toBeNull()
        ->and($creditRow['contact_id'])->toBe($customer->id)
        ->and($creditRow['balance'])->toBe(-3000)
        ->and($report['totals']['gross_balance'])->toBe(10000)
        ->and($report['totals']['credits'])->toBe(3000)
        // Net balance = 100 invoice - 30 credit = customer's GL AR.
        ->and($report['totals']['balance'])->toBe(7000);
});

it('nets an unapplied customer payment so the open balance ties to AR Aging', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $company);

    $customer = Contact::create(['display_name' => 'Overpaid Co', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $today = CarbonImmutable::create(2026, 5, 20);

    // $1,000 invoice, paid by a $1,000 receipt but only $800 applied → $200 unapplied.
    $inv = Invoice::create([
        'contact_id' => $customer->id, 'invoice_no' => 'OVERPAY',
        'invoice_date' => $today->subDays(3), 'due_date' => $today->addDays(27),
    ]);
    $inv->lines()->create([
        'account_id' => $income->id, 'description' => 'x', 'quantity' => '1',
        'unit_price_cents' => 100000, 'line_subtotal_cents' => 100000,
        'line_tax_cents' => 0, 'line_total_cents' => 100000, 'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($inv);

    $receipt = CustomerReceipt::create([
        'contact_id' => $customer->id, 'receipt_no' => 'OP-1', 'receipt_date' => $today->subDays(1),
        'amount_cents' => 100000,
        'deposit_to_account_id' => Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->value('id'),
    ]);
    $receipt->applications()->create(['invoice_id' => $inv->id, 'amount_cents' => 80000]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    app()->forgetInstance('current_company');

    $report = Livewire\Livewire::test('pages::reports.open-invoices', ['company' => $company])
        ->set('asOf', $today->toDateString())
        ->get('report');

    // Invoice shows $200 open; the $200 unapplied payment appears as a credit row; net $0
    // — matching the customer's GL AR (and AR Aging).
    expect(collect($report['rows'])->firstWhere('type', 'credit'))->not->toBeNull()
        ->and($report['totals']['gross_balance'])->toBe(20000)
        ->and($report['totals']['credits'])->toBe(20000)
        ->and($report['totals']['balance'])->toBe(0);
});

it('clears a credit-memo-backed invoice when close settled balance applies the credit', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $company);

    $customer = Contact::create(['display_name' => 'Reconciled Co', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $today = CarbonImmutable::create(2026, 5, 26);

    // $555 invoice + $50 posted credit memo → GL AR is $505.
    $inv = Invoice::create([
        'contact_id' => $customer->id, 'invoice_no' => 'INV-RC',
        'invoice_date' => $today, 'due_date' => $today->addDays(30),
    ]);
    $inv->lines()->create([
        'account_id' => $income->id, 'description' => 'x', 'quantity' => '1',
        'unit_price_cents' => 55500, 'line_subtotal_cents' => 55500,
        'line_tax_cents' => 0, 'line_total_cents' => 55500, 'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($inv);

    $memo = CreditMemo::create([
        'contact_id' => $customer->id, 'credit_memo_no' => 'CM-RC', 'credit_memo_date' => $today,
    ]);
    $memo->lines()->create([
        'account_id' => $income->id, 'description' => 'credit', 'quantity' => '1',
        'unit_price_cents' => 5000, 'line_subtotal_cents' => 5000,
        'line_tax_cents' => 0, 'line_total_cents' => 5000, 'line_order' => 0,
    ]);
    app(CreditMemoPoster::class)->post($memo);

    // Before clearing: invoice shows its full $555, the credit appears as a -$50 row, net $505.
    app()->instance('current_company', $company);
    $before = Livewire\Livewire::test('pages::reports.open-invoices', ['company' => $company])
        ->set('asOf', $today->toDateString())
        ->get('report');

    expect(collect($before['rows'])->firstWhere('type', 'credit'))->not->toBeNull()
        ->and($before['totals']['gross_balance'])->toBe(55500)
        ->and($before['totals']['credits'])->toBe(5000)
        ->and($before['totals']['balance'])->toBe(50500);

    // "Close settled balance" applies the $50 credit to the invoice (no new GL).
    $closed = app(InvoiceReconciler::class)->reconcileInvoice($inv->fresh());

    expect($closed)->toBe(5000)
        ->and($inv->fresh()->reconciled_cents)->toBe(5000)
        ->and($inv->fresh()->balanceCents())->toBe(50500);

    // After: invoice now shows its reduced $505 balance, the credit is consumed (no credit
    // row), and the net still ties to the customer's GL AR of $505.
    $after = Livewire\Livewire::test('pages::reports.open-invoices', ['company' => $company])
        ->set('asOf', $today->toDateString())
        ->get('report');

    app()->forgetInstance('current_company');

    expect(collect($after['rows'])->firstWhere('type', 'credit'))->toBeNull()
        ->and($after['totals']['gross_balance'])->toBe(50500)
        ->and($after['totals']['credits'])->toBe(0)
        ->and($after['totals']['balance'])->toBe(50500);
});

it('is reachable and shows an empty state with no open invoices', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user);

    $this->get(route('reports.open-invoices', ['company' => $company->slug]))
        ->assertOk()
        ->assertSee('No open invoices as of this date.');
});
