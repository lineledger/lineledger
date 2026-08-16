<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Getting started')"
        :subheading="__('A quick tour of the app and where to find things.')"
    >
        <flux:text>
            {{ __('Welcome to your bookkeeping workspace. This guide is written like a manual: each area below walks you through what a feature is for, then gives you the exact steps — "To add a customer", "To create an invoice", "To customize your invoices" — with screenshots along the way. The examples all use a sample business, Demo Company Inc., so you can follow along. Start here for the lay of the land, then jump into whichever area you need.') }}
        </flux:text>

        <flux:text>
            {{ __('The whole app wears the Tidewater design system — a teal-accented palette with light and dark modes, picked automatically from your operating-system preference. You can keep it as is; everything below works the same in either mode.') }}
        </flux:text>

        <flux:heading size="lg" class="mt-8">{{ __('Core concepts') }}</flux:heading>
        <flux:text>
            {{ __('Every page lives inside a company. The selector at the top of the sidebar switches between any companies you belong to. Your accounting data, contacts, and reports are scoped to the active company.') }}
        </flux:text>

        <flux:heading size="lg" class="mt-8">{{ __('Creating your first company') }}</flux:heading>
        <flux:text>
            {{ __('A new account starts empty — you create your first company explicitly. When you do, you choose a country (Canada or the United States). That choice seeds a starting chart of accounts, tax codes and tax agency, default payment methods, and currency, and sets the wording the app uses (Cheque vs. Check, GST/HST vs. Sales Tax). Pick carefully: the country can\'t be changed once the company exists.') }}
        </flux:text>
        <flux:text>
            {{ __('Underneath the app is a standard double-entry ledger. When you post an invoice, bill, payment, or journal entry, the system writes balanced debits and credits to the affected accounts. Reports read from those entries — nothing is calculated by hand.') }}
        </flux:text>
        <flux:text>
            {{ __('When you create an organization, an 8-step wizard walks you through your organization type, chart of accounts, the features you want, sales tax, and how to start. See the full walkthrough — including examples for sole proprietorships, corporations, and non-profits — on the') }}
            <a class="underline" href="{{ route('docs.creating-a-company') }}" wire:navigate>{{ __('Create an organization') }}</a>
            {{ __('page.') }}
        </flux:text>

        <flux:heading size="lg" class="mt-8">{{ __('The sidebar at a glance') }}</flux:heading>
        <flux:text>
            {{ __('Everything you do lives in the left sidebar, grouped by what you are working on. Demo Company Inc. sees the groups below — your own list depends on the features you turned on when you created the company and on your role:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Banking — bank register, cheques, deposits, and transfers between accounts.') }}</li>
            <li>{{ __('Sales — customers, estimates, sales orders, invoices, invoice templates, sales receipts, credit memos, recurring invoices, and customer receipts. (Non-profits see this group as Revenues.)') }}</li>
            <li>{{ __('Purchases — vendors, purchase orders, bills, vendor credits, bill payments, expenses, and recurring bills.') }}</li>
            <li>{{ __('Inventory — stock on hand and adjustments.') }}</li>
            <li>{{ __('Employees — employee records and expense reimbursements.') }}</li>
            <li>{{ __('Payroll — pay runs, CPP / EI, and T4 / PD7A / ROE filings (Canada).') }}</li>
            <li>{{ __('Accounting — chart of accounts, the journal, journal templates, recurring entries, and fixed assets.') }}</li>
            <li>{{ __('Reports — every financial statement and tax report, budgets, and a Favorites quick-list.') }}</li>
            <li>{{ __('Documents — your file repository and an index of everything attached to transactions.') }}</li>
            <li>{{ __('Inbox — a review queue for receipts and bills you upload or email in.') }}</li>
        </ul>
        <flux:text>
            {{ __('Registered charities and non-profits also get a Fundraising group for donations, grants, and official donation receipts. Anything you do not use, you can hide — see "Make it yours" below.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/getting-started/sidebar.png') }}"
            alt="{{ __('The LineLedger sidebar showing the Banking, Sales, Purchases, Accounting, Reports, Documents, and Inbox groups') }}"
            caption="{{ __('The sidebar groups every area by what you are working on. The company selector sits at the top; your account menu — with Support and Feature Requests — sits at the bottom.') }}"
        />

        <x-docs.callout type="tip" heading="{{ __('Snap a receipt, skip the typing') }}">
            {{ __('The Inbox is the fastest way to capture a bill or expense: drag a PDF or photo onto the review queue, or forward it to your company email-in address (set one up under Settings → Inbox email). With the optional document reader turned on, LineLedger reads the vendor, date, and amount and prepares a draft bill or expense; otherwise it stages the file so you can fill it in. Either way you review and post the draft from the queue.') }}
        </x-docs.callout>

        <flux:heading size="lg" class="mt-8">{{ __('Where to go next') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li><a class="underline" href="{{ route('docs.dashboard') }}" wire:navigate>{{ __('Dashboard') }}</a> — {{ __('snapshot of your finances.') }}</li>
            <li><a class="underline" href="{{ route('docs.customers') }}" wire:navigate>{{ __('Customers') }}</a> — {{ __('billing the people you sell to — invoices, credit memos, and automated payment reminders.') }}</li>
            <li><a class="underline" href="{{ route('docs.members') }}" wire:navigate>{{ __('Members') }}</a> — {{ __('your membership roster, dues, and renewals.') }}</li>
            <li><a class="underline" href="{{ route('docs.estimates') }}" wire:navigate>{{ __('Estimates') }}</a> — {{ __('quotes before you bill.') }}</li>
            <li><a class="underline" href="{{ route('docs.sales-orders') }}" wire:navigate>{{ __('Sales orders') }}</a> — {{ __('committed sales you fulfill over time.') }}</li>
            <li><a class="underline" href="{{ route('docs.recurring') }}" wire:navigate>{{ __('Recurring') }}</a> — {{ __('invoices and bills on a schedule, with optional auto-send and auto-post.') }}</li>
            <li><a class="underline" href="{{ route('docs.sales-receipts') }}" wire:navigate>{{ __('Sales receipts') }}</a> — {{ __('record a sale that is paid on the spot.') }}</li>
            <li><a class="underline" href="{{ route('docs.customer-portal') }}" wire:navigate>{{ __('Customer portal') }}</a> — {{ __('let customers view and pay online.') }}</li>
            <li><a class="underline" href="{{ route('docs.vendors') }}" wire:navigate>{{ __('Vendors') }}</a> — {{ __('tracking what you owe.') }}</li>
            <li><a class="underline" href="{{ route('docs.purchase-orders') }}" wire:navigate>{{ __('Purchase orders') }}</a> — {{ __('what you ordered before the bill arrives.') }}</li>
            <li><a class="underline" href="{{ route('docs.employees') }}" wire:navigate>{{ __('Employees') }}</a> — {{ __('reimbursements and expenses.') }}</li>
            <li><a class="underline" href="{{ route('docs.payroll') }}" wire:navigate>{{ __('Payroll') }}</a> — {{ __('pay runs, CPP/EI, and T4 filings (Canada).') }}</li>
            <li><a class="underline" href="{{ route('docs.accounting') }}" wire:navigate>{{ __('Accounting') }}</a> — {{ __('chart of accounts and journal entries.') }}</li>
            <li><a class="underline" href="{{ route('docs.banking') }}" wire:navigate>{{ __('Banking') }}</a> — {{ __('bank register, reconciliation, cheques.') }}</li>
            <li><a class="underline" href="{{ route('docs.inventory') }}" wire:navigate>{{ __('Inventory') }}</a> — {{ __('stock on hand, adjustments, history.') }}</li>
            <li><a class="underline" href="{{ route('docs.fixed-assets') }}" wire:navigate>{{ __('Fixed assets') }}</a> — {{ __('the equipment and property you own.') }}</li>
            <li><a class="underline" href="{{ route('docs.multi-currency') }}" wire:navigate>{{ __('Multi-currency') }}</a> — {{ __('trade in foreign currencies.') }}</li>
            <li><a class="underline" href="{{ route('docs.budgets') }}" wire:navigate>{{ __('Budgets') }}</a> — {{ __('set monthly targets per account and compare to actuals.') }}</li>
            <li><a class="underline" href="{{ route('docs.fundraising') }}" wire:navigate>{{ __('Fundraising') }}</a> — {{ __('donations, grants, and official receipts.') }}</li>
            <li><a class="underline" href="{{ route('docs.tax-returns') }}" wire:navigate>{{ __('Tax returns') }}</a> — {{ __('record tax filings and payments.') }}</li>
            <li><a class="underline" href="{{ route('docs.reports') }}" wire:navigate>{{ __('Reports') }}</a> — {{ __('financial statements and tax reports.') }}</li>
            <li><a class="underline" href="{{ route('docs.documents') }}" wire:navigate>{{ __('Documents') }}</a> — {{ __('folders, file storage, and attachments to transactions.') }}</li>
            <li><a class="underline" href="{{ route('docs.inbox') }}" wire:navigate>{{ __('Inbox') }}</a> — {{ __('capture receipts and bills by upload or email, then post the drafts.') }}</li>
            <li><a class="underline" href="{{ route('docs.lists') }}" wire:navigate>{{ __('Lists') }}</a> — {{ __('items, tax codes, payment terms.') }}</li>
            <li><a class="underline" href="{{ route('docs.settings') }}" wire:navigate>{{ __('Settings') }}</a> — {{ __('profile, security, company configuration.') }}</li>
            <li><a class="underline" href="{{ route('docs.migration') }}" wire:navigate>{{ __('Import from QuickBooks') }}</a> — {{ __('bring an existing company in.') }}</li>
            <li><a class="underline" href="{{ route('docs.api') }}" wire:navigate>{{ __('API') }}</a> — {{ __('programmatic access via REST.') }}</li>
        </ul>

        <x-docs.callout type="note" heading="{{ __('Reports favorites in the sidebar') }}">
            {{ __('The Reports group keeps an All Reports link, with a Favorites quick-list right underneath it. Star the reports you run most often and they show up there for one-click access. See') }}
            <a class="underline" href="{{ route('docs.reports') }}" wire:navigate>{{ __('Reports') }}</a>
            {{ __('for the full list.') }}
        </x-docs.callout>

        <flux:heading size="lg" class="mt-8">{{ __('A typical workflow') }}</flux:heading>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Add customers and vendors under the Sales and Purchases groups.') }}</li>
            <li>{{ __('Configure your chart of accounts, tax codes, and payment terms.') }}</li>
            <li>{{ __('Create invoices for sales — or sales receipts for sales paid on the spot — and enter bills for purchases. You can also drop receipts into the Inbox and post the drafts it prepares.') }}</li>
            <li>{{ __('Record receipts and bill payments as money moves.') }}</li>
            <li>{{ __('Reconcile your bank accounts at month end.') }}</li>
            <li>{{ __('Run reports to review performance and file taxes.') }}</li>
        </ol>

        <flux:heading size="lg" class="mt-8">{{ __('Make it yours') }}</flux:heading>
        <flux:text>
            {{ __('You can show or hide sidebar links and whole sections to match how you work, and switch between light and dark themes — both live under') }}
            <a class="underline" href="{{ route('docs.settings') }}" wire:navigate>{{ __('Settings → Sidebar') }}</a>.
        </flux:text>
        <flux:text>
            {{ __('Anyone can confirm the books always balance on the public') }}
            <a class="underline" href="/verification">{{ __('Verification') }}</a>
            {{ __('page.') }}
        </flux:text>

        <flux:heading size="lg" class="mt-8">{{ __('Getting help') }}</flux:heading>
        <flux:text>
            {{ __('If something is not covered here, two links live in the account menu at the bottom of the sidebar:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li><a class="underline" href="https://lineledger.ca/support" target="_blank" rel="noopener">{{ __('Support') }}</a> — {{ __('get help with a problem or a question.') }}</li>
            <li><a class="underline" href="https://lineledger.ca/requests" target="_blank" rel="noopener">{{ __('Feature Requests') }}</a> — {{ __('suggest an improvement or vote on what we build next.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
