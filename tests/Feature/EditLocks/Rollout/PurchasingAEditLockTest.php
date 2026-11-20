<?php

use App\Actions\Purchasing\SaveBill;
use App\Actions\Purchasing\SaveBillPayment;
use App\Actions\Purchasing\SaveExpense;
use App\Actions\Purchasing\SavePurchaseOrder;
use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\CompanyRole;
use App\Enums\PurchaseOrderStatus;
use App\Livewire\Attributes\GuardsEditLock;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EditLock;
use App\Services\EditLocks\EditLockManager;
use App\Services\Posting\BillPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->jane = editLockMember($this->company, CompanyRole::Accountant);
    $this->bob = editLockMember($this->company, CompanyRole::Accountant);
    app()->instance('current_company', $this->company);

    $this->locks = app(EditLockManager::class);
    $this->vendor = Contact::query()->create(['display_name' => 'Edit Lock Vendor', 'is_vendor' => true]);
    $this->expenseAccount = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();

    $this->makeBill = fn (): Bill => app(SaveBill::class)->handle([
        'contact_id' => $this->vendor->id,
        'bill_date' => '2026-06-01',
        'due_date' => '2026-06-30',
        'memo' => 'Original memo',
        'lines' => [[
            'account_id' => $this->expenseAccount->id,
            'description' => 'Supplies',
            'quantity' => '1',
            'unit_price_cents' => 20000,
        ]],
    ]);

    // The record each edit form opens, built through the same Action the form saves with.
    $this->purchasingRecords = [
        'bill' => fn () => ($this->makeBill)(),
        'payment' => function () {
            $bill = ($this->makeBill)();
            app(BillPoster::class)->post($bill);

            return app(SaveBillPayment::class)->handle([
                'contact_id' => $this->vendor->id,
                'payment_date' => '2026-06-15',
                'paid_from_account_id' => $this->bank->id,
                'amount_cents' => 20000,
                'memo' => 'Original memo',
                'applications' => [['bill_id' => $bill->id, 'amount_cents' => 20000]],
            ]);
        },
        'expense' => fn () => app(SaveExpense::class)->handle([
            'payment_account_id' => $this->bank->id,
            'expense_date' => '2026-06-01',
            'payee_name' => 'Cloud Host',
            'memo' => 'Original memo',
            'lines' => [[
                'account_id' => $this->expenseAccount->id,
                'description' => 'Hosting',
                'amount_cents' => 8000,
            ]],
        ]),
        'purchaseOrder' => fn () => app(SavePurchaseOrder::class)->handle([
            'contact_id' => $this->vendor->id,
            'po_date' => '2026-06-01',
            'memo' => 'Original memo',
            'lines' => [[
                'account_id' => $this->expenseAccount->id,
                'description' => 'Widget',
                'quantity' => '10',
                'unit_price_cents' => 10000,
            ]],
        ]),
    ];
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

dataset('purchasing A edit-locked forms', [
    'bill' => ['pages::bills.form', 'bill', 'saveDraft'],
    'bill payment' => ['pages::bill-payments.form', 'payment', 'save'],
    'expense' => ['pages::expenses.form', 'expense', 'saveDraft'],
    'purchase order' => ['pages::purchase-orders.form', 'purchaseOrder', 'save'],
]);

dataset('purchasing A edit-locked show pages', [
    'bill' => ['pages::bills.show', 'bill', 'bill', ['confirmDelete', 'reconcile', 'void']],
    'bill payment' => ['pages::bill-payments.show', 'payment', 'bill payment', ['confirmDelete', 'void']],
    'expense' => ['pages::expenses.show', 'expense', 'expense', ['confirmDelete', 'void']],
    'purchase order' => ['pages::purchase-orders.show', 'purchaseOrder', 'purchase order', ['cancelOrder', 'confirmDelete', 'receive']],
]);

