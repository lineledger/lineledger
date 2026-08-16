<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\Location;
use App\Models\TaxCode;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\TaxCalculator;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->customer = Contact::create(['display_name' => 'Dimension Co', 'is_customer' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->class = Classification::create(['name' => 'East Region']);
    $this->location = Location::create(['name' => 'Chapel A']);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * @param  array<int, array<string, mixed>>  $lines
 */
function postInvoiceWithLines(Contact $customer, array $lines): Invoice
{
    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-'.fake()->unique()->numerify('######'),
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    foreach ($lines as $order => $line) {
        $invoice->lines()->create($line + ['line_order' => $order]);
    }

    app(InvoicePoster::class)->post($invoice);

    return $invoice->refresh();
}

it('scopes classifications and locations to the company', function () {
    $other = Company::factory()->create();
    app()->instance('current_company', $other);
    Classification::create(['name' => 'Other Co Class']);

    app()->instance('current_company', $this->company);

    expect(Classification::pluck('name')->all())->toBe(['East Region']);
    expect(Location::pluck('name')->all())->toBe(['Chapel A']);
});

it('tags the revenue GL line with the dimensions but leaves AR and tax untagged', function () {
    $gst = TaxCode::where('code', 'GST')->firstOrFail();
    $totals = app(TaxCalculator::class)->line('1', 10000, $gst);

    $invoice = postInvoiceWithLines($this->customer, [[
        'account_id' => $this->income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'tax_code_id' => $gst->id,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'class_id' => $this->class->id,
        'location_id' => $this->location->id,
    ]]);

    $lines = JournalLine::where('journal_entry_id', $invoice->journal_entry_id)->get();
    $arId = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->value('id');
    $payableId = $gst->agency->payable_account_id;

    $revenue = $lines->firstWhere('account_id', $this->income->id);
    $ar = $lines->firstWhere('account_id', $arId);
    $tax = $lines->firstWhere('account_id', $payableId);

    expect($revenue->class_id)->toBe($this->class->id);
    expect($revenue->location_id)->toBe($this->location->id);

    // System/aggregate legs are never dimension-tagged.
    expect($ar->class_id)->toBeNull();
    expect($ar->location_id)->toBeNull();
    expect($tax->class_id)->toBeNull();
    expect($tax->location_id)->toBeNull();
});

it('splits same-account revenue into separate GL legs per dimension', function () {
    $west = Classification::create(['name' => 'West Region']);

    $line = fn (int $class): array => [
        'account_id' => $this->income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 5000,
        'line_tax_cents' => 0,
        'line_total_cents' => 5000,
        'class_id' => $class,
    ];

    $invoice = postInvoiceWithLines($this->customer, [$line($this->class->id), $line($west->id)]);

    $revenueLines = JournalLine::where('journal_entry_id', $invoice->journal_entry_id)
        ->where('account_id', $this->income->id)
        ->get();

    // Same income account, two different classes → two distinct legs.
    expect($revenueLines)->toHaveCount(2);
    expect($revenueLines->pluck('class_id')->sort()->values()->all())
        ->toBe(collect([$this->class->id, $west->id])->sort()->values()->all());
    expect($revenueLines->sum('credit_cents'))->toBe(10000);
});

it('merges same-account, same-dimension lines into one leg (no-dimension parity)', function () {
    $line = [
        'account_id' => $this->income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 5000,
        'line_tax_cents' => 0,
        'line_total_cents' => 5000,
    ];

    $invoice = postInvoiceWithLines($this->customer, [$line, $line]);

    $revenueLines = JournalLine::where('journal_entry_id', $invoice->journal_entry_id)
        ->where('account_id', $this->income->id)
        ->get();

    expect($revenueLines)->toHaveCount(1);
    expect($revenueLines->first()->credit_cents)->toBe(10000);
    expect($revenueLines->first()->class_id)->toBeNull();
});

it('copies dimensions onto void reversal lines', function () {
    $invoice = postInvoiceWithLines($this->customer, [[
        'account_id' => $this->income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 5000,
        'line_tax_cents' => 0,
        'line_total_cents' => 5000,
        'class_id' => $this->class->id,
        'location_id' => $this->location->id,
    ]]);

    app(InvoicePoster::class)->void($invoice);

    $reversal = $invoice->journalEntry->fresh()->reversedBy;
    $reversalRevenue = $reversal->lines->firstWhere('account_id', $this->income->id);

    expect($reversalRevenue->class_id)->toBe($this->class->id);
    expect($reversalRevenue->location_id)->toBe($this->location->id);
});
