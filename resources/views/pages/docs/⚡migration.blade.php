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
            {{ __('The import starts when you create a new organization: on the setup wizard’s How do you want to start? step, pick Import from QuickBooks and the wizard opens the import right after it creates the organization. While a run is unfinished, the app keeps a link to it under Settings → Company → Import from QuickBooks (the item appears only while an import is in progress) and shows a Finish setting up your company banner on your dashboard with a Continue import button, so you can step away and come back any time.') }}
        </flux:text>

        {{-- ───────────── Import vs. opening balances ───────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Importing from QuickBooks vs. entering opening balances') }}</flux:heading>
        <flux:text>
            {{ __('There are two ways to bring old books into a new organization, and they are chosen at different moments. Pick the one that matches where you are:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li><strong>{{ __('Import from QuickBooks') }}</strong> — {{ __('this page. Chosen in the setup wizard when the organization is created. It reads QuickBooks exports (lists, open documents, a trial balance, or the whole journal) and writes them in one guided run, then finalizes it. An Opening balances run always locks the books through its conversion date; a Full transaction history run locks only if you tick the lock box when you finish. Best when you have QuickBooks Desktop exports in hand and want the lists and history loaded for you.') }}</li>
            <li><strong>{{ __('Opening balances') }}</strong> — {{ __('the workspace under Accounting → Opening balances in the sidebar, available to Owners on every organization, however it was started — including one created with Import from QuickBooks. It holds a draft trial balance you type or import, opening balances per customer and vendor, outstanding cheques and deposits in transit, and inventory and fixed-asset imports, across six tabs — Overview, Trial balance, Customers (AR), Vendors (AP), Outstanding cheques, and Deposits in transit. One opening journal entry follows every save, nothing locks until you select Finalize & lock, and Un-finalize reopens it. Best when you are coming from any other system, or want to enter and adjust balances over time rather than in one sitting. See') }}
                <a class="underline" href="{{ route('docs.opening-balances') }}" wire:navigate>{{ __('Opening balances') }}</a>{{ __('.') }}</li>
        </ul>

        <x-docs.callout type="note">
            {{ __('There is no menu entry for starting a fresh QuickBooks import on an organization that already exists — the wizard is offered only while you are creating one. If Demo Company Inc. was created with Start fresh, use the Opening balances workspace to carry the balances over. Creating the organization itself is covered in') }}
            <a class="underline" href="{{ route('docs.creating-a-company') }}" wire:navigate>{{ __('Create an organization') }}</a>{{ __('.') }}
        </x-docs.callout>

        {{-- ───────────── What it does ───────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('What the importer does and does not do') }}</flux:heading>
        <flux:text>
            {{ __('The importer moves your chart of accounts, customers, vendors, items, open invoices and bills, inventory, fixed assets, and account balances. What it deliberately does not reconstruct is the link between a customer payment and the specific invoice it paid — QuickBooks’ exported files do not carry that linkage, so the app keeps the import general-ledger-driven rather than guessing which receipt cleared which invoice.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('General-ledger driven by design') }}">
            {{ __('Balances and journal entries come across faithfully, but payment-to-invoice matching does not. After an import your account totals tie out exactly; individual documents may show as open even where the original was paid. This is intentional — inventing those links from incomplete data would produce wrong history. (The full-history Reconstruct documents option can rebuild invoices and bills and auto-apply payments oldest-first, but that is a best-effort heuristic, not the original linkage.)') }}
        </x-docs.callout>

        {{-- ───────────── Mode ───────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Choose an import mode') }}</flux:heading>
        <flux:text>
            {{ __('The mode decides how much QuickBooks history you bring across. On the recommended path you choose it in the setup wizard: once you pick Import from QuickBooks, a second question, What do you want to import?, offers Opening balances / trial balance and Full transaction history. The import then opens inside the wizard already set to that mode.') }}
        </flux:text>

        <ul class="list-disc ps-6 space-y-1">
            <li><strong>{{ __('Opening balances') }}</strong> — {{ __('the default conversion. You set a conversion date and import a trial balance, chart of accounts, and the customer, vendor, and item lists. History before the conversion date is not loaded; one consolidated opening journal entry lifts the books to that day, and finishing locks the organization through that date.') }}</li>
            <li><strong>{{ __('Full transaction history') }}</strong> — {{ __('replays every historical journal entry from your QuickBooks general ledger, so every past transaction posts in detail. Use it when you want complete reportable history rather than just a starting point. You can upload several ledger files in one go — split exports for large date ranges are merged on import.') }}</li>
        </ul>

        <x-docs.figure
            src="{{ asset('docs/screenshots/migration/setup-wizard-mode.png') }}"
            alt="{{ __('The setup wizard’s How do you want to start? step with Import from QuickBooks selected and the What do you want to import? choice between Opening balances / trial balance and Full transaction history') }}"
            caption="{{ __('The mode is chosen in the setup wizard. Pick Import from QuickBooks, then answer What do you want to import? — the import opens inside the wizard already set to that mode.') }}"
        />

        <flux:text>
            {{ __('When you come back to an unfinished run from the dashboard or from Settings, the import opens as its own page. There the same two modes are buttons at the top — Opening balances and Full transaction history — and Demo Company Inc. can switch between them: the step list below rebuilds to match and a toast confirms the change. There is no separate save for the mode.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/migration/mode-select.png') }}"
            alt="{{ __('The Import from QuickBooks page opened from Settings, with the Opening balances and Full transaction history mode buttons above the numbered step list') }}"
            caption="{{ __('On the standalone Import from QuickBooks page the two mode buttons sit at the top. Whichever you pick rebuilds the numbered step list below.') }}"
        />

        <x-docs.callout type="note">
            {{ __('You cannot switch modes once any step after Setup has been committed or skipped — the app shows a warning toast and keeps the current mode. The only way out of the mode you are in is Abandon migration at the bottom of the step list on the standalone page, and that ends the run: the app offers no button or menu to start another one afterwards (see Finishing, locking, and abandoning below). Inside the setup wizard that button reads Finish later instead: it parks the run and takes you to the dashboard, where Continue import reopens it as a page.') }}
        </x-docs.callout>

        {{-- ───────────── Setup ───────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Step 1 — Setup') }}</flux:heading>
        <flux:text>
            {{ __('Setup is always the first step, and what it asks depends on the mode. In Opening balances mode it collects the conversion date and the date strategy; in Full transaction history mode it collects an optional history start date and three matching options. Select Save & continue to move on to the Chart of accounts step. A click anywhere in a date field opens the calendar.') }}
        </flux:text>

        <p><strong>{{ __('To complete Setup in Opening balances mode:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Confirm the Conversion date — usually your QuickBooks fiscal year-end. The wizard pre-fills it with your most recent fiscal year-end; change it if you convert on a different day.') }}</li>
            <li>{{ __('Leave “Use original invoice dates when importing open AR invoices” ticked to keep each open invoice on its real date so aging buckets stay accurate. Untick it to date every imported invoice as the conversion day.') }}</li>
            <li>{{ __('Do the same with “Use original bill dates when importing open AP bills” for open bills.') }}</li>
            <li>{{ __('Select Save & continue.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/migration/conversion-date.png') }}"
            alt="{{ __('The Setup step in Opening balances mode showing the Conversion date field and the two use-original-dates checkboxes') }}"
            caption="{{ __('The Setup step in Opening balances mode. The conversion date is your starting line; pre-conversion entries are locked once the run finishes.') }}"
        />

        <flux:text>
            {{ __('In Full transaction history mode the Setup step looks different: instead of a conversion date it offers a History start date (optional) and three checkboxes — Auto-create accounts found in the file but not in the chart, Link transaction names to customers/vendors (on by default), and Reconstruct documents (invoices, bills, cheques, deposits, receipts) where possible. These shape how the ledger replay later in the run handles unknown accounts, names, and document types; see Options worth knowing below. The history start date matters only if you choose to lock the imported history at the end.') }}
        </flux:text>

        {{-- ───────────── Working through ───────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Work through the remaining steps') }}</flux:heading>
        <flux:text>
            {{ __('After Setup, work down the step list. The list adapts to your mode — the Items, Inventory on hand, Fixed assets, and Trial balance steps appear only in Opening balances mode, and the Transaction history step appears only in Full transaction history mode, because the replayed ledger already carries that detail.') }}
        </flux:text>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Move down the step list — Chart of accounts, Control accounts, Customers, Vendors, and so on. Select any step in the list to jump to it; a green check marks the ones you have finished or skipped.') }}</li>
            <li>{{ __('At each upload step, select Download template, fill it from your QuickBooks export (or upload the QuickBooks list export as-is where the step accepts one — see Templates and previews), choose the file under Upload CSV, then Preview to see what will be created.') }}</li>
            <li>{{ __('Select Commit to write that step, or Skip this step if it does not apply.') }}</li>
            <li>{{ __('Finish on Review & finish, where you confirm the totals and finalize the run.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/migration/import.png') }}"
            alt="{{ __('The Import from QuickBooks wizard with the numbered step list on the left, finished steps marked with a green check, and the current upload step on the right with Download template, Upload CSV, Preview, Commit, and Skip this step') }}"
            caption="{{ __('The import wizard. The numbered list on the left tracks your progress — a green check marks finished steps; the panel on the right is the current step, with Download template above the upload box and Preview, Commit, and Skip this step below it.') }}"
        />

        {{-- ───────────── The steps ───────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('The steps') }}</flux:heading>
        <flux:text>
            {{ __('The wizard adapts the step list to the mode you chose. A typical run moves through:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Setup — conversion date and date strategy (Opening balances), or history start date and matching options (Full transaction history).') }}</li>
            <li>{{ __('Chart of accounts — optional; add your QuickBooks accounts on top of the seeded chart. Existing codes are skipped and new ones added. In Full transaction history mode, do not skip this if you plan to reconstruct documents.') }}</li>
            <li>{{ __('Control accounts — confirm which accounts act as Accounts Receivable, Accounts Payable, Undeposited Funds, Sales Tax Payable, Employee Reimbursements Payable, Inventory Asset, Cost of Goods Sold, Retained Earnings, and Opening Balance Equity.') }}</li>
            <li>{{ __('Customers and Vendors — import the contact lists.') }}</li>
            <li>{{ __('Items — Opening balances only, and optional: import products and services with their accounts.') }}</li>
            <li>{{ __('Transaction history — Full transaction history only: replay every transaction as a balanced journal entry, de-duplicated and reconciled for rounding.') }}</li>
            <li>{{ __('Open invoices and Open bills — load the unpaid documents outstanding at the conversion date so you have live AR and AP aging.') }}</li>
            <li>{{ __('Inventory on hand and Fixed assets — Opening balances only: seed stock quantities and costs, and the asset register with cost and accumulated depreciation.') }}</li>
            <li>{{ __('Trial balance — Opening balances only: post the remaining account balances so the books open in balance.') }}</li>
            <li>{{ __('Review & finish — confirm the totals and finalize the run.') }}</li>
        </ul>

        <x-docs.callout type="tip">
            {{ __('On the Control accounts step you can point a role at any imported account. Choosing an account whose type does not match the system role re-types it on the spot — pick an Income account to fill the Accounts Receivable role, for example, and the app converts that account to Accounts Receivable so it can act as the AR control. Useful when the QuickBooks chart had an off-type account you want to promote. Select Keep defaults to accept the seeded control accounts unchanged.') }}
        </x-docs.callout>

        {{-- ───────────── Full history ───────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Full transaction history: replaying your ledger') }}</flux:heading>
        <flux:text>
            {{ __('In Full transaction history mode the Transaction history step replaces the opening-balance steps. QuickBooks usually exports the ledger as one file per year (or per quarter for big companies), so the upload accepts several files at once and merges them into a single replay. The replay runs as a background job, so a queue worker must be running; the page shows Replaying transaction history… with a running count and updates automatically, and large files keep running even if you navigate away.') }}
        </flux:text>

        <x-docs.callout type="warning" heading="{{ __('Import your chart of accounts first') }}">
            {{ __('If Reconstruct documents is on but you skipped the Chart of accounts step, the Transaction history step shows this warning. Without a typed chart, accounts the replay creates are untyped (Other Asset) and every invoice, bill, receipt, cheque, and deposit falls back to a plain journal entry. Go back and import the QuickBooks Account Listing on the Chart of accounts step — it carries the account types — or turn off document reconstruction in Setup.') }}
        </x-docs.callout>

        <x-docs.callout type="note" heading="{{ __('Use the Journal report — not the General Ledger report') }}">
            {{ __('In QuickBooks Desktop choose Reports → Accountant & Taxes → Journal, set the date range to All, then Export → CSV. The Journal report lists each transaction with its split lines and separate Debit and Credit columns, which the importer needs; adding the “Trans #” column makes grouping exact but is optional. The General Ledger report is organised by account with a single signed Amount column and cannot be imported directly. A native IIF file also works.') }}
        </x-docs.callout>

        <p><strong>{{ __('To replay your transaction history:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('On the Transaction history step, choose the Source format — Journal CSV or IIF file.') }}</li>
            <li>{{ __('If you turned on Auto-create accounts and chose Journal CSV, an Account types (optional) box appears. Attach a QuickBooks Account Listing there (Reports → Lists → Account Listing, include inactive, export to CSV) so auto-created accounts are typed from it by number, then name, instead of defaulting to Other Asset. IIF files already carry account types.') }}</li>
            <li>{{ __('Drag every exported file into the drop zone, or click it to choose them — select them all at once and they import together, in date order. Up to 100 MB each.') }}</li>
            <li>{{ __('Select Preview to check the transaction counts, then Import history. Each historical journal entry posts on its original date. A transaction that is already in the books (from an earlier file or a re-run) is skipped, and a difference of a cent or two within a transaction is absorbed into a Conversion Rounding Variance account so every entry balances. If Reconstruct documents is on, recognised transaction types become real documents.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/migration/gl-bulk-upload.png') }}"
            alt="{{ __('The Transaction history step with the Journal-report note, the Source format choice, the Account types (optional) box, and a drag-and-drop zone accepting several Journal CSV or IIF files') }}"
            caption="{{ __('The Transaction history step accepts several files at once. Drop them all in together; with Journal CSV and Auto-create accounts selected, the Account types (optional) box above the drop zone takes your Account Listing.') }}"
        />

        {{-- ───────────── Trial balance ───────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('The trial balance: opening the books in balance') }}</flux:heading>
        <flux:text>
            {{ __('In Opening balances mode the final upload is the Trial balance. Its template has three columns — account_code, debit, credit. The importer matches each row to an account by code, then posts a single journal entry on the conversion date that brings those accounts to their QuickBooks balances. Any difference goes to Opening Balance Equity so the entry always balances.') }}
        </flux:text>

        <p><strong>{{ __('To import the trial balance:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Select Download template and fill it from your QuickBooks trial-balance export — one row per account, with the amount in either the debit or the credit column, never both.') }}</li>
            <li>{{ __('Upload the file and select Preview. The preview lists each matched account with its debit or credit, and flags any code it cannot find in your chart.') }}</li>
            <li>{{ __('Select Commit. The importer posts the opening entry; zero-balance rows are skipped, and any leftover difference lands in Opening Balance Equity.') }}</li>
        </ol>

        <x-docs.callout type="note" heading="{{ __('GL impact') }}">
            {{ __('One journal entry dated the conversion date: every accepted row is posted exactly as your file states it — a debit to the account for a debit balance, a credit for a credit balance — and the residual is debited or credited to Opening Balance Equity so the entry balances. Nothing dated before that day exists in the ledger.') }}
        </x-docs.callout>

        <x-docs.figure
            src="{{ asset('docs/screenshots/migration/tb-mapping.png') }}"
            alt="{{ __('The Trial balance step after Preview, listing each matched account code with its debit or credit amount and the issues callout for any rejected row') }}"
            caption="{{ __('The trial-balance preview. Rows are matched to your accounts by code; the difference is plugged to Opening Balance Equity so the books open in balance.') }}"
        />

        <x-docs.callout type="note">
            {{ __('Accounts Receivable, Accounts Payable, and Inventory rows are rejected on the trial balance because of their account type, and any account whose name contains “accumulated depreciation” is rejected too — the preview explains why for each one. Their detail has to come through the dedicated importers (open invoices, open bills, inventory on hand, fixed assets) so each customer, vendor, item, and asset stays tied to its balance. The depreciation check is by name only, so leave a contra-asset with a different name (say “Accum. Dep. — Vehicles”) off the trial-balance file yourself, or it would be counted twice once the Fixed assets step posts it.') }}
        </x-docs.callout>

        {{-- ───────────── Options ───────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Options worth knowing') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li><strong>{{ __('Use original dates') }}</strong> — {{ __('preserve each open invoice or bill’s real date instead of dating it to the conversion day, so aging stays accurate. Set per document type on the Setup step; both are on by default.') }}</li>
            <li><strong>{{ __('Auto-create accounts found in the file but not in the chart') }}</strong> — {{ __('let the replay add an account on the fly when a file references one that does not exist yet. For Journal CSVs, attach an Account Listing on the Transaction history step so those accounts are typed correctly; otherwise they default to Other Asset and should be reviewed. Leave it off to require a matching chart first.') }}</li>
            <li><strong>{{ __('Link transaction names to customers/vendors') }}</strong> — {{ __('link each replayed transaction to the customer or vendor whose name matches the transaction’s Name, ignoring upper and lower case. The name is the only thing compared — there is no reference-number match — so a name spelled differently from your contact list stays unlinked.') }}</li>
            <li><strong>{{ __('Reconstruct documents') }}</strong> — {{ __('turn recognised transaction types into real documents during the replay — invoices, credit memos, customer receipts, bills, bill payments, deposits, and cheques — with account-level lines; everything else stays a plain journal entry. Receipts and payments are auto-applied oldest-first. Each rebuilt invoice, credit memo, receipt, deposit line, bill, and bill payment is tied to the customer or vendor named on the transaction — an existing contact with that name, or a new one the replay creates. Cheques only match a contact that already exists and never create one, so importing your customer and vendor lists first still gives the cleanest result. A rebuilt cheque leaves off any line coded to Accounts Receivable or Accounts Payable (its journal entry still holds that line), so a cheque that only settles a receivable or payable — a customer refund, say — is not rebuilt and stays a plain journal entry.') }}</li>
        </ul>

        {{-- ───────────── Templates ───────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Templates and previews') }}</flux:heading>
        <flux:text>
            {{ __('Each upload step offers a downloadable CSV template so your file lines up with what the importer expects. Preview never writes anything — it shows row counts and any warnings so you can fix the file and re-upload. The preview table shows up to 200 rows and 100 issues, with a Showing first … of … rows note when there are more.') }}
        </flux:text>
        <flux:text>
            {{ __('Commit is what actually writes that step to your books, and what happens when the file has errors depends on the step. Trial balance, Inventory on hand, and Fixed assets are all-or-nothing: if any row has an error, nothing is written. Chart of accounts, Customers, Vendors, Items, Open invoices, and Open bills skip only the rows with errors and save every valid row. On every step the toast then reads Import has errors — nothing was saved and the step stays open — but on those six steps the valid rows are already in your books despite what the toast says, and the summary line under the table (created: …) shows how many went in. The exception is an issue that begins Row 0: Import aborted. That commit hit an error it could not skip — an invoice or bill number already in your books, or a date it cannot read, such as 31/12/2025 — and was rolled back whole, so nothing from the file was saved, whatever the created count says.') }}
        </flux:text>

        <x-docs.callout type="warning" heading="{{ __('After a commit with errors, re-commit only the rows that failed') }}">
            {{ __('Commit once Preview reports no issues and you avoid this entirely. If a commit on Chart of accounts, Customers, Vendors, Items, Open invoices, or Open bills does report errors, fix the rejected rows and commit a file that holds only those rows — not the whole file again. Rows that were already saved are harmless to repeat for accounts (existing codes are skipped), customers and vendors (matched by name and merged), and items with a SKU (skipped), but an item without a SKU is created a second time, and an invoice or bill number that is already in your books makes the whole commit fail with an error.') }}
        </x-docs.callout>

        <flux:heading size="md" class="mt-6">{{ __('Upload the QuickBooks export as-is') }}</flux:heading>
        <flux:text>
            {{ __('Three steps read QuickBooks’ own list exports directly, so you do not have to retype them into the template. Chart of accounts accepts an Account Listing export (Account, Type, Accnt. #, Description); Customers and Vendors accept a Customer List or Vendor List export. These readers skip the report preamble and padding columns QuickBooks adds, strip a byte-order mark, and convert Windows-1252 text to UTF-8 — as does the Transaction history step.') }}
        </flux:text>
        <flux:text>
            {{ __('Open invoices also accepts QuickBooks’ Open Invoices report as-is, recognised by its Type and Open Balance columns. The app nets each customer’s credits (rows with a negative open balance, such as unapplied payments) against that customer’s invoices, oldest first, so a customer whose invoices and payments cancel out imports nothing; and it creates any customer that is not in your list yet. The template layout, by contrast, needs every customer to exist already — import Customers first.') }}
        </flux:text>
        <flux:text>
            {{ __('No other upload gets that cleanup. The Items, Open invoices (in the template layout), Open bills, Inventory on hand, Fixed assets, and Trial balance steps read the file exactly as it is, so it has to be UTF-8 without a byte-order mark and laid out exactly like its template. Excel makes that awkward: its “CSV UTF-8 (Comma delimited)” format starts the file with a byte-order mark that hides the first column, and its plain “CSV” on Windows is not UTF-8, so names with accents (Café, Montréal) are not read correctly. Export from Google Sheets (File → Download → Comma-separated values) or from LibreOffice with the Unicode (UTF-8) character set instead. If a byte-order mark does slip in, on most of these steps Preview reports a missing required column, but the first column on Items is the optional SKU, so Preview shows no error — the SKU column is just blank — and every item is imported without its SKU.') }}
        </flux:text>

        {{-- ───────────── Finishing ───────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Finishing, locking, and abandoning') }}</flux:heading>
        <flux:text>
            {{ __('The Review & finish step links you to the Trial Balance, AR Aging, and AP Aging reports, each opened as of the conversion date, so you can confirm the totals match QuickBooks before you commit to the conversion. If anything is off, select the relevant step in the list and redo it.') }}
        </flux:text>

        <flux:heading size="md" class="mt-6">{{ __('Check AR and AP before you finish') }}</flux:heading>
        <flux:text>
            {{ __('In Full transaction history mode the Review & finish step also shows an AR / AP reconciliation panel: the Replayed AR balance and Replayed AP balance from the general ledger alongside the Open invoices total and Open bills total from the open-document steps. If you loaded open invoices or bills as documents, exclude those same transactions from the journal file — otherwise the replay posts them a second time and AR or AP is double-counted. The two figures on each line should agree before you finish.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/migration/review-finish.png') }}"
            alt="{{ __('The Review & finish step in Full transaction history mode with the All import steps complete callout, the AR / AP reconciliation panel, the three report links, the Lock everything on or before the history start date checkbox, and the Finish import button') }}"
            caption="{{ __('Review & finish in Full transaction history mode. Compare the replayed AR and AP balances with the open-document totals, open the three reports, then decide whether to lock the imported history.') }}"
        />

        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('In Opening balances mode, select Finalize & lock. The step warns you first — Finalize will lock the books — because this sets the organization’s lock date to the conversion date, so no new postings dated on or before it will be accepted.') }}</li>
            <li>{{ __('In Full transaction history mode, select Finish import. Locking is optional — tick “Lock everything on or before the history start date” only if you want to freeze the imported history; leave it off to keep the books fully open. The lock uses the History start date you entered in Setup, so if you left that blank, nothing is locked.') }}</li>
        </ul>
        <flux:text>
            {{ __('Either way the app confirms Conversion complete and returns you to the dashboard. Owners and Admins can move the lock date later from the Close the books card under Settings → Organizations → Edit organization — see') }}
            <a class="underline" href="{{ route('docs.accounting') }}" wire:navigate>{{ __('Accounting') }}</a>{{ __('.') }}
        </flux:text>

        <x-docs.callout type="warning">
            {{ __('Each step commits as you go, so the wizard is not all-or-nothing. If you select Abandon migration in an Opening balances run, the data you already imported stays in the organization; in a Full transaction history run, the replayed transactions and any reconstructed documents are removed. Either way the run is over: the app returns you to the dashboard, the Continue import banner and the Import from QuickBooks item under Settings disappear, and no button or menu starts a new run. To begin again, type the import page’s address into your browser yourself — your organization’s address followed by /import-from-quickbooks, such as …/demo/import-from-quickbooks for Demo Company Inc. — which opens a fresh run; or carry the balances over in the Opening balances workspace instead. Abandon migration is only on the standalone page — inside the setup wizard the button is Finish later, which keeps the run.') }}
        </x-docs.callout>

        {{-- ───────────── After ───────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('After the import') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Run each bank account’s first reconciliation using the QuickBooks statement-side balance as the beginning balance — see') }}
                <a class="underline" href="{{ route('docs.banking') }}" wire:navigate>{{ __('Banking') }}</a>{{ __('.') }}</li>
            <li>{{ __('Review any auto-created accounts typed Other Asset on the chart of accounts — see') }}
                <a class="underline" href="{{ route('docs.accounting') }}" wire:navigate>{{ __('Accounting') }}</a>{{ __('.') }}</li>
            <li>{{ __('If you skipped the import, or need to adjust a balance after finishing, Owners can carry balances over or correct them in the Opening balances workspace — see') }}
                <a class="underline" href="{{ route('docs.opening-balances') }}" wire:navigate>{{ __('Opening balances') }}</a>{{ __('.') }}</li>
        </ul>

        <flux:heading size="lg" class="mt-8">{{ __('Reports tied to this area') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Trial Balance — as of the conversion date, to prove the books open in balance against your QuickBooks trial balance.') }}</li>
            <li>{{ __('AR Aging and AP Aging — as of the conversion date, to confirm the open invoices and bills you loaded match QuickBooks.') }}</li>
            <li>{{ __('Reports are described in') }} <a class="underline" href="{{ route('docs.reports') }}" wire:navigate>{{ __('Reports') }}</a>{{ __('.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
