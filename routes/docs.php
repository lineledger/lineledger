<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('docs', 'docs/getting-started');

    Route::livewire('docs/getting-started', 'pages::docs.getting-started')->name('docs.getting-started');
    Route::livewire('docs/creating-a-company', 'pages::docs.creating-a-company')->name('docs.creating-a-company');
    Route::livewire('docs/dashboard', 'pages::docs.dashboard')->name('docs.dashboard');
    Route::livewire('docs/customers', 'pages::docs.customers')->name('docs.customers');
    Route::livewire('docs/members', 'pages::docs.members')->name('docs.members');
    Route::livewire('docs/estimates', 'pages::docs.estimates')->name('docs.estimates');
    Route::livewire('docs/sales-orders', 'pages::docs.sales-orders')->name('docs.sales-orders');
    Route::livewire('docs/recurring', 'pages::docs.recurring')->name('docs.recurring');
    Route::livewire('docs/sales-receipts', 'pages::docs.sales-receipts')->name('docs.sales-receipts');
    Route::livewire('docs/customer-portal', 'pages::docs.customer-portal')->name('docs.customer-portal');
    Route::livewire('docs/vendors', 'pages::docs.vendors')->name('docs.vendors');
    Route::livewire('docs/purchase-orders', 'pages::docs.purchase-orders')->name('docs.purchase-orders');
    Route::livewire('docs/employees', 'pages::docs.employees')->name('docs.employees');
    Route::livewire('docs/payroll', 'pages::docs.payroll')->name('docs.payroll');
    Route::livewire('docs/accounting', 'pages::docs.accounting')->name('docs.accounting');
    Route::livewire('docs/banking', 'pages::docs.banking')->name('docs.banking');
    Route::livewire('docs/inventory', 'pages::docs.inventory')->name('docs.inventory');
    Route::livewire('docs/fixed-assets', 'pages::docs.fixed-assets')->name('docs.fixed-assets');
    Route::livewire('docs/multi-currency', 'pages::docs.multi-currency')->name('docs.multi-currency');
    Route::livewire('docs/reports', 'pages::docs.reports')->name('docs.reports');
    Route::livewire('docs/budgets', 'pages::docs.budgets')->name('docs.budgets');
    Route::livewire('docs/fundraising', 'pages::docs.fundraising')->name('docs.fundraising');
    Route::livewire('docs/tax-returns', 'pages::docs.tax-returns')->name('docs.tax-returns');
    Route::livewire('docs/lists', 'pages::docs.lists')->name('docs.lists');
    Route::livewire('docs/documents', 'pages::docs.documents')->name('docs.documents');
    Route::livewire('docs/inbox', 'pages::docs.inbox')->name('docs.inbox');
    Route::livewire('docs/settings', 'pages::docs.settings')->name('docs.settings');
    Route::livewire('docs/migration', 'pages::docs.migration')->name('docs.migration');
    Route::livewire('docs/api', 'pages::docs.api')->name('docs.api');
});
