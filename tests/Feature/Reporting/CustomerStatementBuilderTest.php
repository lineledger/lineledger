<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Posting\CreditMemoPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\ReceiptPoster;
use App\Services\Reporting\CustomerStatementBuilder;
use App\Services\Reporting\OpenDocumentAgingBuilder;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function postStatementInvoice(object $test, Contact $customer, string $no, string $date, string $due, int $cents): Invoice
{
    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => $no,
        'invoice_date' => CarbonImmutable::parse($date),
        'due_date' => CarbonImmutable::parse($due),
    ]);
    $invoice->lines()->create([
        'account_id' => $test->income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => $cents,
        'line_subtotal_cents' => $cents,
        'line_tax_cents' => 0,
        'line_total_cents' => $cents,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    return $invoice->fresh();
}

it('lists open invoices with balances and ties the total to the aging strip', function () {
    $customer = Contact::factory()->customer()->create();

    postStatementInvoice($this, $customer, 'INV-OPEN-1', '2026-03-01', '2026-03-31', 20000);
    $partial = postStatementInvoice($this, $customer, 'INV-OPEN-2', '2026-04-01', '2026-05-01', 10000);

    // Partially pay the second invoice: 40 of 100.
    $undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();
    $receipt = CustomerReceipt::create([
        'contact_id' => $customer->id,
        'receipt_no' => 'REC-1',
        'receipt_date' => CarbonImmutable::create(2026, 4, 15),
        'deposit_to_account_id' => $undeposited->id,
        'amount_cents' => 4000,
    ]);
    $receipt->applications()->create(['invoice_id' => $partial->id, 'amount_cents' => 4000]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    $result = app(CustomerStatementBuilder::class)
        ->openInvoices($this->company, $customer, CarbonImmutable::create(2026, 6, 1));

    expect($result['rows'])->toHaveCount(2)
        ->and($result['rows'][0]['invoice_no'])->toBe('INV-OPEN-1')
        ->and($result['rows'][0]['total'])->toBe(20000)
        ->and($result['rows'][0]['balance'])->toBe(20000)
        ->and($result['rows'][0]['due_date'])->toBe('2026-03-31')
        ->and($result['rows'][1]['invoice_no'])->toBe('INV-OPEN-2')
        ->and($result['rows'][1]['balance'])->toBe(6000)
        ->and($result['total_due'])->toBe(26000)
        ->and($result['aging']['total'])->toBe(26000)
        ->and(array_sum(array_column($result['rows'], 'balance')))->toBe($result['total_due'])
        ->and($customer->recomputeArBalance())->toBe(26000);
});

it('absorbs credits on account into a single negative adjustment row', function () {
    $customer = Contact::factory()->customer()->create();

    postStatementInvoice($this, $customer, 'INV-CR-1', '2026-03-01', '2026-03-31', 20000);

    // An unapplied credit memo for 50 — no application to the invoice, so the
    // invoice stays fully open while the GL balance drops to 150.
    $memo = CreditMemo::create([
        'contact_id' => $customer->id,
        'credit_memo_no' => 'CM-CR-1',
        'credit_memo_date' => CarbonImmutable::create(2026, 3, 10),
    ]);
    $memo->lines()->create([
        'account_id' => $this->income->id,
        'description' => 'Credit',
        'quantity' => '1',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 5000,
        'line_tax_cents' => 0,
        'line_total_cents' => 5000,
        'line_order' => 0,
    ]);
    app(CreditMemoPoster::class)->post($memo);

    $result = app(CustomerStatementBuilder::class)
        ->openInvoices($this->company, $customer, CarbonImmutable::create(2026, 6, 1));

    $adjustment = collect($result['rows'])->firstWhere('kind', 'adjustment');

    expect($result['total_due'])->toBe(15000)
        ->and($adjustment)->not->toBeNull()
        ->and($adjustment['balance'])->toBe(-5000)
        ->and($adjustment['label'])->toBe('Credits on account')
        ->and(array_sum(array_column($result['rows'], 'balance')))->toBe($result['total_due']);
});

it('absorbs journal entries posted straight to AR into a positive adjustment row', function () {
    $customer = Contact::factory()->customer()->create();
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();

    postStatementInvoice($this, $customer, 'INV-JE-1', '2026-03-01', '2026-03-31', 10000);

    // A refund cheque booked as a plain JE: DR AR 25 tagged to the customer.
    $refund = JournalEntry::create([
        'entry_no' => 'JE-REFUND',
        'entry_date' => CarbonImmutable::create(2026, 3, 10),
        'memo' => 'Refund cheque',
    ]);
    $refund->lines()->create(['account_id' => $ar->id, 'contact_id' => $customer->id, 'debit_cents' => 2500, 'credit_cents' => 0, 'line_order' => 0]);
    $refund->lines()->create(['account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => 2500, 'line_order' => 1]);
    app(JournalPoster::class)->post($refund);

    $result = app(CustomerStatementBuilder::class)
        ->openInvoices($this->company, $customer, CarbonImmutable::create(2026, 6, 1));

    $adjustment = collect($result['rows'])->firstWhere('kind', 'adjustment');

    expect($result['total_due'])->toBe(12500)
        ->and($adjustment['balance'])->toBe(2500)
        ->and($adjustment['label'])->toBe('Other balance')
        ->and(array_sum(array_column($result['rows'], 'balance')))->toBe($result['total_due']);
});

it('excludes invoices dated after the as-of date', function () {
    $customer = Contact::factory()->customer()->create();

    postStatementInvoice($this, $customer, 'INV-BEFORE', '2026-03-01', '2026-03-31', 10000);
    postStatementInvoice($this, $customer, 'INV-AFTER', '2026-07-01', '2026-07-31', 5000);

    $result = app(CustomerStatementBuilder::class)
        ->openInvoices($this->company, $customer, CarbonImmutable::create(2026, 6, 1));

    expect(collect($result['rows'])->pluck('invoice_no'))->toContain('INV-BEFORE')
        ->not->toContain('INV-AFTER')
        ->and($result['total_due'])->toBe(10000);
});

it('buckets one contact aging row and returns zeros for a contact with no balance', function () {
    $customer = Contact::factory()->customer()->create();
    $stranger = Contact::factory()->customer()->create();

    // Due 2026-03-31 → 62 days overdue as of 2026-06-01 → b61_90.
    postStatementInvoice($this, $customer, 'INV-AGE-1', '2026-03-01', '2026-03-31', 20000);
    // Due 2026-06-30 → not yet due → current.
    postStatementInvoice($this, $customer, 'INV-AGE-2', '2026-05-30', '2026-06-30', 5000);

    $aging = app(OpenDocumentAgingBuilder::class);
    $asOf = CarbonImmutable::create(2026, 6, 1);

    $row = $aging->summaryRowForContact($this->company, 'ar', $asOf, $customer);

    expect($row['b61_90'])->toBe(20000)
        ->and($row['current'])->toBe(5000)
        ->and($row['b1_30'])->toBe(0)
        ->and($row['total'])->toBe(25000);

    expect($aging->summaryRowForContact($this->company, 'ar', $asOf, $stranger))
        ->toBe(['current' => 0, 'b1_30' => 0, 'b31_60' => 0, 'b61_90' => 0, 'b90_plus' => 0, 'total' => 0]);
});

it('builds the activity statement with the aging strip tied to the closing balance', function () {
    $customer = Contact::factory()->customer()->create();

    $invoice = postStatementInvoice($this, $customer, 'INV-ACT-1', '2026-03-01', '2026-03-31', 20000);

    $undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();
    $receipt = CustomerReceipt::create([
        'contact_id' => $customer->id,
        'receipt_no' => 'REC-ACT-1',
        'receipt_date' => CarbonImmutable::create(2026, 3, 15),
        'deposit_to_account_id' => $undeposited->id,
        'amount_cents' => 5000,
    ]);
    $receipt->applications()->create(['invoice_id' => $invoice->id, 'amount_cents' => 5000]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    $result = app(CustomerStatementBuilder::class)->activity(
        $this->company,
        $customer,
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 12, 31),
    );

    expect($result['statement']['opening'])->toBe(0)
        ->and(collect($result['statement']['lines'])->pluck('doc_no'))->toContain('INV-ACT-1')->toContain('REC-ACT-1')
        ->and($result['statement']['closing'])->toBe(15000)
        ->and($result['aging']['total'])->toBe(15000)
        ->and($result['start'])->toBe('2026-01-01')
        ->and($result['end'])->toBe('2026-12-31');
});
