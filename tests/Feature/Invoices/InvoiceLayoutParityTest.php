<?php

use App\Actions\Sales\SaveFormStyle;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\InvoiceLayout;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\PaymentTerm;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use App\Services\Reporting\InvoicePdfRenderer;
use Carbon\CarbonImmutable;

/*
 * Every invoice layout has to print everything that is on the invoice. One
 * invoice with every optional part filled in is rendered in each layout, so a
 * new layout — or a new invoice field added to only one of them — fails here.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create([
        'address_region' => 'QC',
        'tax_number' => '123456789 RT0001',
    ]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->company->provincialTaxAgency()?->update(['registration_number' => '1234567890 TQ 0001']);
    $this->company->invoiceSettingsOrNew()->forceFill([
        'company_id' => $this->company->id,
        'show_service_date_column' => true,
        'footer_message' => "Thank you.\nLate payments incur a 2% fee.",
    ])->save();

    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
    $gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
    $qst = TaxCode::query()->where('code', 'QST-QC')->firstOrFail();
    $item = Item::create(['name' => 'Consulting', 'type' => 'service', 'income_account_id' => $income->id]);
    $terms = PaymentTerm::create(['name' => 'Net 30', 'days' => 30, 'is_active' => true]);
    $rep = Contact::create(['display_name' => 'Pat Rep', 'is_employee' => true]);

    $customer = Contact::create([
        'display_name' => 'Harbour Supply Co.',
        'is_customer' => true,
        'locale' => 'en',
        'billing_line1' => '200 Peel St',
        'billing_city' => 'Montreal',
        'billing_region' => 'QC',
        'billing_postal_code' => 'H3A 1T9',
        'shipping_line1' => '99 Dock Rd',
        'shipping_city' => 'Laval',
        'shipping_region' => 'QC',
        'shipping_postal_code' => 'H7N 1A1',
    ]);

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'PARITY-1',
        'invoice_date' => CarbonImmutable::create(2026, 5, 24),
        'due_date' => CarbonImmutable::create(2026, 6, 23),
        'terms_id' => $terms->id,
        'sales_rep_id' => $rep->id,
        'customer_po' => 'PO-7781',
        'ship_date' => CarbonImmutable::create(2026, 5, 25),
        'ship_via' => 'Purolator',
        'fob' => 'Origin',
        'tracking_no' => 'TRK-55821',
        'customer_message' => 'We appreciate your business.',
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id, 'item_id' => $item->id, 'tax_code_id' => $gst->id, 'secondary_tax_code_id' => $qst->id,
        'description' => 'Strategy workshop', 'quantity' => '2', 'unit_price_cents' => 50000,
        'line_subtotal_cents' => 100000, 'line_tax_cents' => 5000, 'secondary_tax_cents' => 9975,
        'line_total_cents' => 114975, 'line_order' => 0, 'service_date' => CarbonImmutable::create(2026, 5, 20),
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id, 'tax_code_id' => $gst->id, 'description' => 'Travel', 'quantity' => '1',
        'unit_price_cents' => 12500, 'line_discount_cents' => 2500, 'line_subtotal_cents' => 10000,
        'line_tax_cents' => 500, 'line_total_cents' => 10500, 'line_order' => 1,
    ]);
    app(InvoicePoster::class)->post($invoice);

    $invoice->paymentRequests()->create([
        'label' => 'Deposit instalment', 'request_type' => 'fixed', 'amount_cents' => 50000, 'due_date' => '2026-06-01', 'sort_order' => 0,
    ]);
    $invoice->forceFill(['amount_paid_cents' => 20000])->save();

    $this->invoice = $invoice->fresh();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('prints every part of the invoice in the :dataset layout', function (InvoiceLayout $layout) {
    app(SaveFormStyle::class)->handle(['name' => 'Parity', 'layout' => $layout, 'is_default' => true, 'is_active' => true]);
    $money = fn (int $cents) => number_format($cents / 100, 2);

    $html = app(InvoicePdfRenderer::class)->html($this->company->fresh(), $this->invoice);

    expect($html)
        // Header: number, customer, both addresses and every header field.
        ->toContain('PARITY-1')
        ->toContain('Harbour Supply Co.')
        ->toContain('200 Peel St')
        ->toContain('99 Dock Rd')
        ->toContain('Net 30')
        ->toContain('PO-7781')
        ->toContain('Pat Rep')
        ->toContain('Purolator')
        ->toContain('Origin')
        ->toContain('TRK-55821')
        // Lines: item, description, service date and the line discount.
        ->toContain('Consulting')
        ->toContain('Strategy workshop')
        ->toContain('Service date')
        ->toContain('less 25.00 disc')
        // Totals: each tax, the total, the payment and the balance.
        ->toContain($money(5500))
        ->toContain($money(9975))
        ->toContain($money($this->invoice->total_cents))
        ->toContain($money(20000))
        ->toContain($money($this->invoice->balanceCents()))
        // The payment schedule, both registration numbers and both messages.
        ->toContain('Deposit instalment')
        ->toContain('123456789 RT0001')
        ->toContain('1234567890 TQ 0001')
        ->toContain('We appreciate your business.')
        ->toContain('Late payments incur a 2% fee.');
})->with(fn () => collect(InvoiceLayout::cases())->mapWithKeys(fn (InvoiceLayout $l) => [$l->value => [$l]])->all());

it('renders every layout to a PDF', function (InvoiceLayout $layout) {
    app(SaveFormStyle::class)->handle(['name' => 'Parity', 'layout' => $layout, 'is_default' => true, 'is_active' => true]);

    expect(substr(app(InvoicePdfRenderer::class)->raw($this->company->fresh(), $this->invoice), 0, 4))->toBe('%PDF');
})->with(fn () => collect(InvoiceLayout::cases())->mapWithKeys(fn (InvoiceLayout $l) => [$l->value => [$l]])->all());
