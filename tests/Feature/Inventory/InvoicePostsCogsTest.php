<?php

use App\Enums\AccountSubtype;
use App\Enums\InvoiceStatus;
use App\Enums\StockAdjustmentReason;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\StockAdjustmentPoster;

beforeEach(function () {
    $this->company = Company::factory()->create(['costing_method' => 'weighted_average']);
    app()->instance('current_company', $this->company);

    $this->customer = Contact::create(['display_name' => 'Widget Buyer', 'is_customer' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
    $this->inventoryAsset = Account::query()->where('subtype', AccountSubtype::Inventory->value)->first();
    $this->cogs = Account::query()->where('subtype', AccountSubtype::CostOfGoodsSold->value)->first();
    $this->ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();

    $this->item = Item::factory()->tracked()->create([
        'income_account_id' => $this->income->id,
        'inventory_asset_account_id' => $this->inventoryAsset->id,
        'cogs_account_id' => $this->cogs->id,
    ]);

    $this->invoicePoster = app(InvoicePoster::class);
    $this->adjPoster = app(StockAdjustmentPoster::class);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function seedStock(Item $item, string $qty, int $unitCostCents): StockAdjustment
{
    $adj = StockAdjustment::create([
        'adjustment_no' => 'ADJ-'.uniqid(),
        'adjustment_date' => now()->toDateString(),
        'reason' => StockAdjustmentReason::OpeningBalance,
    ]);
    $adj->lines()->create([
        'item_id' => $item->id,
        'qty_change' => $qty,
        'unit_cost_cents' => $unitCostCents,
        'line_order' => 0,
    ]);
    test()->adjPoster->post($adj->fresh('lines.item'));

    return $adj;
}

function makeInvoiceForItem(Item $item, string $qty, int $unitPriceCents): Invoice
{
    $invoice = Invoice::create([
        'contact_id' => test()->customer->id,
        'invoice_no' => 'INV-'.uniqid(),
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);
    $invoice->lines()->create([
        'item_id' => $item->id,
        'account_id' => test()->income->id,
        'description' => 'sale',
        'quantity' => $qty,
        'unit_price_cents' => $unitPriceCents,
        'line_subtotal_cents' => (int) round((float) $qty * $unitPriceCents),
        'line_tax_cents' => 0,
        'line_total_cents' => (int) round((float) $qty * $unitPriceCents),
        'line_order' => 0,
    ]);

    return $invoice->fresh('lines.item');
}

it('posts COGS lines alongside the income/AR lines on a single balanced entry', function () {
    seedStock($this->item, '100', 500);

    $invoice = makeInvoiceForItem($this->item, '20', 1000);
    $entry = $this->invoicePoster->post($invoice);
    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Posted);
    expect($entry->isBalanced())->toBeTrue();

    // DR AR 20000 / CR Income 20000 / DR COGS 10000 / CR Inventory 10000
    $arLine = $entry->lines->firstWhere('account_id', $this->ar->id);
    $incomeLine = $entry->lines->firstWhere('account_id', $this->income->id);
    $cogsLine = $entry->lines->firstWhere('account_id', $this->cogs->id);
    $invLine = $entry->lines->firstWhere('account_id', $this->inventoryAsset->id);

    expect($arLine->debit_cents)->toBe(20000);
    expect($incomeLine->credit_cents)->toBe(20000);
    expect($cogsLine->debit_cents)->toBe(10000);
    expect($invLine->credit_cents)->toBe(10000);

    $this->item->refresh();
    expect((float) $this->item->qty_on_hand_cached)->toBe(80.0);
});

it('does not post COGS for non-tracked items', function () {
    $service = Item::factory()->create(['income_account_id' => $this->income->id]);

    $invoice = makeInvoiceForItem($service, '1', 5000);
    $entry = $this->invoicePoster->post($invoice);

    expect($entry->lines->firstWhere('account_id', $this->cogs->id))->toBeNull();
    expect($entry->lines->firstWhere('account_id', $this->inventoryAsset->id))->toBeNull();
});

it('throws InsufficientStockException and does not create a JE if stock is insufficient', function () {
    seedStock($this->item, '5', 500);
    $invoice = makeInvoiceForItem($this->item, '10', 1000);

    $jeCountBefore = JournalEntry::query()->count();
    $movementsBefore = StockMovement::query()->count();

    expect(fn () => $this->invoicePoster->post($invoice))
        ->toThrow(InsufficientStockException::class);

    expect(JournalEntry::query()->count())->toBe($jeCountBefore);
    expect(StockMovement::query()->count())->toBe($movementsBefore);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);

    $this->item->refresh();
    expect((float) $this->item->qty_on_hand_cached)->toBe(5.0);
});

it('voiding an invoice reverses its stock movements', function () {
    seedStock($this->item, '50', 400);

    $invoice = makeInvoiceForItem($this->item, '10', 1000);
    $this->invoicePoster->post($invoice);

    $this->item->refresh();
    expect((float) $this->item->qty_on_hand_cached)->toBe(40.0);

    $this->invoicePoster->void($invoice->fresh());

    $this->item->refresh();
    expect((float) $this->item->qty_on_hand_cached)->toBe(50.0);
});

it('reposting an invoice reverses old stock issues then re-issues at current cost', function () {
    seedStock($this->item, '100', 500);

    $invoice = makeInvoiceForItem($this->item, '20', 1000);
    $this->invoicePoster->post($invoice);

    $this->item->refresh();
    expect((float) $this->item->qty_on_hand_cached)->toBe(80.0);

    // Edit the invoice — change to 30 units.
    $invoice->lines()->first()->update([
        'quantity' => '30',
        'line_subtotal_cents' => 30000,
        'line_total_cents' => 30000,
    ]);

    $this->invoicePoster->repost($invoice->fresh('lines.item'));

    $this->item->refresh();
    expect((float) $this->item->qty_on_hand_cached)->toBe(70.0);
});
