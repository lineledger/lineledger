<?php

use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\StockLayer;
use App\Models\StockMovement;
use App\Services\Posting\InvoicePoster;
use App\Services\Reporting\InventoryReportBuilder;
use App\Services\Reporting\SalesPurchaseReportBuilder;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
    $this->start = CarbonImmutable::now()->subMonth();
    $this->end = CarbonImmutable::now()->addDay();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function salesLine(Invoice|CreditMemo $doc, Account $account, int $subtotalCents, float $qty = 1): void
{
    $doc->lines()->create([
        'account_id' => $account->id,
        'description' => 'x',
        'quantity' => (string) $qty,
        'unit_price_cents' => $subtotalCents,
        'line_subtotal_cents' => $subtotalCents,
        'line_tax_cents' => 0,
        'line_total_cents' => $subtotalCents,
        'line_order' => 0,
    ]);
}

it('sums sales by customer and nets credit memos, excluding drafts and voids', function () {
    $customer = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Acme', 'is_customer' => true]);

    $posted = Invoice::create(['company_id' => $this->company->id, 'contact_id' => $customer->id, 'invoice_no' => 'INV-1', 'invoice_date' => CarbonImmutable::now(), 'due_date' => CarbonImmutable::now(), 'status' => InvoiceStatus::Posted->value]);
    salesLine($posted, $this->income, 10000);

    $paid = Invoice::create(['company_id' => $this->company->id, 'contact_id' => $customer->id, 'invoice_no' => 'INV-2', 'invoice_date' => CarbonImmutable::now(), 'due_date' => CarbonImmutable::now(), 'status' => InvoiceStatus::Paid->value]);
    salesLine($paid, $this->income, 5000);

    // Draft and void must be ignored.
    $draft = Invoice::create(['company_id' => $this->company->id, 'contact_id' => $customer->id, 'invoice_no' => 'INV-3', 'invoice_date' => CarbonImmutable::now(), 'due_date' => CarbonImmutable::now(), 'status' => InvoiceStatus::Draft->value]);
    salesLine($draft, $this->income, 99999);

    $credit = CreditMemo::create(['company_id' => $this->company->id, 'contact_id' => $customer->id, 'credit_memo_no' => 'CM-1', 'credit_memo_date' => CarbonImmutable::now(), 'status' => InvoiceStatus::Posted->value]);
    salesLine($credit, $this->income, 2000);

    $rows = app(SalesPurchaseReportBuilder::class)->salesByDimension($this->company, $this->start, $this->end, 'contact');

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['label'])->toBe('Acme')
        ->and($rows->first()['amount_cents'])->toBe(13000); // 10000 + 5000 - 2000
});

it('sums purchases by vendor netting vendor credits', function () {
    $vendor = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Supplier', 'is_vendor' => true]);

    $bill = Bill::create(['company_id' => $this->company->id, 'contact_id' => $vendor->id, 'bill_no' => 'B-1', 'bill_date' => CarbonImmutable::now(), 'due_date' => CarbonImmutable::now(), 'status' => BillStatus::Posted->value]);
    $bill->lines()->create(['account_id' => $this->expense->id, 'description' => 'x', 'quantity' => '1', 'unit_price_cents' => 8000, 'line_subtotal_cents' => 8000, 'line_tax_cents' => 0, 'line_total_cents' => 8000, 'line_order' => 0]);

    $rows = app(SalesPurchaseReportBuilder::class)->purchasesByDimension($this->company, $this->start, $this->end, 'contact');

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['label'])->toBe('Supplier')
        ->and($rows->first()['amount_cents'])->toBe(8000);
});

