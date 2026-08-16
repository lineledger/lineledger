<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Import from QuickBooks')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Import from QuickBooks')"
        :subheading="__('Bring an existing QuickBooks company in as a step-by-step wizard.')"
    >
        <flux:text>
            {{ __('The migration tool brings a QuickBooks company into the app from exported files. It runs as a wizard: a numbered step list on the left, and the current step on the right. You upload one file per step, preview what will be created, and commit before moving on. The run is resumable — leave and come back and it picks up where you left off. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        <flux:text>
            {{ __('You normally start an import when you create a new organization: pick "Import from QuickBooks" in the setup wizard. While a run is unfinished the app keeps a link to it under Settings → Organizations → Import from QuickBooks, and shows a "Resume import" reminder on your dashboard, so you can step away and come back any time.') }}
        </flux:text>

        <flux:heading size="lg" class="mt-8">{{ __('What the importer does and does not do') }}</flux:heading>
        <flux:text>
            {{ __('The importer moves your chart of accounts, customers, vendors, items, open invoices and bills, inventory, fixed assets, and account balances. What it deliberately does not reconstruct is the link between a customer payment and the specific invoice it paid — QuickBooks’ exported files do not carry that linkage, so the app keeps the import general-ledger-driven rather than guessing which receipt cleared which invoice.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('General-ledger driven by design') }}">
            {{ __('Balances and journal entries come across faithfully, but payment-to-invoice matching does not. After an import your account totals tie out exactly; individual documents may show as open even where the original was paid. This is intentional — inventing those links from incomplete data would produce wrong history. (The full-history "Reconstruct documents" option can rebuild invoices and bills and auto-apply payments oldest-first, but that is a best-effort heuristic, not the original linkage.)') }}
        </x-docs.callout>

        <flux:heading size="lg" class="mt-8">{{ __('Choose an import mode') }}</flux:heading>
        <flux:text>
            {{ __('Two buttons at the top of the page choose how much QuickBooks history you bring across. Demo Company Inc. picks one and the step list below rebuilds to match. Switching is instant — there is no separate "save" for the mode — and a toast confirms the change.') }}
        </flux:text>

        <ul class="list-disc ps-6 space-y-1">
            <li><strong>{{ __('Opening balances') }}</strong> — {{ __('the default conversion. You set a conversion date and import a trial balance, chart of accounts, and the customer, vendor, and item lists. History before the conversion date is not loaded; one consolidated opening journal entry lifts the books to that day, and finishing locks the company through that date.') }}</li>
            <li><strong>{{ __('Full transaction history') }}</strong> — {{ __('replays every historical journal entry from your QuickBooks general ledger, so every past transaction posts in detail. Use it when you want complete reportable history rather than just a starting point. You can upload several ledger files in one go — split exports for large date ranges are merged on import.') }}</li>
        </ul>

        <x-docs.figure
            src="{{ asset('docs/screenshots/migration/mode-select.png') }}"
            alt="{{ __('The Import from QuickBooks page with Opening balances and Full transaction history mode buttons at the top') }}"
            caption="{{ __('The two mode buttons sit at the top of the page. Whichever you pick rebuilds the numbered step list below.') }}"
        />

        <x-docs.callout type="note">
            {{ __('You cannot switch modes once data has been imported — the app shows a warning toast and keeps the current mode. If you change your mind, select Abandon migration at the bottom of the step list and start over.') }}
        </x-docs.callout>

        <flux:heading size="lg" class="mt-8">{{ __('Step 1 — Setup') }}</flux:heading>
        <flux:text>
            {{ __('Setup is always the first step, and what it asks depends on the mode. In Opening balances mode it collects the conversion date and the date strategy; in Full transaction history mode it collects an optional history start date and a few matching options. Select Save & continue to move on to the Chart of accounts step.') }}
        </flux:text>

        <p><strong>{{ __('To complete Setup in Opening balances mode:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Confirm the Conversion date — usually your QuickBooks fiscal year-end. The wizard pre-fills it with your most recent fiscal year-end; change it if you convert on a different day.') }}</li>
            <li>{{ __('Leave "Use original invoice dates" ticked to keep each open AR invoice on its real date so aging buckets stay accurate. Untick to date all open invoices as the conversion day.') }}</li>
            <li>{{ __('Do the same for "Use original bill dates" for open AP bills.') }}</li>
            <li>{{ __('Select Save & continue.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/migration/conversion-date.png') }}"
            alt="{{ __('The Setup step in Opening balances mode showing the conversion date field and the use-original-dates toggles') }}"
            caption="{{ __('The Setup step in Opening balances mode. The conversion date is your starting line; pre-conversion entries are locked once the run finishes.') }}"
        />

        <flux:text>
            {{ __('In Full transaction history mode the Setup step looks different: instead of a conversion date it offers an optional History start date and three options — Auto-create accounts found in the file but not in the chart, Link transaction names to customers and vendors, and Reconstruct documents (invoices, bills, cheques, deposits, receipts) where possible. These shape how the ledger replay later in the run handles unknown accounts, names, and document types.') }}
        </flux:text>

        <flux:heading size="lg" class="mt-8">{{ __('Work through the remaining steps') }}</flux:heading>
        <flux:text>
            {{ __('After Setup, work down the step list. The list adapts to your mode — the inventory, fixed-asset, and trial-balance steps appear only in Opening balances mode, and the Transaction history step appears only in Full transaction history mode, because the replayed ledger already carries that detail.') }}
        </flux:text>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Move down the step list — Chart of accounts, Control accounts, Customers, Vendors, and so on. Select any step in the list to jump to it.') }}</li>
            <li>{{ __('At each upload step, select Download template, fill it from your QuickBooks export, choose the file, then Preview to see what will be created.') }}</li>
            <li>{{ __('Select Commit to write that step, or Skip this step if it does not apply.') }}</li>
            <li>{{ __('Finish on Review & finish, where you confirm the totals and finalize the run.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/migration/import.png') }}"
            alt="{{ __('The Import from QuickBooks wizard showing the mode buttons, numbered step list, and the current step') }}"
            caption="{{ __('The import wizard. The numbered list on the left tracks your progress — a green check marks finished steps; the panel on the right is the current step.') }}"
        />

        <flux:heading size="lg" class="mt-8">{{ __('The steps') }}</flux:heading>
        <flux:text>
            {{ __('The wizard adapts the step list to the mode you chose. A typical run moves through:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Setup — conversion date and date strategy (Opening balances), or history start date and matching options (Full transaction history).') }}</li>
            <li>{{ __('Chart of accounts — optional; add your QuickBooks accounts on top of the seeded chart. Existing codes are skipped and new ones added.') }}</li>
            <li>{{ __('Control accounts — confirm which accounts act as Accounts Receivable, Accounts Payable, Undeposited Funds, Sales Tax Payable, Employee Reimbursements Payable, Inventory Asset, Cost of Goods Sold, Retained Earnings, and Opening Balance Equity.') }}</li>
            <li>{{ __('Customers and Vendors — import the contact lists.') }}</li>
            <li>{{ __('Items — Opening balances only: import products and services with their accounts.') }}</li>
            <li>{{ __('Transaction history — Full transaction history only: replay every transaction as a balanced journal entry, de-duplicated and reconciled for rounding.') }}</li>
            <li>{{ __('Open invoices and Open bills — load the unpaid documents outstanding at the conversion date so you have live AR and AP aging.') }}</li>
            <li>{{ __('Inventory on hand and Fixed assets — Opening balances only: seed stock quantities and costs, and the asset register with cost and accumulated depreciation.') }}</li>
            <li>{{ __('Trial balance — Opening balances only: post the remaining account balances so the books open in balance.') }}</li>
            <li>{{ __('Review &amp; finish — confirm the totals and finalize the run.') }}</li>
        </ul>

        <x-docs.callout type="tip">
            {{ __('On the Control accounts step you can point a role at any imported account. Choosing an account whose type does not match the system role re-types it on the spot — pick an Income account to fill the Accounts Receivable role, for example, and the app converts that account to Accounts Receivable so it can act as the AR control. Useful when the QuickBooks chart had an off-type account you want to promote. Select Keep defaults to accept the seeded control accounts unchanged.') }}
        </x-docs.callout>

        <flux:heading size="lg" class="mt-8">{{ __('Full transaction history: replaying your ledger') }}</flux:heading>
        <flux:text>
            {{ __('In Full transaction history mode the Transaction history step replaces the opening-balance steps. QuickBooks usually exports the ledger as one file per year (or per quarter for big companies), so the upload accepts several files at once and merges them into a single replay. The replay runs as a background job, so a queue worker must be running and the page polls for progress while it works.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('Export the Journal report, not the General Ledger report') }}">
            {{ __('In QuickBooks Desktop choose Reports → Accountant & Taxes → Journal, set the date range to All, then Export → CSV. The Journal report lists each transaction with its split lines and separate Debit and Credit columns, which the importer needs. The General Ledger report is organised by account with a single signed Amount column and cannot be imported directly. A native IIF file also works.') }}
        </x-docs.callout>

        <p><strong>{{ __('To replay your transaction history:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('On the Transaction history step, choose the Source format — Journal CSV or IIF file.') }}</li>
            <li>{{ __('Drag every exported file into the drop zone, or click it to choose them — select them all at once and they import together, in date order. Up to 100 MB each.') }}</li>
            <li>{{ __('If you turned on Auto-create accounts and are uploading Journal CSVs, optionally attach a QuickBooks Account Listing so auto-created accounts are typed from it instead of defaulting to Other Asset.') }}</li>
            <li>{{ __('Select Preview to check the row counts, then Import history. Each historical journal entry posts on its original date, with refunds and rounding adjustments handled automatically; if Reconstruct documents is on, recognised transaction types become real invoices, bills, cheques, deposits, and receipts.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/migration/gl-bulk-upload.png') }}"
            alt="{{ __('The Transaction history step with a drag-and-drop zone accepting multiple Journal CSV or IIF files') }}"
            caption="{{ __('The Transaction history step accepts several files at once. Drop them all in together; the replay runs in the background and the page updates as transactions post.') }}"
        />

        <flux:heading size="lg" class="mt-8">{{ __('The trial balance: opening the books in balance') }}</flux:heading>
        <flux:text>
            {{ __('In Opening balances mode the final upload is the Trial balance. Its template has three columns — account code, debit, credit. The importer matches each row to a company account by code, then posts a single journal entry on the conversion date that brings those accounts to their QuickBooks balances. Any difference goes to Opening Balance Equity so the entry always balances.') }}
        </flux:text>

        <p><strong>{{ __('To import the trial balance:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Select Download template and fill it from your QuickBooks trial-balance export — one row per account, with the amount in either the debit or credit column.') }}</li>
            <li>{{ __('Upload the file and select Preview. The preview lists each matched account with its debit or credit, and flags any code it cannot find in your chart.') }}</li>
            <li>{{ __('Select Commit. The importer posts the opening entry; zero-balance rows are skipped, and any leftover difference lands in Opening Balance Equity.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/migration/tb-mapping.png') }}"
            alt="{{ __('The trial-balance preview listing each imported account code with its debit or credit amount') }}"
            caption="{{ __('The trial-balance preview. Rows are matched to your accounts by code; the difference is plugged to Opening Balance Equity so the books open in balance.') }}"
        />

        <x-docs.callout type="note">
            {{ __('Accounts Receivable, Accounts Payable, Inventory, and Accumulated Depreciation rows are rejected on the trial balance — the preview explains why for each one. Their detail has to come through the dedicated importers (open invoices, open bills, inventory on hand, fixed assets) so each customer, vendor, item, and asset stays tied to its balance.') }}
        </x-docs.callout>

        <flux:heading size="lg" class="mt-8">{{ __('Options worth knowing') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li><strong>{{ __('Use original dates') }}</strong> — {{ __('preserve each open invoice or bill\'s real date instead of dating it to the conversion day, so aging stays accurate. Set per document type on the Setup step.') }}</li>
            <li><strong>{{ __('Auto-create accounts') }}</strong> — {{ __('let the replay add an account on the fly when a file references a code that does not exist yet. For Journal CSVs, attach an Account Listing so those accounts are typed correctly; otherwise they default to Other Asset and should be reviewed.') }}</li>
            <li><strong>{{ __('Link transaction names to customers and vendors') }}</strong> — {{ __('match a replayed transaction to a customer or vendor by name when no exact reference is found.') }}</li>
            <li><strong>{{ __('Reconstruct documents') }}</strong> — {{ __('turn recognised transaction types into real invoices, bills, cheques, deposits, and receipts during the replay; everything else stays a plain journal entry.') }}</li>
        </ul>

        <flux:heading size="lg" class="mt-8">{{ __('Templates and previews') }}</flux:heading>
        <flux:text>
            {{ __('Each upload step offers a downloadable CSV template so your file lines up with what the importer expects. Preview never writes anything — it shows row counts and any warnings so you can fix the file and re-upload. Commit is what actually writes that step to your books. Files come in as exported, so the importer cleans up common QuickBooks quirks (byte-order marks, mixed encodings, stray characters) on the way in.') }}
        </flux:text>

        <flux:heading size="lg" class="mt-8">{{ __('Finishing, locking, and abandoning') }}</flux:heading>
        <flux:text>
            {{ __('The Review & finish step links you to the Trial Balance, AR Aging, and AP Aging reports so you can confirm the totals match QuickBooks before you commit to the conversion.') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('In Opening balances mode, select Finalize & lock. This sets the company lock date to the conversion date, so no new postings dated on or before it will be accepted.') }}</li>
            <li>{{ __('In Full transaction history mode, select Finish import. Locking is optional — tick "Lock everything on or before the history start date" only if you want to freeze the imported history; leave it off to keep the books fully open.') }}</li>
        </ul>

        <x-docs.callout type="warning">
            {{ __('Each step commits as you go, so the wizard is not all-or-nothing. If you select Abandon migration in an Opening balances run, the data you already imported stays in the company; in a Full transaction history run, the replayed transactions are removed. Either way you can then start a fresh run.') }}
        </x-docs.callout>
    </x-pages::docs.layout>
</section>
