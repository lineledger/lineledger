<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\InvoiceSetting;
use App\Models\Item;
use App\Models\TaxCode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function makeCreditMemoWithLine(object $test): CreditMemo
{
    $customer = Contact::create(['display_name' => 'Show Page Co', 'is_customer' => true]);
    $item = Item::create(['company_id' => $test->company->id, 'name' => 'Widget Sentinel']);
    $gst = TaxCode::query()->where('company_id', $test->company->id)->where('code', 'GST')->firstOrFail();

    $memo = CreditMemo::create([
        'contact_id' => $customer->id,
        'credit_memo_no' => 'CM-SHOW-1',
        'credit_memo_date' => CarbonImmutable::create(2026, 5, 20),
    ]);

    $memo->lines()->create([
        'item_id' => $item->id,
        'account_id' => $test->income->id,
        'tax_code_id' => $gst->id,
        'description' => 'Consulting',
        'service_date' => CarbonImmutable::create(2026, 5, 20),
        'quantity' => '9',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 45000,
        'line_tax_cents' => 2250,
        'line_total_cents' => 47250,
        'line_order' => 0,
    ]);

    return $memo->fresh();
}

it('shows the essential line columns but hides tax and service date by default', function () {
    Livewire::test('pages::credit-memos.form', ['company' => $this->company])
        ->assertSeeHtml('data-test="line-item"')
        ->assertSeeHtml('data-test="line-qty"')
        ->assertSeeHtml('data-test="line-account"')
        // Shared with invoices — hidden by default on a new company.
        ->assertDontSeeHtml('data-test="line-tax"')
        ->assertDontSeeHtml('data-test="line-service-date"');
});

it('hides line columns on the credit memo form when toggled off', function () {
    Livewire::test('pages::credit-memos.form', ['company' => $this->company])
        ->set('fieldVisibility.item_column', false)
        ->set('fieldVisibility.qty_column', false)
        ->set('fieldVisibility.tax_column', false)
        ->assertDontSeeHtml('data-test="line-item"')
        ->assertDontSeeHtml('data-test="line-qty"')
        ->assertDontSeeHtml('data-test="line-tax"')
        // Untoggled columns remain.
        ->assertSeeHtml('data-test="line-unit-price"');
});

it('persists credit memo column choices to the shared InvoiceSetting', function () {
    Livewire::test('pages::credit-memos.form', ['company' => $this->company])
        ->set('fieldVisibility.item_column', false)
        ->set('fieldVisibility.service_date_column', false);

    $this->assertDatabaseHas('invoice_settings', [
        'company_id' => $this->company->id,
        'show_item_column' => false,
        'show_service_date_column' => false,
        'show_qty_column' => true,
    ]);
});

it('reloads the saved layout on a fresh credit memo form', function () {
    InvoiceSetting::updateOrCreate(
        ['company_id' => $this->company->id],
        [...InvoiceSetting::defaults(), 'show_tax_column' => false],
    );

    Livewire::test('pages::credit-memos.form', ['company' => $this->company])
        ->assertSet('fieldVisibility.tax_column', false)
        ->assertDontSeeHtml('data-test="line-tax"');
});

it('shows the essential line columns but hides tax and service date on the detail page by default', function () {
    $memo = makeCreditMemoWithLine($this);

    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $memo])
        ->assertSeeHtml('<th class="px-4 py-2 text-left">Item</th>')
        ->assertSeeHtml('Widget Sentinel')
        ->assertSeeHtml('<th class="px-4 py-2 text-right">Qty</th>')
        // Hidden by default (shared invoice/credit-memo setting).
        ->assertDontSeeHtml('<th class="px-4 py-2 text-left">Tax</th>')
        ->assertDontSeeHtml('Service date: 2026-05-20');
});

it('hides line columns on the credit memo detail page when toggled off', function () {
    InvoiceSetting::updateOrCreate(
        ['company_id' => $this->company->id],
        [...InvoiceSetting::defaults(), 'show_item_column' => false, 'show_qty_column' => false, 'show_tax_column' => false, 'show_service_date_column' => false],
    );

    $memo = makeCreditMemoWithLine($this);

    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $memo])
        ->assertDontSeeHtml('<th class="px-4 py-2 text-left">Item</th>')
        ->assertDontSeeHtml('Widget Sentinel')
        ->assertDontSeeHtml('<th class="px-4 py-2 text-right">Qty</th>')
        ->assertDontSeeHtml('<th class="px-4 py-2 text-left">Tax</th>')
        ->assertDontSeeHtml('Service date: 2026-05-20');
});

it('persists a column toggle from the credit memo detail page', function () {
    $memo = makeCreditMemoWithLine($this);

    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $memo])
        ->set('fieldVisibility.qty_column', false);

    $this->assertDatabaseHas('invoice_settings', [
        'company_id' => $this->company->id,
        'show_qty_column' => false,
        'show_item_column' => true,
    ]);
});