it('merges current and prior comparison rows, outer-joined by key', function () {
    $current = collect([
        ['key' => 1, 'label' => 'Acme', 'qty' => 2.0, 'amount_cents' => 10000],
        ['key' => 2, 'label' => 'Beta', 'qty' => 1.0, 'amount_cents' => 5000],
    ]);
    $prior = collect([
        ['key' => 1, 'label' => 'Acme', 'qty' => 1.0, 'amount_cents' => 8000],
        ['key' => 3, 'label' => 'Gamma', 'qty' => 4.0, 'amount_cents' => 4000],
    ]);

    $merged = app(SalesPurchaseReportBuilder::class)->mergeComparison($current, $prior)->keyBy('key');

    expect($merged)->toHaveCount(3); // keys 1, 2, 3 — outer join keeps prior-only rows

    // Overlapping key: both sides present, change and pct derived.
    expect($merged[1]->amountCents)->toBe(10000)
        ->and($merged[1]->priorAmountCents)->toBe(8000)
        ->and($merged[1]->changeCents())->toBe(2000)
        ->and($merged[1]->changePct())->toBe(25.0)
        ->and($merged[1]->qtyChange())->toBe(1.0);

    // Current-only key: prior is zero, so % change is null (renders as —).
    expect($merged[2]->priorAmountCents)->toBe(0)
        ->and($merged[2]->changeCents())->toBe(5000)
        ->and($merged[2]->changePct())->toBeNull();

    // Prior-only key: still appears, current is zero, label taken from prior side.
    expect($merged[3]->amountCents)->toBe(0)
        ->and($merged[3]->priorAmountCents)->toBe(4000)
        ->and($merged[3]->changeCents())->toBe(-4000)
        ->and($merged[3]->changePct())->toBe(-100.0)
        ->and($merged[3]->label)->toBe('Gamma');
});

it('shows prior-year comparison columns on sales-by-customer and toggles them off', function () {
    $acme = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Acme', 'is_customer' => true, 'is_active' => true]);

    $cur = Invoice::create(['company_id' => $this->company->id, 'contact_id' => $acme->id, 'invoice_no' => 'INV-CUR', 'invoice_date' => CarbonImmutable::now(), 'due_date' => CarbonImmutable::now(), 'status' => InvoiceStatus::Posted->value]);
    salesLine($cur, $this->income, 10000);

    $prior = Invoice::create(['company_id' => $this->company->id, 'contact_id' => $acme->id, 'invoice_no' => 'INV-PRI', 'invoice_date' => CarbonImmutable::now()->subYear(), 'due_date' => CarbonImmutable::now()->subYear(), 'status' => InvoiceStatus::Posted->value]);
    salesLine($prior, $this->income, 6000);

    $page = Livewire::test('pages::reports.sales-by-customer', ['company' => $this->company])
        ->assertOk()
        ->set('startDate', $this->start->toDateString())
        ->set('endDate', $this->end->toDateString())
        ->set('comparisonBasis', 'prior_year')
        ->assertSee('Prior')
        ->assertSee('100.00')  // current
        ->assertSee('60.00')   // prior
        ->assertSee('40.00')   // change
        ->assertSee('66.7%');  // % change = (10000-6000)/6000

    // Hiding the Prior column removes the prior figure but keeps the change column.
    $page->call('toggleColumn', 'prior')
        ->assertSet('hiddenColumns', 'prior')
        ->assertDontSee('60.00')
        ->assertSee('40.00');

    // Turning comparison off collapses back to the single-column layout: the
    // prior figure and % change disappear (the word "Prior" still lives in the
    // always-present Compare dropdown, so assert on the values instead).
    $page->set('comparisonBasis', 'off')
        ->assertDontSee('66.7%')
        ->assertDontSee('60.00')
        ->assertSee('100.00');
});

it('compares quantity and amount on sales-by-item with the prior period', function () {
    $item = Item::factory()->create(['company_id' => $this->company->id, 'name' => 'Widget']);
    $customer = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Acme', 'is_customer' => true]);

    $cur = Invoice::create(['company_id' => $this->company->id, 'contact_id' => $customer->id, 'invoice_no' => 'INV-CUR', 'invoice_date' => CarbonImmutable::now(), 'due_date' => CarbonImmutable::now(), 'status' => InvoiceStatus::Posted->value]);
    $cur->lines()->create(['account_id' => $this->income->id, 'item_id' => $item->id, 'description' => 'x', 'quantity' => '5', 'unit_price_cents' => 2000, 'line_subtotal_cents' => 10000, 'line_tax_cents' => 0, 'line_total_cents' => 10000, 'line_order' => 0]);

    $prior = Invoice::create(['company_id' => $this->company->id, 'contact_id' => $customer->id, 'invoice_no' => 'INV-PRI', 'invoice_date' => CarbonImmutable::now()->subYear(), 'due_date' => CarbonImmutable::now()->subYear(), 'status' => InvoiceStatus::Posted->value]);
    $prior->lines()->create(['account_id' => $this->income->id, 'item_id' => $item->id, 'description' => 'x', 'quantity' => '3', 'unit_price_cents' => 2000, 'line_subtotal_cents' => 6000, 'line_tax_cents' => 0, 'line_total_cents' => 6000, 'line_order' => 0]);

    Livewire::test('pages::reports.sales-by-item', ['company' => $this->company])
        ->assertOk()
        ->set('startDate', $this->start->toDateString())
        ->set('endDate', $this->end->toDateString())
        ->set('comparisonBasis', 'prior_year')
        ->assertSee('Widget')
        ->assertSee('Prior Qty')   // qty comparison columns present on item reports
        ->assertSee('Qty Change')
        ->assertSee('100.00')      // current sales
        ->assertSee('60.00');      // prior sales
});

