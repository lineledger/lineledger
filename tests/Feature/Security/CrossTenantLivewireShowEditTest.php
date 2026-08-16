<?php

use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Enums\CreditMemoStatus;
use App\Enums\EstimateStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Http\Middleware\EnsureCompanyMembership;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Models\Transfer;
use App\Models\User;
use App\Models\VendorCredit;
use Illuminate\Routing\Middleware\SubstituteBindings;

use function Pest\Laravel\actingAs;

/**
 * Regression coverage for the cross-tenant IDOR (C1) on the Livewire show/edit
 * routes. These `Route::livewire(...)` routes resolve their {model} bindings via
 * SubstituteBindings. If that runs before EnsureCompanyMembership binds
 * `current_company`, the global CompanyScope is inactive at binding time and a
 * member of company A can load company B's record by ID. EnsureCompanyMembership
 * must therefore run before SubstituteBindings so the scope is active — making
 * the binding 404 instead of leaking another tenant's document.
 *
 * Two layers of coverage:
 *  - a behavioural dataset that actually requests company B records as a company
 *    A member and asserts 404 across the AR / AP / banking document surfaces;
 *  - a structural assertion that EVERY `{company}` route resolving a binding runs
 *    EnsureCompanyMembership before SubstituteBindings, so a future route added
 *    without that ordering fails CI even if no behavioural case is written for it.
 */
beforeEach(function () {
    // Victim company B with private records.
    $this->victim = User::factory()->create();
    $this->companyB = Company::factory()->create();
    $this->companyB->members()->attach($this->victim, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $this->companyB);

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $otherAccount = Account::query()->where('id', '!=', $bank->id)->orderBy('code')->first();
    $customer = Contact::factory()->customer()->create();
    $vendor = Contact::factory()->vendor()->create();

    $this->invoiceB = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-B-1',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'status' => InvoiceStatus::Draft,
    ]);

    $this->billB = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-B-1',
        'bill_date' => now()->subDays(5)->toDateString(),
        'due_date' => now()->addDays(25)->toDateString(),
    ]);

    $this->reimbursementB = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Reimbursement,
        'bill_no' => 'REIMB-B-1',
        'bill_date' => now()->subDays(5)->toDateString(),
        'due_date' => now()->addDays(25)->toDateString(),
    ]);

    $this->estimateB = Estimate::create([
        'contact_id' => $customer->id,
        'estimate_no' => 'EST-B-1',
        'estimate_date' => now()->toDateString(),
        'status' => EstimateStatus::Pending,
    ]);

    $this->salesOrderB = SalesOrder::create([
        'contact_id' => $customer->id,
        'order_no' => 'SO-B-1',
        'order_date' => now()->toDateString(),
        'status' => SalesOrderStatus::Open,
    ]);

    $this->creditMemoB = CreditMemo::create([
        'contact_id' => $customer->id,
        'credit_memo_no' => 'CM-B-1',
        'credit_memo_date' => now()->toDateString(),
        'status' => CreditMemoStatus::Draft,
    ]);

    $this->receiptB = CustomerReceipt::create([
        'contact_id' => $customer->id,
        'receipt_no' => 'REC-B-1',
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $bank->id,
        'amount_cents' => 100,
    ]);

    $this->vendorCreditB = VendorCredit::create([
        'contact_id' => $vendor->id,
        'vendor_credit_no' => 'VC-B-1',
        'vendor_credit_date' => now()->toDateString(),
    ]);

    $this->depositB = Deposit::create([
        'bank_account_id' => $bank->id,
        'deposit_no' => 'DEP-B-1',
        'deposit_date' => now()->toDateString(),
        'amount_cents' => 100,
    ]);

    $this->transferB = Transfer::create([
        'from_account_id' => $bank->id,
        'to_account_id' => $otherAccount->id,
        'transfer_no' => 'XFR-B-1',
        'transfer_date' => now()->toDateString(),
        'from_amount_cents' => 5000,
        'to_amount_cents' => 5000,
    ]);

    $this->billPaymentB = BillPayment::create([
        'contact_id' => $vendor->id,
        'payment_no' => 'PAY-B-1',
        'payment_date' => now()->toDateString(),
        'paid_from_account_id' => $bank->id,
        'reference' => '7777',
        'amount_cents' => 1000,
    ]);

    $this->chequeB = Cheque::create([
        'bank_account_id' => $bank->id,
        'cheque_no' => '5001',
        'cheque_date' => now()->toDateString(),
        'payee_name' => 'Jane Doe',
    ]);

    app()->forgetInstance('current_company');

    // Attacker, member of company A only.
    $this->attacker = User::factory()->create();
    $this->companyA = Company::factory()->create();
    $this->companyA->members()->attach($this->attacker, ['role' => CompanyRole::Owner->value]);

    actingAs($this->attacker);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Each case is [route-name, route-param-key, model-property]. The attacker uses
 * their OWN company A slug in the URL (passing EnsureCompanyMembership for A)
 * but references company B's record id.
 *
 * @return array<string, array{0: string, 1: string, 2: string}>
 */