it('takes the lock when the record is opened for editing', function (string $component, string $param, string $saveMethod) {
    $record = ($this->purchasingRecords[$param])();

    $this->actingAs($this->jane);
    $form = Livewire::test($component, ['company' => $this->company, $param => $record])
        ->assertSet('editLockBlocked', false);

    expect($form->get('editLockToken'))->toHaveLength(40)
        ->and(EditLock::query()->sole()->user_id)->toBe($this->jane->id);
})->with('purchasing A edit-locked forms');

it('shows a second member who is editing instead of the form, and refuses a crafted save', function (string $component, string $param, string $saveMethod) {
    $record = ($this->purchasingRecords[$param])();

    $this->actingAs($this->jane);
    Livewire::test($component, ['company' => $this->company, $param => $record]);

    $this->actingAs($this->bob);
    Livewire::test($component, ['company' => $this->company, $param => $record])
        ->assertSet('editLockBlocked', true)
        ->assertSee($this->jane->name)
        ->set('memo', 'Bob was here')
        ->call($saveMethod)
        ->assertDispatched('toast-show');

    expect($record->fresh()->memo)->toBe('Original memo')
        ->and(EditLock::query()->sole()->user_id)->toBe($this->jane->id);
})->with('purchasing A edit-locked forms');

it('lets the member holding the lock save', function (string $component, string $param, string $saveMethod) {
    $record = ($this->purchasingRecords[$param])();

    $this->actingAs($this->jane);
    Livewire::test($component, ['company' => $this->company, $param => $record])
        ->set('memo', 'Jane was here')
        ->call($saveMethod)
        ->assertHasNoErrors();

    expect($record->fresh()->memo)->toBe('Jane was here');
})->with('purchasing A edit-locked forms');

it('refuses to void a bill from its show page while another member is editing it', function () {
    $bill = ($this->makeBill)();
    app(BillPoster::class)->post($bill);
    $this->locks->acquire($bill, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::bills.show', ['company' => $this->company, 'bill' => $bill->fresh()])
        ->assertSee(__(':name is editing this :noun', ['name' => $this->jane->name, 'noun' => 'bill']))
        ->call('void')
        ->assertDispatched('toast-show')
        ->assertNoRedirect();

    expect($bill->fresh()->status)->not->toBe(BillStatus::Void);
});

it('refuses to cancel or receive a purchase order while another member is editing it', function () {
    $order = ($this->purchasingRecords['purchaseOrder'])();
    $this->locks->acquire($order, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::purchase-orders.show', ['company' => $this->company, 'purchaseOrder' => $order])
        ->assertSee(__(':name is editing this :noun', ['name' => $this->jane->name, 'noun' => 'purchase order']))
        ->call('cancelOrder')
        ->assertDispatched('toast-show')
        ->call('startReceive')
        ->call('receive')
        ->assertNoRedirect();

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Open)
        ->and(Bill::query()->where('purchase_order_id', $order->id)->exists())->toBeFalse();
});

it('cancels a purchase order normally when nobody is editing, replacing the version', function () {
    $order = ($this->purchasingRecords['purchaseOrder'])();
    $mine = $this->locks->acquire($order, $this->jane);
    $this->locks->release($mine->token, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::purchase-orders.show', ['company' => $this->company, 'purchaseOrder' => $order])
        ->call('cancelOrder');

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled)
        ->and($this->locks->currentVersion($order))->not->toBe($mine->version);
});

it('tells a viewer of the show page who is editing the record', function (string $component, string $param, string $noun) {
    $record = ($this->purchasingRecords[$param])();
    $this->locks->acquire($record, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test($component, ['company' => $this->company, $param => $record->fresh()])
        ->assertSee(__(':name is editing this :noun', ['name' => $this->jane->name, 'noun' => $noun]));
})->with('purchasing A edit-locked show pages');

it('guards exactly the show page methods that change the record', function (string $component, string $param, string $noun, array $methods) {
    $class = app('livewire.factory')->resolveComponentClass($component);

    $guarded = collect((new ReflectionClass($class))->getMethods())
        ->filter(fn (ReflectionMethod $m) => $m->getAttributes(GuardsEditLock::class) !== [])
        ->map->getName()
        ->sort()->values()->all();

    expect($guarded)->toBe($methods);
})->with('purchasing A edit-locked show pages');