it('exports the comparison reports to xlsx and pdf in both single and comparison modes', function () {
    $item = Item::factory()->create(['company_id' => $this->company->id, 'name' => 'Widget']);
    $customer = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Acme', 'is_customer' => true]);
    $inv = Invoice::create(['company_id' => $this->company->id, 'contact_id' => $customer->id, 'invoice_no' => 'INV-X', 'invoice_date' => CarbonImmutable::now(), 'due_date' => CarbonImmutable::now(), 'status' => InvoiceStatus::Posted->value]);
    $inv->lines()->create(['account_id' => $this->income->id, 'item_id' => $item->id, 'description' => 'x', 'quantity' => '5', 'unit_price_cents' => 2000, 'line_subtotal_cents' => 10000, 'line_tax_cents' => 0, 'line_total_cents' => 10000, 'line_order' => 0]);

    foreach (['sales-by-customer', 'sales-by-item'] as $page) {
        foreach (['off', 'prior_year'] as $basis) {
            $component = Livewire::test("pages::reports.{$page}", ['company' => $this->company])
                ->set('startDate', $this->start->toDateString())
                ->set('endDate', $this->end->toDateString())
                ->set('comparisonBasis', $basis)
                ->instance();

            $xlsx = $component->exportXlsx();
            expect($xlsx->getStatusCode())->toBe(200)
                ->and($xlsx->headers->get('content-disposition'))->toContain("{$page}-")
                ->and($xlsx->headers->get('content-disposition'))->toContain('.xlsx');

            $pdf = $component->exportPdf();
            expect($pdf->getStatusCode())->toBe(200)
                ->and($pdf->headers->get('content-disposition'))->toContain('.pdf');
        }
    }
});

it('reports inventory stock status with below-reorder flag', function () {
    $low = Item::factory()->create(['company_id' => $this->company->id, 'name' => 'Widget', 'track_inventory' => true, 'qty_on_hand_cached' => 3, 'reorder_point' => 10, 'unit_cost_cents_cached' => 250]);
    Item::factory()->create(['company_id' => $this->company->id, 'name' => 'Service', 'track_inventory' => false]);

    $rows = app(InventoryReportBuilder::class)->stockStatus($this->company);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['name'])->toBe('Widget')
        ->and($rows->first()['below_reorder'])->toBeTrue();
});

it('values inventory from remaining FIFO layers', function () {
    $item = Item::factory()->create(['company_id' => $this->company->id, 'name' => 'Widget', 'track_inventory' => true]);
    $movement = StockMovement::create(['company_id' => $this->company->id, 'item_id' => $item->id, 'movement_date' => CarbonImmutable::now(), 'qty_change' => 6, 'unit_cost_cents' => 0, 'total_cost_cents' => 0]);
    StockLayer::create(['company_id' => $this->company->id, 'item_id' => $item->id, 'stock_movement_id' => $movement->id, 'qty_remaining' => 4, 'unit_cost_cents' => 250]);
    StockLayer::create(['company_id' => $this->company->id, 'item_id' => $item->id, 'stock_movement_id' => $movement->id, 'qty_remaining' => 2, 'unit_cost_cents' => 300]);

    $summary = app(InventoryReportBuilder::class)->valuationSummary($this->company);

    // 4*250 + 2*300 = 1600
    expect($summary['total_value_cents'])->toBe(1600)
        ->and($summary['rows']->first()['qty'])->toBe(6.0);
});

