<?php

use App\Http\Controllers\BillPayments\PrintBillPaymentController;
use App\Http\Controllers\BillPayments\PrintChequeController;
use App\Http\Controllers\Bills\PrintBillController;
use App\Http\Controllers\Charity\PrintDonationReceiptController;
use App\Http\Controllers\ChartOfAccountsTemplateController;
use App\Http\Controllers\Cheques\PrintChequeController as ChequesPrintChequeController;
use App\Http\Controllers\CompanyBackupDownloadController;
use App\Http\Controllers\Estimates\PrintEstimateController;
use App\Http\Controllers\Health\ExchangeRateHealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Inbound\InboundEmailController;
use App\Http\Controllers\Invoices\PrintCreditMemoController;
use App\Http\Controllers\Invoices\PrintInvoiceController;
use App\Http\Controllers\Invoices\PrintReceiptController;
use App\Http\Controllers\Invoices\PrintSalesReceiptController;
use App\Http\Controllers\Migration\MigrationTemplateController;
use App\Http\Controllers\Payroll\PrintPayrollChequeController;
use App\Http\Controllers\Payroll\PrintPayStubController;
use App\Http\Controllers\Portal\EmployeePortalDocumentController;
use App\Http\Controllers\Portal\EmployeePortalSessionController;
use App\Http\Controllers\Portal\PortalDocumentController;
use App\Http\Controllers\Portal\SessionController as PortalSessionController;
use App\Http\Controllers\Purchasing\PrintPurchaseOrderController;
use App\Http\Controllers\Purchasing\PrintVendorCreditController;
use App\Http\Controllers\Reports\PrintReportController;
use App\Http\Controllers\SalesOrders\PrintSalesOrderController;
use App\Http\Controllers\Security\CspReportController;
use App\Http\Controllers\Settings\ListImportTemplateController;
use App\Http\Controllers\Stripe\ConnectController;
use App\Http\Controllers\Stripe\WebhookController as StripeWebhookController;
use App\Http\Controllers\VerificationDownloadController;
use App\Http\Middleware\EnforceTwoFactor;
use App\Http\Middleware\EnsureCompanyMembership;
use App\Http\Middleware\EnsureSectionAccess;
use App\Http\Middleware\EnsureSectionEnabled;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\DocumentFolder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', HomeController::class)->name('home');

// Public liveness probe for the daily provider FX rate fetch (rates:fetch).
// 200 when every active foreign currency pair is fresh, 503 when any is stale.
// Throttled since it's unauthenticated and hits the DB.
Route::get('health/fx', ExchangeRateHealthController::class)
    ->middleware('throttle:60,1')
    ->name('health.fx');

// Public proof-of-correctness page: live accounting tests + downloadable evidence.
Route::livewire('verification', 'pages::verification.index')->name('verification');
Route::get('verification/download/{test}', VerificationDownloadController::class)->name('verification.download');

require __DIR__.'/docs.php';

// Onboarding. `verified` is deliberate: these routes create tenant data (a
// company, or a whole restored ledger), so an account whose email address has
// never been proven must not reach them. Without it a scripted signup can create
// organizations, not merely a users row. EnsureUserHasCompany exempts the
// verification routes so this gate resolves instead of looping.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('companies', 'pages::companies.picker')->name('companies.picker');

    Route::livewire('welcome', 'pages::welcome.setup-wizard')->name('welcome.create-company');

    Route::livewire('companies/new', 'pages::welcome.setup-wizard')->name('companies.setup');

    Route::livewire('companies/restore', 'pages::companies.restore')->name('companies.restore');

    Route::livewire('invitations/{invitation}/accept', 'pages::companies.accept-invitation')->name('invitations.accept');
});

Route::middleware(['auth'])->group(function () {
    // Gate screen for accepting (or re-accepting) updated legal documents. The
    // EnsureLegalAcceptance middleware redirects users here when they owe one.
    // Left ungated: that middleware runs in the global `web` group, ahead of
    // route middleware, so adding `verified` here would bounce a user who owes
    // legal acceptance between the two screens.
    Route::livewire('legal/accept', 'pages::legal.accept')->name('legal.accept');
});

// In-app support tickets. Platform-level (not tenant-scoped), so they live here
// beside settings rather than under the {company} prefix; per-page mount() guards
// enforce that a user only sees their own tickets.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('support', 'pages::support.index')->name('support.index');
    Route::livewire('support/{ticket}', 'pages::support.show')->name('support.show');
});

