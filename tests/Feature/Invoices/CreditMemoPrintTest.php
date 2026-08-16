<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\InvoiceSetting;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\Posting\CreditMemoPoster;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
    $this->gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function makePostedCreditMemo(object $test): CreditMemo
{
    $customer = Contact::create([
        'display_name' => 'Chavez, Isaac Jr.',
        'is_customer' => true,
        'billing_line1' => '123 Main St',
        'billing_city' => 'Victoria',
        'billing_region' => 'BC',
        'billing_postal_code' => 'V8V 1A1',
    ]);

    $memo = CreditMemo::create([
        'contact_id' => $customer->id,
        'credit_memo_no' => 'CM-PRINT-1',
        'credit_memo_date' => CarbonImmutable::create(2026, 5, 24),
    ]);

    $memo->lines()->create([
        'account_id' => $test->income->id,
        'tax_code_id' => $test->gst->id,
        'description' => 'Embalming services',
        'quantity' => '1',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 5000,
        'line_tax_cents' => 250,
        'line_total_cents' => 5250,
        'line_order' => 0,
    ]);

    app(CreditMemoPoster::class)->post($memo);

    return $memo->fresh();
}

it('returns the credit memo as an inline PDF', function () {
    $memo = makePostedCreditMemo($this);

    $response = $this->get(route('credit-memos.print', [
        'company' => $this->company->slug,
        'credit_memo' => $memo->id,
    ]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline');
});

it('404s when the credit memo belongs to another company', function () {
    $otherCompany = Company::factory()->create();
    app()->instance('current_company', $otherCompany);
    $foreignMemo = makePostedCreditMemo((object) ['income' => Account::query()->where('company_id', $otherCompany->id)->where('subtype', AccountSubtype::Income->value)->first(), 'gst' => TaxCode::query()->where('company_id', $otherCompany->id)->where('code', 'GST')->firstOrFail()]);
    app()->instance('current_company', $this->company);

    $this->get(route('credit-memos.print', [
        'company' => $this->company->slug,
        'credit_memo' => $foreignMemo->id,
    ]))->assertNotFound();
});

it('hides the service date line when the service date column is toggled off', function () {
    $memo = makePostedCreditMemo($this);
    $memo->lines()->update(['service_date' => CarbonImmutable::create(2026, 5, 20)]);
    $memo = $memo->fresh()->load('lines.taxCode', 'lines.item', 'contact');

    $render = fn (bool $show) => view('pdf.credit-memos.credit-memo', [
        'company' => $this->company,
        'creditMemo' => $memo,
        'settings' => new InvoiceSetting([...InvoiceSetting::defaults(), 'company_id' => $this->company->id, 'show_service_date_column' => $show]),
        'taxSummary' => [['label' => 'GST (5%)', 'rate' => 5.0, 'tax_cents' => 250]],
        'logoData' => null,
    ])->render();

    expect($render(true))->toContain('Service date');
    expect($render(false))->not->toContain('Service date');
});

it('renders customer-facing content without GL account codes', function () {
    $memo = makePostedCreditMemo($this)->load('lines.taxCode', 'lines.item', 'contact');

    $settings = new InvoiceSetting([...InvoiceSetting::defaults(), 'company_id' => $this->company->id]);
    $settings->footer_message = 'Thank you for your business';
    $this->company->tax_number = '123456789 RT0001';

    $taxSummary = [['label' => 'GST (5%)', 'rate' => 5.0, 'tax_cents' => 250]];

    $html = view('pdf.credit-memos.credit-memo', [
        'company' => $this->company,
        'creditMemo' => $memo,
        'settings' => $settings,
        'taxSummary' => $taxSummary,
        'logoData' => null,
    ])->render();

    expect($html)
        ->toContain('CREDIT MEMO')
        ->toContain('CM-PRINT-1')
        ->toContain('Chavez, Isaac Jr.')
        ->toContain('123 Main St')
        ->toContain('GST (5%)')
        ->toContain('5.00%')
        ->toContain('GST/HST No.')
        ->toContain('123456789 RT0001')
        ->toContain('Thank you for your business')
        // Internal GL account code/name must not leak onto the customer-facing memo.
        ->not->toContain($this->income->code)
        ->not->toContain($this->income->name);
});