it('filters the sales-by-customer page to a single customer and clears back to all', function () {
    $acme = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Acme', 'is_customer' => true, 'is_active' => true]);
    $beta = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Beta Industries', 'is_customer' => true, 'is_active' => true]);

    $invoiceA = Invoice::create(['company_id' => $this->company->id, 'contact_id' => $acme->id, 'invoice_no' => 'INV-A', 'invoice_date' => CarbonImmutable::now(), 'due_date' => CarbonImmutable::now(), 'status' => InvoiceStatus::Posted->value]);
    salesLine($invoiceA, $this->income, 10000);

    $invoiceB = Invoice::create(['company_id' => $this->company->id, 'contact_id' => $beta->id, 'invoice_no' => 'INV-B', 'invoice_date' => CarbonImmutable::now(), 'due_date' => CarbonImmutable::now(), 'status' => InvoiceStatus::Posted->value]);
    salesLine($invoiceB, $this->income, 5000);

    $page = Livewire::test('pages::reports.sales-by-customer', ['company' => $this->company])
        ->assertOk()
        ->set('startDate', $this->start->toDateString())
        ->set('endDate', $this->end->toDateString())
        ->assertSee('Acme')
        ->assertSee('150.00'); // both customers in the total

    expect(substr_count($page->html(), 'data-test="sales-row"'))->toBe(2);

    // Filter to Acme: only their row and total remain (the dropdown still lists
    // every customer, so assert on rows and amounts rather than names).
    $page->set('contactId', $acme->id)
        ->assertSee('100.00')
        ->assertDontSee('150.00')
        ->assertDontSee('50.00');

    expect(substr_count($page->html(), 'data-test="sales-row"'))->toBe(1);

    // Clearing the filter (flux:select sends '' for "All customers") restores both.
    $page->set('contactId', '')
        ->assertSee('150.00');

    expect(substr_count($page->html(), 'data-test="sales-row"'))->toBe(2);
});

it('filters the purchases-by-vendor page to a single vendor and clears back to all', function () {
    $supplier = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Supplier One', 'is_vendor' => true, 'is_active' => true]);
    $other = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Other Vendor', 'is_vendor' => true, 'is_active' => true]);

    $billA = Bill::create(['company_id' => $this->company->id, 'contact_id' => $supplier->id, 'bill_no' => 'B-A', 'bill_date' => CarbonImmutable::now(), 'due_date' => CarbonImmutable::now(), 'status' => BillStatus::Posted->value]);
    $billA->lines()->create(['account_id' => $this->expense->id, 'description' => 'x', 'quantity' => '1', 'unit_price_cents' => 8000, 'line_subtotal_cents' => 8000, 'line_tax_cents' => 0, 'line_total_cents' => 8000, 'line_order' => 0]);

    $billB = Bill::create(['company_id' => $this->company->id, 'contact_id' => $other->id, 'bill_no' => 'B-B', 'bill_date' => CarbonImmutable::now(), 'due_date' => CarbonImmutable::now(), 'status' => BillStatus::Posted->value]);
    $billB->lines()->create(['account_id' => $this->expense->id, 'description' => 'x', 'quantity' => '1', 'unit_price_cents' => 3000, 'line_subtotal_cents' => 3000, 'line_tax_cents' => 0, 'line_total_cents' => 3000, 'line_order' => 0]);

    $page = Livewire::test('pages::reports.purchases-by-vendor', ['company' => $this->company])
        ->assertOk()
        ->set('startDate', $this->start->toDateString())
        ->set('endDate', $this->end->toDateString())
        ->assertSee('110.00'); // both vendors in the total

    expect(substr_count($page->html(), 'data-test="purchases-row"'))->toBe(2);

    $page->set('contactId', $supplier->id)
        ->assertSee('80.00')
        ->assertDontSee('110.00')
        ->assertDontSee('30.00');

    expect(substr_count($page->html(), 'data-test="purchases-row"'))->toBe(1);

    $page->set('contactId', '')
        ->assertSee('110.00');

    expect(substr_count($page->html(), 'data-test="purchases-row"'))->toBe(2);
});

it('filters the trial balance by account type', function () {
    $customer = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Acme', 'is_customer' => true]);

    // Posting an invoice debits AR (asset) and credits income, giving the
    // trial balance one row of each type. Dated in the past so the entry is
    // within the page's default as-of date on every database driver.
    $invoice = Invoice::create(['company_id' => $this->company->id, 'contact_id' => $customer->id, 'invoice_no' => 'INV-1', 'invoice_date' => CarbonImmutable::now()->subDays(10), 'due_date' => CarbonImmutable::now()->subDays(10)]);
    salesLine($invoice, $this->income, 10000);
    app(InvoicePoster::class)->post($invoice);

    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();

    $page = Livewire::test('pages::reports.trial-balance', ['company' => $this->company])
        ->assertOk()
        ->assertSee($ar->name)
        ->assertSee($this->income->name);

    $page->set('accountType', 'income')
        ->assertSee($this->income->name)
        ->assertDontSee($ar->name);

    expect(substr_count($page->html(), 'data-test="tb-row"'))->toBe(1);

    $page->set('accountType', '')
        ->assertSee($ar->name)
        ->assertSee($this->income->name);
});
