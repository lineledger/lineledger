<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Getting started')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Getting started')"
        :subheading="__('A quick tour of the app and where to find things.')"
    >
        <flux:text>
            {{ __('Welcome to your bookkeeping workspace. This guide is written like a manual: each area below walks you through what a feature is for, then gives you the exact steps — "To add a customer", "To create an invoice", "To customize your invoices" — with screenshots along the way. The examples all use a sample business, Demo Company Inc.; membership and fundraising examples use a sample charity, Demo Community Society. Start here for the lay of the land, then jump into whichever area you need.') }}
        </flux:text>

        <flux:text>
            {{ __('The whole app wears the Tidewater design system — a teal-accented palette with light and dark modes. It opens in Light the first time you sign in on a browser; under Settings → Appearance you can switch to Dark, or to System so it follows your operating-system preference. Everything below works the same in either mode.') }}
        </flux:text>

        {{-- ───────────────────────── Core concepts ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Core concepts') }}</flux:heading>
        <flux:text>
            {{ __('Every page lives inside an organization. Your accounting data, contacts, and reports are scoped to the organization you are working in, and the switcher at the top of the sidebar lists every organization you belong to under an Organizations heading, with a check mark beside the one you are in.') }}
        </flux:text>
        <flux:text>
            {{ __('Picking a different organization opens it in a new browser tab and leaves the tab you are in alone, so you can keep two organizations open side by side — say, Demo Company Inc. in one tab and Demo Community Society in the other. New organization, at the bottom of the same menu, starts the setup wizard.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/getting-started/company-switcher.png') }}"
            alt="{{ __('The organization switcher menu open at the top of the sidebar, listing Demo Company Inc. with a check mark, Demo Community Society with an open-in-new-tab icon, and a New organization link') }}"
            caption="{{ __('The organization switcher. The check mark shows where you are; choosing another organization opens it in a new tab.') }}"
        />

        <flux:text>
            {{ __('Underneath the app is a standard double-entry ledger. When you post an invoice, bill, payment, or journal entry, the system writes balanced debits and credits to the affected accounts. Reports read from those entries — nothing is calculated by hand.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('One person edits a record at a time') }}">
            {{ __('When several people work in the same organization, whoever opens a record for editing holds it until they save and leave. Anyone else who opens it sees who is editing instead of the form, and Owners and Admins can take over. There is nothing to set up — see') }}
            <a class="underline" href="{{ route('docs.settings') }}" wire:navigate>{{ __('Settings') }}</a>
            {{ __('for how edit locks behave.') }}
        </x-docs.callout>

        {{-- ────────────────── Creating your first organization ───────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Creating your first organization') }}</flux:heading>
        <flux:text>
            {{ __('A new account starts empty — you create your first organization explicitly. When you do, you choose a country (Canada or the United States). That choice seeds a starting chart of accounts, tax codes and tax agency, default payment methods, and currency, and sets the wording the app uses (Cheque vs. Check, GST/HST vs. Sales Tax). Pick carefully: the country can\'t be changed once the organization exists.') }}
        </flux:text>
        <flux:text>
            {{ __('An 8-step wizard walks you through your organization type, industry and chart of accounts, the features you want, sales tax, and how to start. See the full walkthrough — including examples for sole proprietorships, corporations, and non-profits — on the') }}
            <a class="underline" href="{{ route('docs.creating-a-company') }}" wire:navigate>{{ __('Create an organization') }}</a>
            {{ __('page. If you are moving from another system and need to carry over balances after the wizard, the') }}
            <a class="underline" href="{{ route('docs.opening-balances') }}" wire:navigate>{{ __('Opening balances') }}</a>
            {{ __('workspace is where that happens.') }}
        </flux:text>

        {{-- ─────────────────────── The sidebar at a glance ───────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('The sidebar at a glance') }}</flux:heading>
        <flux:text>
            {{ __('Everything you do lives in the left sidebar. Right under the organization switcher sit a Search… box (⌘K on a Mac, Ctrl+K on Windows) that finds invoices, bills, contacts, and more across the organization, and a small Calculator button. Dashboard and Insights always come next; below them the groups are arranged by what you are working on.') }}
        </flux:text>
        <flux:text>
            {{ __('Demo Company Inc. sees the groups below. Your own list depends on the features you turned on when you created the organization and on your role — a group you have no access to simply does not appear, and a feature you switched off takes its links with it:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Banking — Bank register, Cheques (labelled Checks in a US organization), Deposits, and Transfers.') }}</li>
            <li>{{ __('Fundraising — Donations, Grants, and, for registered charities, Donation receipts. Only organizations with fundraising turned on see this group; Demo Community Society does.') }}</li>
            <li>{{ __('Sales — Customers, Members (when membership is turned on), Estimates, Sales orders, Invoices, Invoice templates, Sales receipts, Credit memos, Recurring, and Receipts. Non-profits see this group labelled Revenues.') }}</li>
            <li>{{ __('Purchases — Vendors, Purchase orders, Bills, Vendor credits, Bill payments, Expenses, and Recurring.') }}</li>
            <li>{{ __('Inventory — Stock on hand and Adjustments.') }}</li>
            <li>{{ __('Employees — Employees and Reimbursements.') }}</li>
            <li>{{ __('Payroll — Overview, Employee setup, Staff calendar, and Pay runs, then a Reports list: Remittance history, PD7A, Workers\' comp, T4 slips, Record of Employment, and Calculation check. The group appears once payroll is turned on.') }}</li>
            <li>{{ __('Accounting — Chart of Accounts, Journal, Journal templates, Recurring entries, Fixed assets, and — for Owners only — Opening balances.') }}</li>
            <li>{{ __('Reports — All Reports and Budgets, followed by any reports you have starred, each as a link of its own.') }}</li>
            <li>{{ __('Documents — Repository and Attachment index.') }}</li>
            <li>{{ __('Inbox — Review queue, for receipts and bills you upload or email in.') }}</li>
        </ul>
        <flux:text>
            {{ __('Banking, Sales, and Purchases start expanded; the other groups start collapsed, and clicking a group heading opens or closes it — the app remembers your choice. The account menu at the very bottom of the sidebar, under your name, holds Documentation, Support, Settings, Site Admin (site administrators only), and Log out.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/getting-started/sidebar.png') }}"
            alt="{{ __('The LineLedger sidebar for Demo Company Inc. showing the organization switcher, the Search box with its ⌘K hint and the Calculator button, the Dashboard and Insights links, the Banking, Sales, Purchases, Inventory, Employees, Payroll, Accounting, Reports, Documents, and Inbox groups, and the account menu at the bottom') }}"
            caption="{{ __('The sidebar groups every area by what you are working on. The organization switcher and search sit at the top; your account menu — with Documentation, Support, and Settings — sits at the bottom.') }}"
        />

        <x-docs.callout type="tip" heading="{{ __('Snap a receipt, skip the typing') }}">
            {{ __('The Inbox is the fastest way to capture a bill or expense: drag a PDF or photo onto the Review queue, or forward it to your organization\'s email-in address once you have switched on Accept documents by email on the Inbox email settings page. That page is not listed in the Settings menu: go to /settings/inbox-email directly (the Inbox page linked below describes the other way in). With Read receipts automatically turned on, LineLedger reads the document and fills in what it found — vendor, date, amount, tax — before the item lands in Needs review; otherwise the item waits there blank for you to fill in. Nothing is created until you open the item, check the details, choose what to Create as — Vendor bill, Expense (paid now), or Employee reimbursement — and select Create draft. That opens the new draft, and you post it from the bill or expense page itself, not from the queue. The fourth choice, Match a bank transaction, is the exception: Record transaction posts the receipt straight against an imported bank line. The') }}
            <a class="underline" href="{{ route('docs.inbox') }}" wire:navigate>{{ __('Inbox') }}</a>
            {{ __('page has the details.') }}
        </x-docs.callout>

        {{-- ───────────────────── Handy keys and shortcuts ───────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Handy keys and shortcuts') }}</flux:heading>
        <flux:text>
            {{ __('A handful of small behaviours work everywhere in the app and save a surprising amount of clicking once you know they are there:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Search from anywhere — press ⌘K (Ctrl+K on Windows) or select the Search… box in the sidebar, then type at least two characters. Results are grouped by kind; select one to jump straight to it.') }}</li>
            <li>{{ __('Escape goes back — on any page, pressing Escape does what the browser\'s Back button does. Anything that is open — a dialog, a menu, a picker, a calculator tape — closes first and the page stays put, and Escape never takes you out of the app. If you have typed into a form, you are asked "Leave this page?" before anything is lost.') }}</li>
            <li>{{ __('Click anywhere in a date field to open the calendar — not just the small icon at its edge. Tabbing into a date field does not pop the calendar, so typing a date stays the fast path.') }}</li>
            <li>{{ __('Every dollar cell is a calculator — type an expression such as 1050+52.50, 100*1.13, or 250/4 and a tape drops down showing each step. Press Enter to commit the result into the cell. Plus, minus, multiply (* or ×) and divide (/ or ÷) all work with normal precedence; dividing by zero shows Error on the tape and leaves the cell alone.') }}</li>
            <li>{{ __('The sidebar Calculator — the button beside the search box opens a tape calculator you can type into or click. Its Place in field button drops the result into whichever field you were last typing in, so you can work out a figure and land it without retyping.') }}</li>
        </ul>

        <x-docs.figure
            src="{{ asset('docs/screenshots/getting-started/global-search.png') }}"
            alt="{{ __('The global search dialog open over the dashboard with a query typed and matching results grouped by kind') }}"
            caption="{{ __('Global search. Press ⌘K or Ctrl+K from any page, type a name or number, and jump straight to the record.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('Document numbers are yours to change') }}">
            {{ __('Invoices, bills, and the other numbered documents fill in the next number for you, but the number is an ordinary field: overwrite it if your numbering has a shape of its own. The app checks that the number is not already in use in the organization before it saves, so two documents can never share one.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Where to go next ────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Where to go next') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li><a class="underline" href="{{ route('docs.dashboard') }}" wire:navigate>{{ __('Dashboard') }}</a> — {{ __('snapshot of your finances.') }}</li>
            <li><a class="underline" href="{{ route('docs.insights') }}" wire:navigate>{{ __('Insights') }}</a> — {{ __('the daily insight card and the history of past insights.') }}</li>
            <li><a class="underline" href="{{ route('docs.banking') }}" wire:navigate>{{ __('Banking') }}</a> — {{ __('bank register, statement import, reconciliation, cheques.') }}</li>
            <li><a class="underline" href="{{ route('docs.fundraising') }}" wire:navigate>{{ __('Fundraising') }}</a> — {{ __('donations, grants, and official receipts.') }}</li>
            <li><a class="underline" href="{{ route('docs.customers') }}" wire:navigate>{{ __('Customers') }}</a> — {{ __('billing the people you sell to — invoices, credit memos, and automated payment reminders.') }}</li>
            <li><a class="underline" href="{{ route('docs.members') }}" wire:navigate>{{ __('Members') }}</a> — {{ __('your membership roster, dues, and renewals.') }}</li>
            <li><a class="underline" href="{{ route('docs.estimates') }}" wire:navigate>{{ __('Estimates') }}</a> — {{ __('quotes before you bill.') }}</li>
            <li><a class="underline" href="{{ route('docs.sales-orders') }}" wire:navigate>{{ __('Sales orders') }}</a> — {{ __('committed sales you fulfill over time.') }}</li>
            <li><a class="underline" href="{{ route('docs.recurring') }}" wire:navigate>{{ __('Recurring') }}</a> — {{ __('invoices and bills on a schedule. An invoice schedule can post, or post and email, each invoice automatically; a bill schedule always creates drafts for you to review.') }}</li>
            <li><a class="underline" href="{{ route('docs.sales-receipts') }}" wire:navigate>{{ __('Sales receipts') }}</a> — {{ __('record a sale that is paid on the spot.') }}</li>
            <li><a class="underline" href="{{ route('docs.customer-portal') }}" wire:navigate>{{ __('Customer portal') }}</a> — {{ __('let customers view and pay online.') }}</li>
            <li><a class="underline" href="{{ route('docs.vendors') }}" wire:navigate>{{ __('Vendors') }}</a> — {{ __('tracking what you owe.') }}</li>
            <li><a class="underline" href="{{ route('docs.purchase-orders') }}" wire:navigate>{{ __('Purchase orders') }}</a> — {{ __('what you ordered before the bill arrives.') }}</li>
            <li><a class="underline" href="{{ route('docs.inventory') }}" wire:navigate>{{ __('Inventory') }}</a> — {{ __('stock on hand, adjustments, history.') }}</li>
            <li><a class="underline" href="{{ route('docs.employees') }}" wire:navigate>{{ __('Employees') }}</a> — {{ __('reimbursements and expenses.') }}</li>
            <li><a class="underline" href="{{ route('docs.employee-portal') }}" wire:navigate>{{ __('Employee portal') }}</a> — {{ __('where your staff see their pay stubs and slips and keep their details current.') }}</li>
            <li><a class="underline" href="{{ route('docs.payroll') }}" wire:navigate>{{ __('Payroll') }}</a> — {{ __('pay runs, CPP/EI, and T4 filings (Canada).') }}</li>
            <li><a class="underline" href="{{ route('docs.accounting') }}" wire:navigate>{{ __('Accounting') }}</a> — {{ __('chart of accounts and journal entries.') }}</li>
            <li><a class="underline" href="{{ route('docs.opening-balances') }}" wire:navigate>{{ __('Opening balances') }}</a> — {{ __('carry balances over from your old books after setup.') }}</li>
            <li><a class="underline" href="{{ route('docs.fixed-assets') }}" wire:navigate>{{ __('Fixed assets') }}</a> — {{ __('the equipment and property you own.') }}</li>
            <li><a class="underline" href="{{ route('docs.multi-currency') }}" wire:navigate>{{ __('Multi-currency') }}</a> — {{ __('trade in foreign currencies.') }}</li>
            <li><a class="underline" href="{{ route('docs.reports') }}" wire:navigate>{{ __('Reports') }}</a> — {{ __('financial statements and tax reports.') }}</li>
            <li><a class="underline" href="{{ route('docs.budgets') }}" wire:navigate>{{ __('Budgets') }}</a> — {{ __('set monthly targets per account and compare to actuals.') }}</li>
            <li><a class="underline" href="{{ route('docs.tax-returns') }}" wire:navigate>{{ __('Tax returns') }}</a> — {{ __('record tax filings and payments.') }}</li>
            <li><a class="underline" href="{{ route('docs.documents') }}" wire:navigate>{{ __('Documents') }}</a> — {{ __('folders, file storage, and attachments to transactions.') }}</li>
            <li><a class="underline" href="{{ route('docs.inbox') }}" wire:navigate>{{ __('Inbox') }}</a> — {{ __('capture receipts and bills by upload or email, then post the drafts.') }}</li>
            <li><a class="underline" href="{{ route('docs.lists') }}" wire:navigate>{{ __('Lists') }}</a> — {{ __('items, tax codes, payment terms, payment methods, other names.') }}</li>
            <li><a class="underline" href="{{ route('docs.settings') }}" wire:navigate>{{ __('Settings') }}</a> — {{ __('profile, security, appearance, team members, and organization configuration.') }}</li>
            <li><a class="underline" href="{{ route('docs.migration') }}" wire:navigate>{{ __('Import from QuickBooks') }}</a> — {{ __('bring an existing set of books in.') }}</li>
            <li><a class="underline" href="{{ route('docs.api') }}" wire:navigate>{{ __('API') }}</a> — {{ __('programmatic access via REST.') }}</li>
            <li><a class="underline" href="{{ route('docs.self-hosting') }}" wire:navigate>{{ __('Self-hosting & upgrades') }}</a> — {{ __('running LineLedger on your own server and keeping it current.') }}</li>
            <li><a class="underline" href="{{ route('docs.site-administration') }}" wire:navigate>{{ __('Site administration') }}</a> — {{ __('the operator\'s portal: users, organizations, support tickets, site settings.') }}</li>
        </ul>

        <x-docs.callout type="note" heading="{{ __('Starred reports in the sidebar') }}">
            {{ __('Star a report on the All Reports page and it becomes a link of its own in the sidebar\'s Reports group, listed after All Reports and Budgets (when budgets are turned on). There is no separate heading — starred reports simply sit beneath the group\'s regular links for one-click access, and un-starring removes them. See') }}
            <a class="underline" href="{{ route('docs.reports') }}" wire:navigate>{{ __('Reports') }}</a>
            {{ __('for the full list.') }}
        </x-docs.callout>

        {{-- ─────────────────────── A typical workflow ────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('A typical workflow') }}</flux:heading>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Add customers and vendors under the Sales and Purchases groups.') }}</li>
            <li>{{ __('Configure your chart of accounts, tax codes, and payment terms.') }}</li>
            <li>{{ __('If you are switching from another system, carry over your balances as of your conversion date — the trial balance, what each customer owed you, and what you owed each vendor — under Accounting → Opening balances.') }}</li>
            <li>{{ __('Create invoices for sales — or sales receipts for sales paid on the spot — and enter bills for purchases. You can also drop receipts into the Inbox and post the drafts it prepares.') }}</li>
            <li>{{ __('Record receipts and bill payments as money moves.') }}</li>
            <li>{{ __('Reconcile your bank accounts at month end.') }}</li>
            <li>{{ __('Run reports to review performance and file taxes.') }}</li>
        </ol>

        {{-- ─────────────────────────── Make it yours ─────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Make it yours') }}</flux:heading>
        <flux:text>
            {{ __('Two pages under Settings shape how the app looks and feels for you alone — nothing on them affects your teammates. Open Settings from the account menu at the bottom of the sidebar.') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Settings → Appearance — pick a Theme (Light, Dark, or System, which follows your operating system), choose a Calculator mode for the sidebar calculator (Standard, or Adding machine, which works like an accountant\'s 10-key with a running total and a Total key), and switch Escape goes back on or off.') }}</li>
            <li>{{ __('Settings → Sidebar — a switch for every group below Dashboard and Insights, and for every link in those groups. Dashboard and Insights have no switch and always show; starred reports have no switch either — un-star a report to take it off the sidebar. Turn off a whole group and all of its links go with it; select Save to apply, or Reset to defaults to bring everything back.') }}</li>
        </ul>
        <flux:text>
            {{ __('Once you have hidden something, a Show all sections button appears at the bottom of the sidebar. It reveals everything temporarily — handy when you need a page you tucked away — and turns into Show fewer to put your tidy sidebar back, without touching your saved choices. See') }}
            <a class="underline" href="{{ route('docs.settings') }}" wire:navigate>{{ __('Settings') }}</a>
            {{ __('for everything else you can configure.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/getting-started/appearance-settings.png') }}"
            alt="{{ __('Settings → Appearance showing the Theme choice of Light, Dark, and System, the Calculator mode options Standard and Adding machine, and the Escape goes back switch') }}"
            caption="{{ __('Settings → Appearance. Theme, the sidebar calculator\'s mode, and the Escape goes back switch are all per person.') }}"
        />

        <flux:text>
            {{ __('Anyone can confirm the books always balance on the public') }}
            <a class="underline" href="{{ route('verification') }}">{{ __('Verification') }}</a>
            {{ __('page — no login needed. It shows the results of automated accounting tests run against sample books: that the trial balance balances, ties to the balance sheet and income statement, and that the audit log verifies end to end, with the source data there to download and check yourself.') }}
        </flux:text>

        {{-- ─────────────────────────── Getting help ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Getting help') }}</flux:heading>
        <flux:text>
            {{ __('The account menu at the bottom of the sidebar is where help lives. Documentation opens these pages. Support opens your ticket list, where you can ask a question, report a problem, or suggest a feature and read the replies — tickets are reviewed and typically answered within 24 hours. Site administrators also see a Site Admin link to the operator\'s portal.') }}
        </flux:text>

        <p><strong>{{ __('To open a support ticket:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the account menu at the bottom of the sidebar and select Support.') }}</li>
            <li>{{ __('Select New ticket.') }}</li>
            <li>{{ __('Enter a Subject, choose a Type — General, Bug or issue, or Feature request — and describe what you need under How can we help?') }}</li>
            <li>{{ __('Select Submit ticket. You land on the ticket itself, where every reply appears as a thread.') }}</li>
        </ol>
        <flux:text>
            {{ __('When someone replies, a blue dot appears on your avatar in the sidebar and a count badge beside Support in the account menu. Open the ticket, read the reply, and answer with Add a reply → Send reply. Feature ideas go in the same way — choose the Feature request type and they reach the same queue.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/getting-started/account-menu.png') }}"
            alt="{{ __('The account menu open at the bottom of the sidebar, showing the signed-in user, then Documentation, Support with an unread-reply badge, Settings, Site Admin, and Log out') }}"
            caption="{{ __('The account menu. Support shows a badge when a ticket has a reply you have not read yet.') }}"
        />

        <flux:text>
            {{ __('Running LineLedger on your own server? The') }}
            <a class="underline" href="{{ route('docs.self-hosting') }}" wire:navigate>{{ __('Self-hosting & upgrades') }}</a>
            {{ __('page covers installation and updates, and') }}
            <a class="underline" href="{{ route('docs.site-administration') }}" wire:navigate>{{ __('Site administration') }}</a>
            {{ __('explains the portal where support tickets are answered and organizations are managed.') }}
        </flux:text>
    </x-pages::docs.layout>
</section>
