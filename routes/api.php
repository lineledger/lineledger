<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AssetCategoryController;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\BankReconciliationController;
use App\Http\Controllers\Api\V1\BillController;
use App\Http\Controllers\Api\V1\BillPaymentController;
use App\Http\Controllers\Api\V1\ChequeController;
use App\Http\Controllers\Api\V1\CreditMemoController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DepositController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\JournalEntryController;
use App\Http\Controllers\Api\V1\OpenApiController;
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\PaymentTermController;
use App\Http\Controllers\Api\V1\ReceiptController;
use App\Http\Controllers\Api\V1\SalesOrderController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\TaxAgencyController;
use App\Http\Controllers\Api\V1\TaxCodeController;
use App\Http\Controllers\Api\V1\TaxReturnController;
use App\Http\Controllers\Api\V1\TaxReturnPaymentController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Controllers\Api\V1\VendorController;
use Illuminate\Support\Facades\Route;

// Public machine-readable spec (no auth — documents the surface, no secrets).
Route::get('v1/openapi.json', OpenApiController::class)->name('api.v1.openapi');

Route::middleware(['throttle:api', 'auth.api_key'])
    ->prefix('v1')
    ->name('api.v1.')
    ->group(function () {
        /**
         * Register a full-lifecycle document resource: index/show (read scope),
         * store/update/destroy (write scope), plus any extra write actions
         * (e.g. 'post', 'void', 'file', 'complete') as POST {uri}/{param}/{action}.
         *
         * The ability gate uses the resource-level scope ({uri}:read|write); a
         * key holding the parent domain grain (e.g. sales:write) satisfies it as
         * a superset — see CompanyApiKey::hasAbility. $domain is retained for
         * documentation/grouping.
         *
         * $param must equal the controller method's model variable name so
         * implicit route-model binding resolves (and stays tenant-scoped).
         *
         * @param  array<int, string>  $actions
         */
        $resource = function (string $uri, string $param, string $controller, string $domain, array $actions = []): void {
            Route::get($uri, [$controller, 'index'])->name("{$uri}.index")->middleware("api.ability:{$uri}:read");
            Route::get("{$uri}/{{$param}}", [$controller, 'show'])->name("{$uri}.show")->middleware("api.ability:{$uri}:read");
            Route::post($uri, [$controller, 'store'])->name("{$uri}.store")->middleware("api.ability:{$uri}:write");
            Route::match(['put', 'patch'], "{$uri}/{{$param}}", [$controller, 'update'])->name("{$uri}.update")->middleware("api.ability:{$uri}:write");
            Route::delete("{$uri}/{{$param}}", [$controller, 'destroy'])->name("{$uri}.destroy")->middleware("api.ability:{$uri}:write");

            foreach ($actions as $action) {
                Route::post("{$uri}/{{$param}}/{$action}", [$controller, $action])->name("{$uri}.{$action}")->middleware("api.ability:{$uri}:write");
            }
        };

        // ---- AR / Sales ----
        // Contact resources share the {contact} binding (one Contact model, role-filtered).
        $resource('customers', 'contact', CustomerController::class, 'sales');
        $resource('sales-orders', 'salesOrder', SalesOrderController::class, 'sales', ['fulfill', 'cancel']);
        $resource('invoices', 'invoice', InvoiceController::class, 'sales', ['post']);
        $resource('receipts', 'receipt', ReceiptController::class, 'sales', ['post']);
        $resource('credit-memos', 'creditMemo', CreditMemoController::class, 'sales', ['post', 'refund']);

        // ---- AP / Purchasing ----
        $resource('vendors', 'contact', VendorController::class, 'purchases');
        $resource('employees', 'contact', EmployeeController::class, 'purchases');
        $resource('bills', 'bill', BillController::class, 'purchases', ['post']);
        $resource('bill-payments', 'billPayment', BillPaymentController::class, 'purchases', ['post']);

        // ---- Banking ----
        $resource('cheques', 'cheque', ChequeController::class, 'banking', ['post']);
        $resource('deposits', 'deposit', DepositController::class, 'banking', ['post']);
        $resource('transfers', 'transfer', TransferController::class, 'banking', ['post']);
        $resource('bank-reconciliations', 'bankReconciliation', BankReconciliationController::class, 'banking', ['complete']);

        // ---- GL + Master data ----
        $resource('accounts', 'account', AccountController::class, 'accounting');
        $resource('journal-entries', 'journalEntry', JournalEntryController::class, 'accounting', ['post']);
        $resource('items', 'item', ItemController::class, 'inventory');
        $resource('tax-codes', 'taxCode', TaxCodeController::class, 'tax');
        $resource('tax-agencies', 'taxAgency', TaxAgencyController::class, 'tax');
        $resource('payment-terms', 'paymentTerm', PaymentTermController::class, 'settings');
        $resource('payment-methods', 'paymentMethod', PaymentMethodController::class, 'settings');

        // ---- Tax / Assets / Inventory ----
        $resource('tax-returns', 'taxReturn', TaxReturnController::class, 'tax', ['file', 'void']);
        $resource('tax-return-payments', 'taxReturnPayment', TaxReturnPaymentController::class, 'tax', ['post']);
        $resource('assets', 'asset', AssetController::class, 'accounting');
        $resource('asset-categories', 'assetCategory', AssetCategoryController::class, 'accounting');
        $resource('stock-adjustments', 'stockAdjustment', StockAdjustmentController::class, 'inventory', ['post']);
    });