dataset('cross_tenant_routes', [
    'invoice show' => ['invoices.show', 'invoice', 'invoiceB'],
    'invoice edit' => ['invoices.edit', 'invoice', 'invoiceB'],
    'bill show' => ['bills.show', 'bill', 'billB'],
    'bill edit' => ['bills.edit', 'bill', 'billB'],
    'reimbursement show' => ['reimbursements.show', 'bill', 'reimbursementB'],
    'reimbursement edit' => ['reimbursements.edit', 'bill', 'reimbursementB'],
    'estimate show' => ['estimates.show', 'estimate', 'estimateB'],
    'estimate edit' => ['estimates.edit', 'estimate', 'estimateB'],
    'sales order show' => ['sales-orders.show', 'salesOrder', 'salesOrderB'],
    'sales order edit' => ['sales-orders.edit', 'salesOrder', 'salesOrderB'],
    'credit memo show' => ['credit-memos.show', 'credit_memo', 'creditMemoB'],
    'credit memo edit' => ['credit-memos.edit', 'credit_memo', 'creditMemoB'],
    'receipt show' => ['receipts.show', 'receipt', 'receiptB'],
    'receipt edit' => ['receipts.edit', 'receipt', 'receiptB'],
    'vendor credit show' => ['vendor-credits.show', 'vendor_credit', 'vendorCreditB'],
    'vendor credit edit' => ['vendor-credits.edit', 'vendor_credit', 'vendorCreditB'],
    'deposit show' => ['deposits.show', 'deposit', 'depositB'],
    'transfer show' => ['transfers.show', 'transfer', 'transferB'],
    'transfer edit' => ['transfers.edit', 'transfer', 'transferB'],
    'bill payment show' => ['bill-payments.show', 'payment', 'billPaymentB'],
    'bill payment edit' => ['bill-payments.edit', 'payment', 'billPaymentB'],
    'cheque show' => ['cheques.show', 'cheque', 'chequeB'],
    'cheque edit' => ['cheques.edit', 'cheque', 'chequeB'],
]);

it('does not let a company A member reach a company B record', function (string $routeName, string $paramKey, string $recordProp) {
    $record = $this->{$recordProp};

    $this->get(route($routeName, [
        'company' => $this->companyA->slug,
        $paramKey => $record->id,
    ]))->assertNotFound();
})->with('cross_tenant_routes');

it('runs EnsureCompanyMembership before SubstituteBindings on every {company} route that resolves a binding', function () {
    $router = app('router');

    $offenders = [];
    $inspected = 0;

    foreach ($router->getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), '{company}/')) {
            continue;
        }

        // gatherRouteMiddleware returns the fully resolved + priority-sorted
        // stack; parameterized middleware arrive as "Class:params".
        $middleware = array_map(
            fn ($m) => is_string($m) ? explode(':', $m, 2)[0] : $m,
            $router->gatherRouteMiddleware($route),
        );

        $substitute = array_search(SubstituteBindings::class, $middleware, true);

        // Only routes that actually resolve a route-model binding can leak.
        if ($substitute === false) {
            continue;
        }

        $inspected++;

        $membership = array_search(EnsureCompanyMembership::class, $middleware, true);

        if ($membership === false || $membership > $substitute) {
            $offenders[] = $route->uri();
        }
    }

    expect($offenders)->toBe([]);
    // Guard against a vacuous pass: the {company} group has many model-bound
    // routes, so the loop must actually have inspected a substantial set.
    expect($inspected)->toBeGreaterThan(15);
});
