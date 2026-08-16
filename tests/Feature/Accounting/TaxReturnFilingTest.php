<?php

use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Enums\TaxReturnStatus;
use App\Exceptions\Posting\TaxPeriodFiledException;
use App\Models\Account;
use App\Models\AccountingAuditLog;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\TaxCode;
use App\Models\TaxReturn;
use App\Services\Posting\BillPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\TaxCalculator;
use App\Services\Tax\TaxReturnBuilder;
use App\Services\Tax\TaxReturnFiler;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->customer = Contact::create([
        'display_name' => 'Acme Corp',
        'is_customer' => true,
    ]);

    $this->vendor = Contact::create([
        'display_name' => 'Vendor Co',
        'is_vendor' => true,
    ]);

    $this->incomeAccount = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->expenseAccount = Account::query()->where('subtype', AccountSubtype::Expense->value)->first();
    $this->gst = TaxCode::where('code', 'GST')->firstOrFail();

    $this->periodStart = CarbonImmutable::parse('2026-01-01');
    $this->periodEnd = CarbonImmutable::parse('2026-03-31');
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function postInvoiceWithGst(Contact $customer, Account $income, TaxCode $gst, string $date, int $subtotalCents): Invoice
{
    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-'.uniqid(),
        'invoice_date' => $date,
        'due_date' => $date,
    ]);

    $totals = app(TaxCalculator::class)->line('1', $subtotalCents, $gst);

    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => $subtotalCents,
        'tax_code_id' => $gst->id,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($invoice);

    return $invoice->fresh();
}

function postBillWithGst(Contact $vendor, Account $expense, TaxCode $gst, string $date, int $subtotalCents): Bill
{
    $bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor->value,
        'bill_no' => 'B-'.uniqid(),
        'bill_date' => $date,
        'due_date' => $date,
    ]);

    $totals = app(TaxCalculator::class)->line('1', $subtotalCents, $gst);

    $bill->lines()->create([
        'account_id' => $expense->id,
        'description' => 'Supplies',
        'quantity' => '1',
        'unit_price_cents' => $subtotalCents,
        'tax_code_id' => $gst->id,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);

    app(BillPoster::class)->post($bill);

    return $bill->fresh();
}

it('files a tax return and snapshots all in-period journal lines', function () {
    postInvoiceWithGst($this->customer, $this->incomeAccount, $this->gst, '2026-02-01', 10000);
    postInvoiceWithGst($this->customer, $this->incomeAccount, $this->gst, '2026-02-15', 20000);
    postBillWithGst($this->vendor, $this->expenseAccount, $this->gst, '2026-03-01', 5000);
    postInvoiceWithGst($this->customer, $this->incomeAccount, $this->gst, '2026-05-01', 99999);

    $return = TaxReturn::create([
        'tax_agency_id' => $this->gst->agency_id,
        'tax_return_no' => 'TR-001',
        'period_start' => $this->periodStart->toDateString(),
        'period_end' => $this->periodEnd->toDateString(),
        'status' => TaxReturnStatus::Draft,
    ]);

    $filed = app(TaxReturnFiler::class)->file($return);

    expect($filed->status)->toBe(TaxReturnStatus::Filed);
    expect($filed->lines)->toHaveCount(3);
    expect($filed->collected_cents)->toBe(1500);
    expect($filed->paid_cents)->toBe(250);
    expect($filed->net_cents)->toBe(1250);
    expect($filed->filed_at)->not->toBeNull();
});

it('omits excluded journal lines from the snapshot and totals', function () {
    postInvoiceWithGst($this->customer, $this->incomeAccount, $this->gst, '2026-02-01', 10000);
    postInvoiceWithGst($this->customer, $this->incomeAccount, $this->gst, '2026-02-15', 20000);
    $excludeBill = postBillWithGst($this->vendor, $this->expenseAccount, $this->gst, '2026-03-01', 5000);

    $excludedLineId = app(TaxReturnBuilder::class)
        ->build(
            $this->gst->agency,
            $this->periodStart,
            $this->periodEnd,
        )
        ->first(fn ($row) => $row['source_type'] === Bill::class && (int) $row['source_id'] === $excludeBill->id)['journal_line_id'];

    $return = TaxReturn::create([
        'tax_agency_id' => $this->gst->agency_id,
        'tax_return_no' => 'TR-EXCL',
        'period_start' => $this->periodStart->toDateString(),
        'period_end' => $this->periodEnd->toDateString(),
        'status' => TaxReturnStatus::Draft,
        'excluded_journal_line_ids' => [$excludedLineId],
    ]);

    $filed = app(TaxReturnFiler::class)->file($return);

    expect($filed->lines)->toHaveCount(2);
    expect($filed->lines->pluck('journal_line_id'))->not->toContain($excludedLineId);
    expect($filed->collected_cents)->toBe(1500);
    expect($filed->paid_cents)->toBe(0);
    expect($filed->net_cents)->toBe(1500);

    $row = AccountingAuditLog::query()
        ->where('action', 'tax_return.filed')
        ->where('auditable_id', $return->id)
        ->first();

    expect($row->payload['excluded_line_count'])->toBe(1);
});

it('writes an audit log row when filing', function () {
    postInvoiceWithGst($this->customer, $this->incomeAccount, $this->gst, '2026-02-01', 10000);

    $return = TaxReturn::create([
        'tax_agency_id' => $this->gst->agency_id,
        'tax_return_no' => 'TR-AUDIT',
        'period_start' => $this->periodStart->toDateString(),
        'period_end' => $this->periodEnd->toDateString(),
        'status' => TaxReturnStatus::Draft,
    ]);

    app(TaxReturnFiler::class)->file($return);

    $row = AccountingAuditLog::query()
        ->where('action', 'tax_return.filed')
        ->where('auditable_id', $return->id)
        ->first();

    expect($row)->not->toBeNull();
    expect($row->payload['line_count'])->toBe(1);
    expect($row->payload['collected_cents'])->toBe(500);
});