Route::prefix('{company}')
    ->middleware(['auth', 'verified', EnsureCompanyMembership::class, EnsureSectionEnabled::class, EnsureSectionAccess::class, EnforceTwoFactor::class])
    ->group(function () {
        Route::livewire('dashboard', 'pages::dashboard.index')->name('dashboard');
        // Daily "Did you know?" insight history — ungated like the dashboard
        // the card lives on (see Section::forRouteName).
        Route::livewire('insights', 'pages::insights.index')->name('insights.index');

        Route::livewire('accounts', 'pages::accounts.index')->name('accounts.index');
        Route::get('accounts/template', ChartOfAccountsTemplateController::class)->name('accounts.template');

        Route::livewire('journal', 'pages::journal.index')->name('journal.index');
        Route::livewire('journal/create', 'pages::journal.form')->name('journal.create');
        Route::livewire('journal/{entry}', 'pages::journal.show')->name('journal.show');
        Route::livewire('journal/{entry}/edit', 'pages::journal.form')->name('journal.edit');

        // Journal entry templates
        Route::livewire('journal-entry-templates', 'pages::journal-entry-templates.index')->name('journal-entry-templates.index');
        Route::livewire('journal-entry-templates/create', 'pages::journal-entry-templates.form')->name('journal-entry-templates.create');
        Route::livewire('journal-entry-templates/{journalEntryTemplate}/edit', 'pages::journal-entry-templates.form')->name('journal-entry-templates.edit');

        // Recurring (memorized) journal entries
        Route::livewire('recurring-journal', 'pages::recurring-journal.index')->name('recurring-journal.index');
        Route::livewire('recurring-journal/create', 'pages::recurring-journal.form')->name('recurring-journal.create');
        Route::livewire('recurring-journal/{recurring}', 'pages::recurring-journal.show')->name('recurring-journal.show');
        Route::livewire('recurring-journal/{recurring}/edit', 'pages::recurring-journal.form')->name('recurring-journal.edit');

        // Customers
        Route::livewire('customers', 'pages::customers.index')->name('customers.index');

        // Members (membership dues) — pages self-gate on the features_membership flag.
        Route::livewire('members', 'pages::members.index')->name('members.index');
        Route::livewire('members/create', 'pages::members.form')->name('members.create');
        Route::livewire('members/{member}', 'pages::members.show')->name('members.show');
        Route::livewire('members/{member}/edit', 'pages::members.form')->name('members.edit');

        // Invoices
        Route::livewire('invoices', 'pages::invoices.index')->name('invoices.index');
        Route::livewire('reminders', 'pages::reminders.index')->name('reminders.index');
        Route::livewire('invoices/create', 'pages::invoices.form')->name('invoices.create');
        Route::livewire('invoices/{invoice}', 'pages::invoices.show')->name('invoices.show');
        Route::livewire('invoices/{invoice}/edit', 'pages::invoices.form')->name('invoices.edit');
        Route::get('invoices/{invoice}/print', PrintInvoiceController::class)->name('invoices.print');

        // Invoice templates
        Route::livewire('invoice-templates', 'pages::invoice-templates.index')->name('invoice-templates.index');
        Route::livewire('invoice-templates/create', 'pages::invoice-templates.form')->name('invoice-templates.create');
        Route::livewire('invoice-templates/{invoiceTemplate}/edit', 'pages::invoice-templates.form')->name('invoice-templates.edit');

        // Estimates
        Route::livewire('estimates', 'pages::estimates.index')->name('estimates.index');
        Route::livewire('estimates/create', 'pages::estimates.form')->name('estimates.create');
        Route::livewire('estimates/{estimate}', 'pages::estimates.show')->name('estimates.show');
        Route::livewire('estimates/{estimate}/edit', 'pages::estimates.form')->name('estimates.edit');
        Route::get('estimates/{estimate}/print', PrintEstimateController::class)->name('estimates.print');

        // Sales orders
        Route::livewire('sales-orders', 'pages::sales-orders.index')->name('sales-orders.index');
        Route::livewire('sales-orders/create', 'pages::sales-orders.form')->name('sales-orders.create');
        Route::livewire('sales-orders/{salesOrder}', 'pages::sales-orders.show')->name('sales-orders.show');
        Route::livewire('sales-orders/{salesOrder}/edit', 'pages::sales-orders.form')->name('sales-orders.edit');
        Route::get('sales-orders/{salesOrder}/print', PrintSalesOrderController::class)->name('sales-orders.print');

        // Credit memos
        Route::livewire('credit-memos', 'pages::credit-memos.index')->name('credit-memos.index');
        Route::livewire('credit-memos/create', 'pages::credit-memos.form')->name('credit-memos.create');
        Route::livewire('credit-memos/{credit_memo}', 'pages::credit-memos.show')->name('credit-memos.show');
        Route::get('credit-memos/{credit_memo}/print', PrintCreditMemoController::class)->name('credit-memos.print');
        Route::livewire('credit-memos/{credit_memo}/edit', 'pages::credit-memos.form')->name('credit-memos.edit');

        // Receipts
        Route::livewire('receipts/create', 'pages::receipts.form')->name('receipts.create');
        Route::livewire('receipts', 'pages::receipts.index')->name('receipts.index');
        Route::livewire('receipts/{receipt}', 'pages::receipts.show')->name('receipts.show');
        Route::get('receipts/{receipt}/print', PrintReceiptController::class)->name('receipts.print');
        Route::livewire('receipts/{receipt}/edit', 'pages::receipts.form')->name('receipts.edit');

        // Sales receipts (pay-now sales: revenue + tax + cash in one document)
        Route::livewire('sales-receipts/create', 'pages::sales-receipts.form')->name('sales-receipts.create');
        Route::livewire('sales-receipts', 'pages::sales-receipts.index')->name('sales-receipts.index');
        Route::livewire('sales-receipts/{receipt}', 'pages::sales-receipts.show')->name('sales-receipts.show');
        Route::get('sales-receipts/{receipt}/print', PrintSalesReceiptController::class)->name('sales-receipts.print');
        Route::livewire('sales-receipts/{receipt}/edit', 'pages::sales-receipts.form')->name('sales-receipts.edit');

        // Recurring invoices & bills
        Route::livewire('recurring', 'pages::recurring.index')->name('recurring.index');
        Route::livewire('recurring/create', 'pages::recurring.form')->name('recurring.create');
        Route::livewire('recurring/{recurring}', 'pages::recurring.show')->name('recurring.show');
        Route::livewire('recurring/{recurring}/edit', 'pages::recurring.form')->name('recurring.edit');

        // Vendors / Bills / Payments
        Route::livewire('vendors', 'pages::vendors.index')->name('vendors.index');
        Route::livewire('bills', 'pages::bills.index')->name('bills.index');
        Route::livewire('bills/create', 'pages::bills.form')->name('bills.create');
        Route::livewire('bills/{bill}', 'pages::bills.show')->name('bills.show');
        Route::livewire('bills/{bill}/edit', 'pages::bills.form')->name('bills.edit');
        Route::get('bills/{bill}/print', PrintBillController::class)->name('bills.print');

        Route::livewire('bill-payments', 'pages::bill-payments.index')->name('bill-payments.index');
        Route::livewire('bill-payments/create', 'pages::bill-payments.form')->name('bill-payments.create');
        // Cross-vendor batch pay — registered before {payment} so "batch" isn't captured as an id.
        Route::livewire('bill-payments/batch', 'pages::bill-payments.batch')->name('bill-payments.batch');
        Route::livewire('bill-payments/{payment}', 'pages::bill-payments.show')->name('bill-payments.show');
        Route::livewire('bill-payments/{payment}/edit', 'pages::bill-payments.form')->name('bill-payments.edit');
        Route::get('bill-payments/{payment}/print-cheque', PrintChequeController::class)->name('bill-payments.print-cheque');
        Route::get('bill-payments/{payment}/print', PrintBillPaymentController::class)->name('bill-payments.print');

        // Pay-now expenses (card / Interac / EFT / cash); coexists with cheques.
        Route::livewire('expenses', 'pages::expenses.index')->name('expenses.index');
        Route::livewire('expenses/create', 'pages::expenses.form')->name('expenses.create');
        Route::livewire('expenses/{expense}', 'pages::expenses.show')->name('expenses.show');
        Route::livewire('expenses/{expense}/edit', 'pages::expenses.form')->name('expenses.edit');

        // Purchase orders
        Route::livewire('purchase-orders', 'pages::purchase-orders.index')->name('purchase-orders.index');
        Route::livewire('purchase-orders/create', 'pages::purchase-orders.form')->name('purchase-orders.create');
        Route::livewire('purchase-orders/{purchaseOrder}', 'pages::purchase-orders.show')->name('purchase-orders.show');
        Route::livewire('purchase-orders/{purchaseOrder}/edit', 'pages::purchase-orders.form')->name('purchase-orders.edit');
        Route::get('purchase-orders/{purchaseOrder}/print', PrintPurchaseOrderController::class)->name('purchase-orders.print');

        // Vendor credits
        Route::livewire('vendor-credits', 'pages::vendor-credits.index')->name('vendor-credits.index');
        Route::livewire('vendor-credits/create', 'pages::vendor-credits.form')->name('vendor-credits.create');
        Route::livewire('vendor-credits/{vendor_credit}', 'pages::vendor-credits.show')->name('vendor-credits.show');
        Route::get('vendor-credits/{vendor_credit}/print', PrintVendorCreditController::class)->name('vendor-credits.print');
        Route::livewire('vendor-credits/{vendor_credit}/edit', 'pages::vendor-credits.form')->name('vendor-credits.edit');

        // Employees / Reimbursements
        Route::livewire('employees', 'pages::employees.index')->name('employees.index');
        Route::livewire('reimbursements', 'pages::reimbursements.index')->name('reimbursements.index');
        Route::livewire('reimbursements/create', 'pages::reimbursements.form')->name('reimbursements.create');
        Route::livewire('reimbursements/{bill}', 'pages::reimbursements.show')->name('reimbursements.show');
        Route::livewire('reimbursements/{bill}/edit', 'pages::reimbursements.form')->name('reimbursements.edit');
        Route::get('reimbursements/{bill}/print', PrintBillController::class)->name('reimbursements.print');

        // Payroll
        Route::livewire('payroll', 'pages::payroll.index')->name('payroll.index');
        Route::livewire('payroll/employees', 'pages::payroll.employees.index')->name('payroll.employees.index');
        Route::livewire('payroll/employees/{contact}/setup', 'pages::payroll.employees.form')->name('payroll.employees.setup');
        Route::livewire('payroll-schedules', 'pages::payroll.schedules.index')->name('payroll-schedules.index');
        Route::livewire('time-off-policies', 'pages::payroll.time-off-policies.index')->name('time-off-policies.index');
        Route::livewire('time-off-requests', 'pages::payroll.time-off-requests.index')->name('time-off-requests.index');
        Route::livewire('payroll/staff-calendar', 'pages::payroll.staff-calendar')->name('payroll.staff-calendar');
        Route::livewire('time-entries', 'pages::payroll.time-entries.index')->name('time-entries.index');
        Route::livewire('pay-runs', 'pages::payroll.pay-runs.index')->name('pay-runs.index');
        Route::livewire('pay-runs/create', 'pages::payroll.pay-runs.form')->name('pay-runs.create');
        Route::livewire('pay-runs/{payRun}', 'pages::payroll.pay-runs.show')->name('pay-runs.show');
        Route::livewire('pay-runs/{payRun}/edit', 'pages::payroll.pay-runs.form')->name('pay-runs.edit');
        Route::get('payroll-cheques/{payrollCheque}/print', PrintPayrollChequeController::class)->name('payroll-cheques.print');
        Route::get('pay-runs/lines/{payRunLine}/stub', PrintPayStubController::class)->name('payroll.pay-stub.preview');
        Route::livewire('payroll/reports/register', 'pages::payroll.reports.register')->name('payroll.reports.register');
        Route::livewire('payroll/reports/pd7a', 'pages::payroll.reports.pd7a')->name('payroll.reports.pd7a');
        Route::livewire('payroll/reports/revenu-quebec', 'pages::payroll.reports.revenu-quebec')->name('payroll.reports.revenu-quebec');
        Route::livewire('payroll/reports/workers-comp', 'pages::payroll.reports.workers-comp')->name('payroll.reports.workers-comp');
        Route::livewire('payroll/reports/remittances', 'pages::payroll.reports.remittances')->name('payroll.reports.remittances');
        Route::livewire('payroll/reports/t4', 'pages::payroll.reports.t4')->name('payroll.reports.t4');
        Route::livewire('payroll/reports/t4a', 'pages::payroll.reports.t4a')->name('payroll.reports.t4a');
        Route::livewire('payroll/reports/rl1', 'pages::payroll.reports.rl1')->name('payroll.reports.rl1');
        Route::livewire('payroll/reports/roe', 'pages::payroll.reports.roe')->name('payroll.reports.roe');
        Route::livewire('payroll/reports/verification', 'pages::payroll.reports.verification')->name('payroll.reports.verification');

        // Banking
        Route::livewire('cheques', 'pages::cheques.index')->name('cheques.index');
        Route::livewire('cheques/create', 'pages::cheques.form')->name('cheques.create');
        Route::livewire('cheques/{cheque}', 'pages::cheques.show')->name('cheques.show');
        Route::livewire('cheques/{cheque}/edit', 'pages::cheques.form')->name('cheques.edit');
        Route::get('cheques/{cheque}/print', ChequesPrintChequeController::class)->name('cheques.print');

        Route::livewire('deposits', 'pages::deposits.index')->name('deposits.index');
        Route::livewire('deposits/create', 'pages::deposits.form')->name('deposits.create');
        Route::livewire('deposits/{deposit}', 'pages::deposits.show')->name('deposits.show');
        Route::livewire('deposits/{deposit}/edit', 'pages::deposits.form')->name('deposits.edit');

        Route::livewire('transfers', 'pages::transfers.index')->name('transfers.index');
        Route::livewire('transfers/create', 'pages::transfers.form')->name('transfers.create');
        Route::livewire('transfers/{transfer}', 'pages::transfers.show')->name('transfers.show');
        Route::livewire('transfers/{transfer}/edit', 'pages::transfers.form')->name('transfers.edit');

        Route::livewire('banking/register', 'pages::banking.register')->name('banking.register');
        Route::livewire('banking/reconcile', 'pages::banking.reconcile')->name('banking.reconcile');
        Route::livewire('banking/import', 'pages::banking.import')->name('banking.import');
        Route::livewire('banking/review', 'pages::banking.review')->name('banking.review');
        Route::livewire('banking/rules', 'pages::banking.rules')->name('banking.rules');
        Route::livewire('banking/reconciliations/{reconciliation}', 'pages::banking.reconciliation-show')->name('banking.reconciliations.show');

        // Document inbox — drag-drop / email-forwarded receipts & bills staged
        // for OCR, review and promotion into draft bills/expenses. The {item}
        // binds App\Models\InboxItem (auto company-scoped via CompanyScope); the
        // show page redirects to bills.show / expenses.show after promotion.
        Route::livewire('inbox', 'pages::inbox.index')->name('inbox.index');
        Route::livewire('inbox/{item}', 'pages::inbox.show')->name('inbox.show');

        // Fundraising — donations, grants & donation receipts (pages self-gate on the
        // features_fundraising flag; official receipts additionally gate on charity status).
        Route::livewire('donations', 'pages::donations.index')->name('donations.index');
        Route::livewire('donations/create', 'pages::donations.form')->name('donations.create');
        Route::livewire('donations/{donation}', 'pages::donations.show')->name('donations.show');
        Route::livewire('donations/{donation}/edit', 'pages::donations.form')->name('donations.edit');

        Route::livewire('donation-receipts', 'pages::donation-receipts.index')->name('donation-receipts.index');
        Route::livewire('donation-receipts/create', 'pages::donation-receipts.form')->name('donation-receipts.create');
        Route::livewire('donation-receipts/{donationReceipt}', 'pages::donation-receipts.show')->name('donation-receipts.show');
        Route::livewire('donation-receipts/{donationReceipt}/edit', 'pages::donation-receipts.form')->name('donation-receipts.edit');
        Route::get('donation-receipts/{donationReceipt}/print', PrintDonationReceiptController::class)->name('donation-receipts.print');

        Route::livewire('grants', 'pages::grants.index')->name('grants.index');
        Route::livewire('grants/create', 'pages::grants.form')->name('grants.create');
        Route::livewire('grants/{grant}', 'pages::grants.show')->name('grants.show');
        Route::livewire('grants/{grant}/edit', 'pages::grants.form')->name('grants.edit');

        // Reports
        Route::livewire('reports', 'pages::reports.index')->name('reports.index');
        Route::livewire('reports/trial-balance', 'pages::reports.trial-balance')->name('reports.trial-balance');
        Route::livewire('reports/gifi', 'pages::reports.gifi')->name('reports.gifi');
        Route::livewire('reports/t5013', 'pages::reports.t5013')->name('reports.t5013');
        Route::livewire('reports/t2125', 'pages::reports.t2125')->name('reports.t2125');
        Route::livewire('reports/t3010', 'pages::reports.t3010')->name('reports.t3010');
        Route::livewire('reports/general-ledger', 'pages::reports.general-ledger')->name('reports.general-ledger');
        Route::livewire('reports/balance-sheet', 'pages::reports.balance-sheet')->name('reports.balance-sheet');
        Route::livewire('reports/balance-sheet/sections', 'pages::reports.balance-sheet-sections')->name('reports.balance-sheet.sections');
        Route::livewire('reports/income-statement', 'pages::reports.income-statement')->name('reports.income-statement');
        Route::livewire('reports/income-statement/sections', 'pages::reports.income-statement-sections')->name('reports.income-statement.sections');
        Route::livewire('reports/profit-insights', 'pages::reports.profit-insights')->name('reports.profit-insights');
        Route::livewire('reports/cash-flow', 'pages::reports.cash-flow')->name('reports.cash-flow');
        Route::livewire('reports/cash-flow/sections', 'pages::reports.cash-flow-sections')->name('reports.cash-flow.sections');
        Route::livewire('reports/cash-flow-forecast', 'pages::reports.cash-flow-forecast')->name('reports.cash-flow-forecast');
        Route::livewire('reports/cash-on-hand', 'pages::reports.cash-on-hand')->name('reports.cash-on-hand');
        Route::livewire('reports/statement-of-financial-position', 'pages::reports.statement-of-financial-position')->name('reports.statement-of-financial-position');
        Route::livewire('reports/statement-of-operations', 'pages::reports.statement-of-operations')->name('reports.statement-of-operations');
        Route::livewire('reports/statement-of-changes-in-net-assets', 'pages::reports.statement-of-changes-in-net-assets')->name('reports.statement-of-changes-in-net-assets');
        Route::livewire('reports/budget-vs-actual', 'pages::reports.budget-vs-actual')->name('reports.budget-vs-actual');
        Route::livewire('reports/budget-overview', 'pages::reports.budget-overview')->name('reports.budget-overview');
        Route::livewire('reports/budget-vs-actual-by-month', 'pages::reports.budget-vs-actual-by-month')->name('reports.budget-vs-actual-by-month');

        // Budgets
        Route::livewire('budgets', 'pages::budgets.index')->name('budgets.index');
        Route::livewire('budgets/create', 'pages::budgets.form')->name('budgets.create');
        Route::livewire('budgets/{budget}/edit', 'pages::budgets.form')->name('budgets.edit');
        Route::livewire('reports/sales-tax', 'pages::reports.sales-tax')->name('reports.sales-tax');

        // Sales, Purchases & Inventory reports
        Route::livewire('reports/sales-by-customer', 'pages::reports.sales-by-customer')->name('reports.sales-by-customer');
        Route::livewire('reports/sales-by-customer-detail', 'pages::reports.sales-by-customer-detail')->name('reports.sales-by-customer-detail');
        Route::livewire('reports/sales-by-item', 'pages::reports.sales-by-item')->name('reports.sales-by-item');
        Route::livewire('reports/sales-by-rep', 'pages::reports.sales-by-rep')->name('reports.sales-by-rep');
        Route::livewire('reports/purchases-by-vendor', 'pages::reports.purchases-by-vendor')->name('reports.purchases-by-vendor');
        Route::livewire('reports/purchases-by-item', 'pages::reports.purchases-by-item')->name('reports.purchases-by-item');
        Route::livewire('reports/open-purchase-orders', 'pages::reports.open-purchase-orders')->name('reports.open-purchase-orders');
        Route::livewire('reports/inventory-stock-status', 'pages::reports.inventory-stock-status')->name('reports.inventory-stock-status');
        Route::livewire('reports/inventory-valuation', 'pages::reports.inventory-valuation')->name('reports.inventory-valuation');

        // Membership & Fundraising reports (pages self-gate on their feature flags)
        Route::livewire('reports/membership-roster', 'pages::reports.membership-roster')->name('reports.membership-roster');
        Route::livewire('reports/membership-revenue-by-level', 'pages::reports.membership-revenue-by-level')->name('reports.membership-revenue-by-level');
        Route::livewire('reports/donations-by-donor', 'pages::reports.donations-by-donor')->name('reports.donations-by-donor');
        Route::livewire('reports/donations-by-fund', 'pages::reports.donations-by-fund')->name('reports.donations-by-fund');
        Route::livewire('reports/grants-summary', 'pages::reports.grants-summary')->name('reports.grants-summary');

        // QuickZoom drill target
        Route::livewire('reports/transactions', 'pages::reports.transactions')->name('reports.transactions');

        // Memorized reports
        Route::livewire('reports/memorized', 'pages::reports.memorized')->name('reports.memorized');

        // List reports
        Route::livewire('reports/account-list', 'pages::reports.account-list')->name('reports.account-list');
        Route::livewire('reports/customer-contact-list', 'pages::reports.customer-contact-list')->name('reports.customer-contact-list');
        Route::livewire('reports/vendor-contact-list', 'pages::reports.vendor-contact-list')->name('reports.vendor-contact-list');

        // Print-friendly inline PDF of any renderable report
        Route::get('reports/print/{reportKey}', PrintReportController::class)
            ->where('reportKey', '[a-z0-9.\-]+')
            ->name('reports.print');

        // Management report packages (bundled PDF with cover + TOC)
        Route::livewire('reports/management', 'pages::reports.management')->name('reports.management');

        // Tax returns
        Route::livewire('tax-returns', 'pages::tax-returns.index')->name('tax-returns.index');
        Route::livewire('tax-returns/create', 'pages::tax-returns.form')->name('tax-returns.create');
        Route::livewire('tax-returns/{tax_return}', 'pages::tax-returns.show')->name('tax-returns.show');
        Route::livewire('tax-returns/{tax_return}/edit', 'pages::tax-returns.form')->name('tax-returns.edit');
        Route::livewire('tax-returns/{tax_return}/payments/create', 'pages::tax-returns.payment-form')->name('tax-returns.payments.create');
        Route::livewire('tax-returns/{tax_return}/payments/{payment}', 'pages::tax-returns.payment-show')->name('tax-returns.payments.show');
        Route::livewire('reports/ar-aging', 'pages::reports.ar-aging')->name('reports.ar-aging');
        Route::livewire('reports/open-invoices', 'pages::reports.open-invoices')->name('reports.open-invoices');
        Route::livewire('reports/unattributed-ar', 'pages::reports.unattributed-ar')->name('reports.unattributed-ar');
        Route::livewire('reports/ap-aging', 'pages::reports.ap-aging')->name('reports.ap-aging');
        Route::livewire('reports/open-bills', 'pages::reports.open-bills')->name('reports.open-bills');
        Route::livewire('reports/1099-summary', 'pages::reports.form-1099')->name('reports.form-1099');
        Route::livewire('reports/contact-statement/{contact}', 'pages::reports.contact-statement')->name('reports.contact-statement');
        Route::livewire('reports/audit-log', 'pages::reports.audit-log')
            ->middleware(EnsureCompanyMembership::class.':owner')
            ->name('reports.audit-log');

        // Attachments
        Route::get('attachments/{attachment}/download', function (Request $request, Company $company, Attachment $attachment) {
            abort_unless($attachment->company_id === $company->id, 404);

            // Repository documents sit behind per-folder view permissions. For
            // every other attachable type (invoices, bills, …) the closure is a
            // no-op beyond the company check above.
            if ($attachment->attachable_type === (new DocumentFolder)->getMorphClass()) {
                $folder = DocumentFolder::find($attachment->attachable_id);

                abort_unless($folder !== null && Gate::forUser($request->user())->allows('view', $folder), 403);
            }

            // Open safe types (PDF, raster images) inline in a new tab when asked;
            // everything else (and any unsafe type) is forced to download. nosniff
            // stops the browser second-guessing the declared content type.
            if ($request->boolean('inline') && $attachment->isInlineViewable()) {
                return Storage::disk($attachment->disk)->response(
                    $attachment->path,
                    $attachment->original_filename,
                    ['Content-Type' => $attachment->mime_type, 'X-Content-Type-Options' => 'nosniff'],
                    'inline',
                );
            }

            return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_filename);
        })->name('attachments.download');

        // Documents
        Route::livewire('documents', 'pages::documents.index')->name('documents.index');
        Route::livewire('documents/attached', 'pages::documents.attached-index')->name('documents.attached-index');
        Route::livewire('documents/folders/{folder}', 'pages::documents.show')->name('documents.show');

        // Inventory
        Route::livewire('inventory', 'pages::inventory.index')->name('inventory.index');
        Route::livewire('inventory/adjustments', 'pages::inventory.adjustments')->name('inventory.adjustments.index');
        Route::livewire('inventory/items/{item}', 'pages::inventory.item-history')->name('inventory.item-history');

        // Fixed assets
        Route::livewire('assets', 'pages::assets.index')->name('assets.index');
        Route::livewire('assets/create', 'pages::assets.form')->name('assets.create');
        Route::livewire('assets/{asset}', 'pages::assets.show')->name('assets.show');
        Route::livewire('assets/{asset}/edit', 'pages::assets.form')->name('assets.edit');

        // QuickBooks migration
        Route::livewire('import-from-quickbooks', 'pages::migration.import')->name('migration.import');
        Route::get('import-from-quickbooks/templates/{step}', MigrationTemplateController::class)
            ->where('step', '[a-z_]+')
            ->name('migration.template');

        // Per-company settings
        Route::livewire('settings/inventory', 'pages::settings.inventory')->name('settings.inventory');
        Route::livewire('settings/invoices', 'pages::settings.invoices')->name('settings.invoices');
        Route::livewire('settings/payroll', 'pages::settings.payroll')->name('settings.payroll');
        Route::livewire('settings/tax-and-filing', 'pages::settings.tax-and-filing')->name('settings.tax-and-filing');
        Route::livewire('settings/currencies', 'pages::settings.currencies')->name('settings.currencies');
        Route::livewire('settings/backup-and-export', 'pages::settings.backup-and-export')->name('settings.backup-and-export');
        Route::get('settings/backup-and-export/{backup}/download', CompanyBackupDownloadController::class)
            ->middleware('signed')
            ->name('settings.backup.download');

        // Per-company settings (lists)
        Route::livewire('settings/lists', 'pages::settings.lists.index')->name('lists.index');
        Route::get('settings/lists/{list}/template', ListImportTemplateController::class)
            ->where('list', 'items|item-categories')
            ->name('lists.template');
        Route::livewire('settings/lists/items', 'pages::settings.lists.items')->name('lists.items');
        Route::livewire('settings/lists/item-categories', 'pages::settings.lists.item-categories')->name('lists.item-categories');
        Route::livewire('settings/lists/tax-codes', 'pages::settings.lists.tax-codes')->name('lists.tax-codes');
        Route::livewire('settings/lists/payment-terms', 'pages::settings.lists.payment-terms')->name('lists.payment-terms');
        Route::livewire('settings/lists/payment-methods', 'pages::settings.lists.payment-methods')->name('lists.payment-methods');
        Route::livewire('settings/lists/asset-categories', 'pages::settings.lists.asset-categories')->name('lists.asset-categories');
        Route::livewire('settings/lists/classifications', 'pages::settings.lists.classifications')->name('lists.classifications');
        Route::livewire('settings/lists/locations', 'pages::settings.lists.locations')->name('lists.locations');
        Route::livewire('settings/lists/funds', 'pages::settings.lists.funds')->name('lists.funds');
        Route::livewire('settings/lists/membership-levels', 'pages::settings.lists.membership-levels')->name('lists.membership-levels');
        Route::livewire('settings/lists/form-styles', 'pages::settings.lists.form-styles')->name('lists.form-styles');

        // Stripe Connect onboarding (owner only)
        Route::get('settings/stripe/connect', [ConnectController::class, 'redirect'])
            ->middleware(EnsureCompanyMembership::class.':owner')
            ->name('settings.stripe.connect');
        Route::delete('settings/stripe/disconnect', [ConnectController::class, 'disconnect'])
            ->middleware(EnsureCompanyMembership::class.':owner')
            ->name('settings.stripe.disconnect');
    });

