<?php

use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\User;
use Livewire\Livewire;

/*
 * The status tabs on the order lists. Cancelled is a stored status; open, partial and
 * closed are worked out from the documents made from the order, and a voided document
 * doesn't count.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->contact = Contact::create(['display_name' => 'Acme', 'is_customer' => true, 'is_vendor' => true]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('sorts purchase orders into their tabs', function () {
    $order = fn (string $no, PurchaseOrderStatus $status) => PurchaseOrder::create([
        'contact_id' => $this->contact->id, 'po_no' => $no, 'po_date' => '2026-05-01', 'status' => $status,
    ]);

    $open = $order('PO-OPEN', PurchaseOrderStatus::Open);
    $cancelled = $order('PO-CANCELLED', PurchaseOrderStatus::Cancelled);
    $voidedBill = $order('PO-VOIDED-BILL', PurchaseOrderStatus::Open);

    Bill::create([
        'contact_id' => $this->contact->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'B-VOID',
        'bill_date' => '2026-05-02',
        'due_date' => '2026-05-30',
        'status' => BillStatus::Void,
        'purchase_order_id' => $voidedBill->id,
    ]);

    $tab = fn (string $filter) => Livewire::test('pages::purchase-orders.index', ['company' => $this->company])
        ->set('statusFilter', $filter)
        ->instance()->purchaseOrders()->getCollection()->pluck('po_no')->sort()->values()->all();

    expect($tab('cancelled'))->toBe([$cancelled->po_no])
        ->and($tab('open'))->toBe([$open->po_no, $voidedBill->po_no])
        ->and($tab('closed'))->toBe([])
        ->and($tab('all'))->toHaveCount(3);
});

it('sorts sales orders into their tabs', function () {
    $order = fn (string $no, SalesOrderStatus $status) => SalesOrder::create([
        'contact_id' => $this->contact->id, 'order_no' => $no, 'order_date' => '2026-05-01', 'status' => $status,
    ]);

    $open = $order('SO-OPEN', SalesOrderStatus::Open);
    $cancelled = $order('SO-CANCELLED', SalesOrderStatus::Cancelled);
    $voidedInvoice = $order('SO-VOIDED-INVOICE', SalesOrderStatus::Open);

    Invoice::create([
        'contact_id' => $this->contact->id,
        'invoice_no' => 'I-VOID',
        'invoice_date' => '2026-05-02',
        'due_date' => '2026-05-30',
        'status' => InvoiceStatus::Void,
        'sales_order_id' => $voidedInvoice->id,
    ]);

    $tab = fn (string $filter) => Livewire::test('pages::sales-orders.index', ['company' => $this->company])
        ->set('statusFilter', $filter)
        ->instance()->salesOrders()->getCollection()->pluck('order_no')->sort()->values()->all();

    expect($tab('cancelled'))->toBe([$cancelled->order_no])
        ->and($tab('open'))->toBe([$open->order_no, $voidedInvoice->order_no])
        ->and($tab('closed'))->toBe([])
        ->and($tab('all'))->toHaveCount(3);
});