it('snapshot survives a void of an underlying invoice', function () {
    $invoice = postInvoiceWithGst($this->customer, $this->incomeAccount, $this->gst, '2026-02-01', 10000);

    $return = TaxReturn::create([
        'tax_agency_id' => $this->gst->agency_id,
        'tax_return_no' => 'TR-SNAP',
        'period_start' => $this->periodStart->toDateString(),
        'period_end' => $this->periodEnd->toDateString(),
        'status' => TaxReturnStatus::Draft,
    ]);
    $filed = app(TaxReturnFiler::class)->file($return);

    $originalLine = $filed->lines->first();
    $originalLabel = $originalLine->doc_label;
    $originalAmount = $originalLine->amount_cents;

    // void unlocks the agency so we can void the underlying invoice
    app(TaxReturnFiler::class)->void($filed, 'snapshot test — temporarily unlock');
    app(InvoicePoster::class)->void($invoice);

    $reread = $filed->fresh(['lines']);
    expect($reread->lines->first()->doc_label)->toBe($originalLabel);
    expect($reread->lines->first()->amount_cents)->toBe($originalAmount);
});

it('blocks posting a new invoice in a filed period that uses the same agency', function () {
    postInvoiceWithGst($this->customer, $this->incomeAccount, $this->gst, '2026-02-01', 10000);

    $return = TaxReturn::create([
        'tax_agency_id' => $this->gst->agency_id,
        'tax_return_no' => 'TR-LOCK',
        'period_start' => $this->periodStart->toDateString(),
        'period_end' => $this->periodEnd->toDateString(),
        'status' => TaxReturnStatus::Draft,
    ]);
    app(TaxReturnFiler::class)->file($return);

    expect(fn () => postInvoiceWithGst($this->customer, $this->incomeAccount, $this->gst, '2026-02-20', 5000))
        ->toThrow(TaxPeriodFiledException::class);
});

it('still allows posting an invoice without that agency tax in a filed period', function () {
    $return = TaxReturn::create([
        'tax_agency_id' => $this->gst->agency_id,
        'tax_return_no' => 'TR-LOCK2',
        'period_start' => $this->periodStart->toDateString(),
        'period_end' => $this->periodEnd->toDateString(),
        'status' => TaxReturnStatus::Draft,
    ]);
    app(TaxReturnFiler::class)->file($return);

    $invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-NOTAX',
        'invoice_date' => '2026-02-15',
        'due_date' => '2026-02-15',
    ]);

    $invoice->lines()->create([
        'account_id' => $this->incomeAccount->id,
        'description' => 'No-tax sale',
        'quantity' => '1',
        'unit_price_cents' => 5000,
        'tax_code_id' => null,
        'line_subtotal_cents' => 5000,
        'line_tax_cents' => 0,
        'line_total_cents' => 5000,
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($invoice);

    expect($invoice->fresh()->journal_entry_id)->not->toBeNull();
});

it('still allows posting an invoice with that agency tax outside the filed period', function () {
    $return = TaxReturn::create([
        'tax_agency_id' => $this->gst->agency_id,
        'tax_return_no' => 'TR-LOCK3',
        'period_start' => $this->periodStart->toDateString(),
        'period_end' => $this->periodEnd->toDateString(),
        'status' => TaxReturnStatus::Draft,
    ]);
    app(TaxReturnFiler::class)->file($return);

    $invoice = postInvoiceWithGst($this->customer, $this->incomeAccount, $this->gst, '2026-04-15', 5000);

    expect($invoice->journal_entry_id)->not->toBeNull();
});

it('voiding a filed return unlocks the period and allows new postings', function () {
    $return = TaxReturn::create([
        'tax_agency_id' => $this->gst->agency_id,
        'tax_return_no' => 'TR-VOID',
        'period_start' => $this->periodStart->toDateString(),
        'period_end' => $this->periodEnd->toDateString(),
        'status' => TaxReturnStatus::Draft,
    ]);
    app(TaxReturnFiler::class)->file($return);

    expect(fn () => postInvoiceWithGst($this->customer, $this->incomeAccount, $this->gst, '2026-02-15', 5000))
        ->toThrow(TaxPeriodFiledException::class);

    app(TaxReturnFiler::class)->void($return->fresh(), 'amendment needed');

    $invoice = postInvoiceWithGst($this->customer, $this->incomeAccount, $this->gst, '2026-02-15', 5000);
    expect($invoice->journal_entry_id)->not->toBeNull();

    $voided = $return->fresh();
    expect($voided->status)->toBe(TaxReturnStatus::Void);
    expect($voided->void_reason)->toBe('amendment needed');
});

it('blocks filing a second return that overlaps an existing filed one', function () {
    $first = TaxReturn::create([
        'tax_agency_id' => $this->gst->agency_id,
        'tax_return_no' => 'TR-FIRST',
        'period_start' => $this->periodStart->toDateString(),
        'period_end' => $this->periodEnd->toDateString(),
        'status' => TaxReturnStatus::Draft,
    ]);
    app(TaxReturnFiler::class)->file($first);

    $overlap = TaxReturn::create([
        'tax_agency_id' => $this->gst->agency_id,
        'tax_return_no' => 'TR-OVERLAP',
        'period_start' => '2026-03-15',
        'period_end' => '2026-06-30',
        'status' => TaxReturnStatus::Draft,
    ]);

    expect(fn () => app(TaxReturnFiler::class)->file($overlap))
        ->toThrow(RuntimeException::class, 'already covers');
});