/*
|--------------------------------------------------------------------------
| Customer payment portal
|--------------------------------------------------------------------------
|
| Public, customer-facing surface scoped to a single company. Passwordless
| (magic-link) sign-in via the `customer` guard; no staff membership required.
| portal.company binds the current company from the {company} slug before the
| guard resolves a Contact from the session.
*/
Route::prefix('pay/{company}')
    ->middleware('portal.company')
    ->group(function () {
        Route::livewire('login', 'pages::portal.login')->name('portal.login');
        Route::get('login/{token}', [PortalSessionController::class, 'consume'])
            ->where('token', '[A-Za-z0-9]+')
            ->name('portal.login.consume');

        Route::middleware(['auth:customer', 'portal.audience:customer'])->group(function () {
            Route::livewire('/', 'pages::portal.dashboard')->name('portal.dashboard');
            Route::livewire('statement', 'pages::portal.statement')->name('portal.statement');
            Route::livewire('pay', 'pages::portal.pay')->name('portal.pay');

            Route::livewire('invoices/{invoice}', 'pages::portal.invoice')->name('portal.invoices.show');
            Route::get('invoices/{invoice}/pdf', [PortalDocumentController::class, 'invoice'])->name('portal.invoices.pdf');
            Route::get('statement/pdf', [PortalDocumentController::class, 'statement'])->name('portal.statement.pdf');

            Route::post('logout', [PortalSessionController::class, 'destroy'])->name('portal.logout');
        });
    });

