<?php

use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Enums\TaxReturnStatus;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\TaxCode;
use App\Models\TaxReturn;
use App\Models\User;
use App\Services\Posting\BillPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\TaxCalculator;
use App\Services\Tax\TaxReturnBuilder;
use App\Services\Tax\TaxReturnFiler;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->customer = Contact::create(['display_name' => 'Acme Corp', 'is_customer' => true]);
    $this->vendor = Contact::create(['display_name' => 'Vendor Co', 'is_vendor' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->first();
    $this->gst = TaxCode::where('code', 'GST')->firstOrFail();

    $this->start = CarbonImmutable::parse('2026-01-01');
    $this->end = CarbonImmutable::parse('2026-03-31');
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function postExclusionInvoice(string $date, int $subtotal): Invoice
{
    $invoice = Invoice::create([
        'contact_id' => test()->customer->id,
        'invoice_no' => 'INV-'.uniqid(),
        'invoice_date' => $date,
        'due_date' => $date,
    ]);

    $totals = app(TaxCalculator::class)->line('1', $subtotal, test()->gst);

    $invoice->lines()->create([
        'account_id' => test()->income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => $subtotal,
        'tax_code_id' => test()->gst->id,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($invoice);

    return $invoice->fresh();
}

function postExclusionBill(string $date, int $subtotal): Bill
{
    $bill = Bill::create([
        'contact_id' => test()->vendor->id,
        'bill_type' => BillType::Vendor->value,
        'bill_no' => 'B-'.uniqid(),
        'bill_date' => $date,
        'due_date' => $date,
    ]);

    $totals = app(TaxCalculator::class)->line('1', $subtotal, test()->gst);

    $bill->lines()->create([
        'account_id' => test()->expense->id,
        'description' => 'Supplies',
        'quantity' => '1',
        'unit_price_cents' => $subtotal,
        'tax_code_id' => test()->gst->id,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);

    app(BillPoster::class)->post($bill);

    return $bill->fresh();
}

function excludedJournalLineId(Bill $bill): int
{
    // Match on type AND id — invoices and bills can share a numeric id.
    return (int) app(TaxReturnBuilder::class)
        ->build(test()->gst->agency, test()->start, test()->end)
        ->first(fn ($row) => $row['source_type'] === Bill::class && (int) $row['source_id'] === $bill->id)['journal_line_id'];
}

it('drops an unchecked line from the preview totals', function () {
    postExclusionInvoice('2026-02-01', 10000);
    $bill = postExclusionBill('2026-03-01', 5000);
    $lineId = excludedJournalLineId($bill);

    $component = Livewire::test('pages::tax-returns.form', ['company' => $this->company])
        ->set('tax_agency_id', $this->gst->agency_id)
        ->set('period_start', $this->start->toDateString())
        ->set('period_end', $this->end->toDateString());

    expect($component->get('preview')['paid'])->toBe(250);

    $component->call('toggleLine', $lineId);

    expect($component->get('preview')['paid'])->toBe(0);
    expect($component->get('preview')['collected'])->toBe(500);
});

it('persists exclusions on save draft and omits them when filed', function () {
    postExclusionInvoice('2026-02-01', 10000);
    $bill = postExclusionBill('2026-03-01', 5000);
    $lineId = excludedJournalLineId($bill);

    Livewire::test('pages::tax-returns.form', ['company' => $this->company])
        ->set('tax_agency_id', $this->gst->agency_id)
        ->set('tax_return_no', 'TR-FORM-EXCL')
        ->set('period_start', $this->start->toDateString())
        ->set('period_end', $this->end->toDateString())
        ->call('toggleLine', $lineId)
        ->call('saveDraft');

    $return = TaxReturn::where('tax_return_no', 'TR-FORM-EXCL')->firstOrFail();
    expect($return->excluded_journal_line_ids)->toBe([$lineId]);

    $filed = app(TaxReturnFiler::class)->file($return);

    expect($filed->status)->toBe(TaxReturnStatus::Filed);
    expect($filed->lines)->toHaveCount(1);
    expect($filed->paid_cents)->toBe(0);
    expect($filed->collected_cents)->toBe(500);
});

it('clears stale exclusions when the period no longer contains the line', function () {
    postExclusionInvoice('2026-02-01', 10000);
    $bill = postExclusionBill('2026-03-01', 5000);
    $lineId = excludedJournalLineId($bill);

    Livewire::test('pages::tax-returns.form', ['company' => $this->company])
        ->set('tax_agency_id', $this->gst->agency_id)
        ->set('tax_return_no', 'TR-STALE')
        ->set('period_start', $this->start->toDateString())
        ->set('period_end', $this->end->toDateString())
        ->call('toggleLine', $lineId)
        // Shift the period so the excluded line falls outside it.
        ->set('period_start', '2026-02-01')
        ->set('period_end', '2026-02-28')
        ->call('saveDraft');

    $return = TaxReturn::where('tax_return_no', 'TR-STALE')->firstOrFail();
    expect($return->excluded_journal_line_ids)->toBe([]);
});