/*
|--------------------------------------------------------------------------
| Employee self-service portal ("my-pay")
|--------------------------------------------------------------------------
|
| Employee-facing surface scoped to a single company. Reuses the passwordless
| `customer` guard (its provider is Contact, and employees are Contacts), so
| portal.company binds the company and portal.audience:employee asserts the
| signed-in contact is an employee. No banking / EFT is ever collected here.
*/
Route::prefix('my-pay/{company}')
    ->middleware('portal.company')
    ->group(function () {
        Route::livewire('login', 'pages::employee-portal.login')->name('employee-portal.login');
        Route::get('login/{token}', [EmployeePortalSessionController::class, 'consume'])
            ->where('token', '[A-Za-z0-9]+')
            ->name('employee-portal.login.consume');

        Route::middleware(['auth:customer', 'portal.audience:employee'])->group(function () {
            Route::livewire('/', 'pages::employee-portal.dashboard')->name('employee-portal.dashboard');
            Route::livewire('info', 'pages::employee-portal.edit-info')->name('employee-portal.edit-info');
            Route::livewire('time', 'pages::employee-portal.time')->name('employee-portal.time');
            Route::livewire('time-off', 'pages::employee-portal.time-off')->name('employee-portal.time-off');

            Route::get('pay-statements/{payRunLine}/pdf', [EmployeePortalDocumentController::class, 'payStub'])
                ->name('employee-portal.pay-stub.pdf');
            Route::get('t4/{year}/pdf', [EmployeePortalDocumentController::class, 't4'])
                ->where('year', '[0-9]{4}')
                ->name('employee-portal.t4.pdf');
            Route::get('rl1/{year}/pdf', [EmployeePortalDocumentController::class, 'rl1'])
                ->where('year', '[0-9]{4}')
                ->name('employee-portal.rl1.pdf');

            Route::post('logout', [EmployeePortalSessionController::class, 'destroy'])->name('employee-portal.logout');
        });
    });

/*
|--------------------------------------------------------------------------
| Stripe (platform-level, not company-prefixed)
|--------------------------------------------------------------------------
|
| OAuth return lands here in the owner's browser session; the company is read
| from the signed state. The webhook is unauthenticated and CSRF-exempt (see
| bootstrap/app.php) — it is verified by Stripe signature instead.
*/
Route::get('stripe/connect/callback', [ConnectController::class, 'callback'])
    ->middleware('auth')
    ->name('stripe.connect.callback');

Route::post('stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

// Inbound-email ingest: an email provider posts forwarded receipt attachments
// here. Unauthenticated and CSRF-exempt (verified by HMAC signature + tenant
// token + sender allow-list inside the controller, not a session).
Route::post('inbound-email/{token}', InboundEmailController::class)->name('inbound-email.ingest');

// Content-Security-Policy violation reports. Unauthenticated (browsers post with
// no session) and CSRF-exempt; throttled per IP and hardened inside the
// controller (size cap, sampling, field allowlist). Always answers 204.
Route::post('csp-report', CspReportController::class)
    ->middleware('throttle:csp-report')
    ->name('csp-report');

require __DIR__.'/settings.php';

require __DIR__.'/admin.php';
