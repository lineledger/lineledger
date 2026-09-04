<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Accounting')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Accounting')"
        :subheading="__('The chart of accounts, manual journal entries, and the controls that keep your closed books closed.')"
    >
        <flux:text>
            {{ __('Everything you record elsewhere — invoices, bills, payments, deposits — eventually lands here as balanced entries against your accounts. The Accounting area lets you shape that ledger directly: organize your chart of accounts, post manual journal entries for things that do not fit the everyday forms, and lock finished periods so the numbers cannot move. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        {{-- ───────────────────────── Chart of accounts ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('The chart of accounts') }}</flux:heading>
        <flux:text>
            {{ __('The chart of accounts is the master list of categories your money flows through — the buckets behind every report. Each account has a code (a number you choose for sorting), a name, a type that decides where it appears on your financial statements, and a balance. Open Accounting → Chart of Accounts from the sidebar to see them grouped by type.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/accounting/accounts.png') }}"
            alt="{{ __('The Chart of Accounts list grouped into Asset, Liability, Equity, Income, and Expense sections') }}"
            caption="{{ __('The Chart of Accounts, grouped by type. Toggle “Show inactive” to include accounts you no longer use.') }}"
        />

        <flux:text>
            {{ __('Accounts are organized into five top-level types. Within each type, a subtype tells the app exactly how the account behaves and where it belongs on the balance sheet or income statement.') }}
        </flux:text>

        <flux:table class="not-prose my-6">
            <flux:table.columns>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Common subtypes') }}</flux:table.column>
                <flux:table.column>{{ __('What it tracks') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                <flux:table.row>
                    <flux:table.cell>{{ __('Asset') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Bank, Accounts Receivable, Undeposited Funds, Inventory, Fixed Asset') }}</flux:table.cell>
                    <flux:table.cell>{{ __('What you own or are owed — cash, money customers owe you, stock, and equipment.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Liability') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Accounts Payable, Credit Card, Tax Payable') }}</flux:table.cell>
                    <flux:table.cell>{{ __('What you owe — money to suppliers, card balances, and sales tax you have collected.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Equity') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Equity, Retained Earnings') }}</flux:table.cell>
                    <flux:table.cell>{{ __('The owner’s stake — contributions, draws, and accumulated profit.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Income') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Income, Other Income') }}</flux:table.cell>
                    <flux:table.cell>{{ __('What you earn — sales and any income outside your normal trade.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Expense') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Cost of Goods Sold, Expense') }}</flux:table.cell>
                    <flux:table.cell>{{ __('What you spend — the direct cost of what you sell and your running operating costs.') }}</flux:table.cell>
                </flux:table.row>
            </flux:table.rows>
        </flux:table>

        <p><strong>{{ __('To add an account:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Accounting → Chart of Accounts from the sidebar.') }}</li>
            <li>{{ __('Select New account in the top-right corner.') }}</li>
            <li>{{ __('From the Type dropdown, pick the subtype that best describes the account — the options are grouped under the five top-level types, so you will see entries like “Asset: Bank” or “Expense: Cost of Goods Sold.”') }}</li>
            <li>{{ __('Enter a Code (the number it sorts by) and a Name.') }}</li>
            <li>{{ __('Optionally pick a Parent account to nest it underneath another for grouped reporting, and a Default tax code to pre-select whenever a transaction line posts here.') }}</li>
            <li>{{ __('For a Canadian company, you can also map the account to a GIFI line — the CRA code it rolls up to on the GIFI statement when you file a T2, T5013, or T2125.') }}</li>
            <li>{{ __('For a balance-sheet account, you can also enter an Opening balance and the As-of date it starts from — the app posts the starting balance for you.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.callout type="note" heading="{{ __('Opening balances') }}">
            {{ __('When you create a balance-sheet account — a bank, receivable, payable, or the like — you can give it a starting balance as of a date you choose. The app records it as a journal entry against Opening Balance Equity, so your books stay in balance from the first day. Opening balances entered here can only be zero or positive; for a contra balance, post a journal entry instead. Foreign-currency Bank and Credit Card accounts skip this field — record their opening balance with a journal entry at the correct exchange rate.') }}
        </x-docs.callout>

        <x-docs.callout type="tip">
            {{ __('Group similar accounts with shared number ranges — assets in the 1000s, liabilities in the 2000s, equity in the 3000s, income in the 4000s, expenses in the 5000s and up. It keeps the chart easy to scan as it grows.') }}
        </x-docs.callout>

        <x-docs.callout type="warning">
            {{ __('Avoid changing the type of an account that already has activity — it changes how every past entry rolls up on the financial statements. When you stop using an account, mark it inactive instead of deleting it so its history stays intact.') }}
        </x-docs.callout>

        <x-docs.callout type="note" heading="{{ __('System accounts can be renamed and recoded') }}">
            {{ __('Built-in system accounts — Accounts Receivable, Accounts Payable, Undeposited Funds, Retained Earnings, and the rest — let you edit the code and the name so you can match Demo Company Inc.’s numbering scheme. The type, subtype, and normal balance stay frozen, because those drive every report and every posting rule the app relies on.') }}
        </x-docs.callout>

        <x-docs.figure
            src="{{ asset('docs/screenshots/accounting/account-edit.png') }}"
            alt="{{ __('The Edit account form for a system account with the code and name fields editable and the type fields disabled') }}"
            caption="{{ __('The Edit account form. Code and name are editable on every account, including system accounts; type, subtype, and normal balance are locked once an account exists.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('Inactive accounts are hidden from the account selectors on invoices, bills, journal entries, and every other transaction form, so day-to-day entry stays uncluttered. If a record was already saved with an inactive account, that account still appears in its selector when you edit the record — it will never silently disappear from existing data.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('Account names on the Balance Sheet and Cash Flow Statement reports are clickable: select one to drill through to a Transactions report scoped to that account for the same date range. It is the quickest way to answer "what made up this number?" without leaving the report.') }}
        </flux:text>

        {{-- ───────────────────────── Import accounts ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Import a chart of accounts') }}</flux:heading>
        <flux:text>
            {{ __('Starting from an existing set of accounts — a list exported from another program, or one handed over by your accountant? Import them in bulk from a CSV instead of adding each one by hand.') }}
        </flux:text>

        <p><strong>{{ __('To import accounts:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Accounting → Chart of Accounts and select Import in the top-right corner.') }}</li>
            <li>{{ __('Select Download template to get a CSV with the right columns, fill in one account per row, and save it.') }}</li>
            <li>{{ __('Choose your CSV file, then select Preview. The app checks every row and reports how many accounts it will create and how many it will skip.') }}</li>
            <li>{{ __('Review the preview, then select Import to create them.') }}</li>
        </ol>

        <x-docs.callout type="note">
            {{ __('The subtype column takes the same subtypes as the New account form. To nest sub-accounts, put the parent’s code in the parent_code column and list the parent before its children. Codes that already exist are left untouched, so a re-run adds only what is new.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Merge accounts ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Merge two accounts into one') }}</flux:heading>
        <flux:text>
            {{ __('Ended up with duplicates — say two "Office Supplies" expense accounts? Merge one into the other so all of its history rolls into a single account instead of leaving the chart cluttered.') }}
        </flux:text>

        <p><strong>{{ __('To merge an account:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Accounting → Chart of Accounts and find the account you want to retire.') }}</li>
            <li>{{ __('Choose Merge from its row actions.') }}</li>
            <li>{{ __('In Merge into, pick the surviving account — only accounts of the same subtype and currency are offered.') }}</li>
            <li>{{ __('Read the summary of what will move, tick "I understand this cannot be undone," and select Merge.') }}</li>
        </ol>

        <x-docs.callout type="warning">
            {{ __('Merging is permanent. Every transaction, item default, recurring line, and sub-account on the account you retire moves to the one you keep, and the retired account is then deactivated and dropped from the list. Built-in system accounts cannot be merged away.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Journal entries ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Journal entries') }}</flux:heading>
        <flux:text>
            {{ __('A journal entry is a raw double-entry transaction — debits on one side, credits on the other. Use one for adjustments that do not fit the invoice, bill, or payment forms: depreciation, accruals, reclassifying an amount between accounts, opening balances, and year-end closing entries. Open Accounting → Journal to see every posting to your general ledger.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/accounting/journal-list.png') }}"
            alt="{{ __('The Journal Entries list showing dated, numbered postings with their memos and amounts') }}"
            caption="{{ __('The Journal Entries list. Every transaction in the app — invoices, bills, payments, transfers — appears here as a numbered entry.') }}"
        />

        <p><strong>{{ __('To create a journal entry:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Accounting → Journal, then select New entry.') }}</li>
            <li>{{ __('The Entry # and Date fill in automatically — adjust the date if the entry belongs to another day.') }}</li>
            <li>{{ __('Type a Memo describing why you are making the entry.') }}</li>
            <li>{{ __('On the first line, choose an Account and enter either a Debit or a Credit amount, plus an optional line memo.') }}</li>
            <li>{{ __('Select Add line and enter the offsetting amount on another account, so the two sides match.') }}</li>
            <li>{{ __('Watch the Totals row — Debit and Credit must be equal before you can post.') }}</li>
            <li>{{ __('Select Post entry to finalize it, or Save draft to keep working on it later.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/accounting/journal-create.png') }}"
            alt="{{ __('The New journal entry form with account lines, debit and credit columns, and a balancing Totals row') }}"
            caption="{{ __('The New journal entry form. The Totals row tallies debits against credits as you type.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('Debits must equal credits') }}">
            {{ __('Every journal entry has to balance: total debits must equal total credits in your home currency. The app refuses to post an entry that does not balance. Posting writes the lines to the general ledger, where they flow into every report that touches the affected accounts; a draft, by contrast, changes nothing in your books until you post it.') }}
        </x-docs.callout>

        <x-docs.callout type="note" heading="{{ __('Source-linked entries are read-only') }}">
            {{ __('Most journal entries in the list were created automatically by another document — a posted invoice, bill, receipt, cheque, deposit, or transfer. Those entries show a blue Source badge on the journal show page that links straight to the document that produced them, and the usual Edit, Void, and Reverse actions are replaced with a single "View source" action. This is a deliberate audit guard: the only correct way to back out a posted bill or invoice is to void or edit that bill or invoice, so the source document and its ledger entry never disagree. Direct edits to a source-linked entry are refused.') }}
        </x-docs.callout>

        <x-docs.figure
            src="{{ asset('docs/screenshots/accounting/journal-source-link.png') }}"
            alt="{{ __('A journal entry show page with a blue Source badge linking back to a posted bill and a View source action in the toolbar') }}"
            caption="{{ __('A source-linked journal entry. The Source badge links to the document that posted it; "View source" takes you to the only screen that can change it.') }}"
        />

        <x-docs.callout type="tip" heading="{{ __('Duplicate to reuse the same entry') }}">
            {{ __('Open any posted entry and choose Duplicate from the Actions menu to start a new draft pre-filled with the same lines, accounts, and amounts — handy for one-off adjustments you make every month but did not turn into a recurring template. For source-linked entries, Duplicate routes to the source document’s own duplicate flow when it has one (for example, a posted bill or deposit), so you keep the original document type instead of creating a bare journal entry.') }}
        </x-docs.callout>

        <x-docs.figure
            src="{{ asset('docs/screenshots/accounting/journal-duplicate.png') }}"
            alt="{{ __('The Actions menu on a journal entry show page with a Duplicate item highlighted') }}"
            caption="{{ __('Duplicate on the journal show page. For source-linked entries it opens the source document’s duplicate flow instead of a bare journal entry.') }}"
        />

        {{-- ───────────────────────── Reversing an entry ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reversing an entry') }}</flux:heading>
        <flux:text>
            {{ __('Reversing a posted entry is the accrual-accounting way to back something out without erasing it. It creates a mirror of the original with the debits and credits swapped, dated whenever you choose — typically the first day of the next period. The original stays in place for your audit trail, and the reversal cancels it on the later date.') }}
        </flux:text>

        <p><strong>{{ __('To reverse a journal entry:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the posted entry you want to reverse.') }}</li>
            <li>{{ __('Select Reverse.') }}</li>
            <li>{{ __('Choose the date for the reversal.') }}</li>
            <li>{{ __('Review the generated Draft — the amounts are the same but the debit and credit sides are flipped.') }}</li>
            <li>{{ __('Select Post entry when you are ready.') }}</li>
        </ol>

        <x-docs.callout type="note">
            {{ __('The reversal is created as a Draft so you can review it before it hits your books, and the two entries stay linked so you can always trace one to the other.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Journal entry templates ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Journal entry templates') }}</flux:heading>
        <flux:text>
            {{ __('A template is a saved set of journal lines you reuse to start new entries quickly — the same accounts, line memos, and even default debit and credit amounts, ready to drop onto a fresh entry. Reach for one when you post the same shape of entry often but on no fixed schedule: a payroll journal, a standard accrual, a reclass you book by hand. Open Accounting → Journal templates from the sidebar to manage them.') }}
        </flux:text>

        <p><strong>{{ __('To create a template:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Accounting → Journal templates, then select New template.') }}</li>
            <li>{{ __('Give the template a name — for example, Monthly depreciation — and leave Active on so it shows up in the picker.') }}</li>
            <li>{{ __('Add the lines: pick an Account, a Tax code if it applies, and any Debit, Credit, or line memo you want pre-filled.') }}</li>
            <li>{{ __('Select Add line for more rows, then Save template.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/accounting/journal-template.png') }}"
            alt="{{ __('The New journal entry template form with a name field, an Active toggle, and a grid of account lines with debit and credit columns') }}"
            caption="{{ __('The New journal entry template form. The Totals row shows whether the saved amounts balance, but a template does not have to balance — you finish and balance the real entry when you apply it.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('Templates are scaffolding, not posted entries') }}">
            {{ __('A template never touches your books on its own — it only stores a line layout. It does not need to balance, the amounts can be zero, and nothing posts until you apply it to a real journal entry and post that. Deleting a template leaves every entry you already created from it untouched.') }}
        </x-docs.callout>

        <p><strong>{{ __('To start an entry from a template:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Accounting → Journal, then select New entry.') }}</li>
            <li>{{ __('At the top of the form, choose your template from the Template selector. Its lines fill in below, ready for you to adjust the amounts.') }}</li>
            <li>{{ __('Set the Date and Memo, make sure debits equal credits, and post as usual.') }}</li>
        </ol>
        <flux:text>
            {{ __('The Template selector only appears while you are creating a new entry, and only when you have at least one active template. Editing an existing entry hides it.') }}
        </flux:text>

        <x-docs.callout type="tip" heading="{{ __('Save any entry as a template') }}">
            {{ __('Building an entry you will want again? On the journal entry form, select Save as template and give it a name — the current lines are stored as a reusable template, so you never have to retype them on the dedicated templates page.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Recurring journal entries ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Recurring (memorized) journal entries') }}</flux:heading>
        <flux:text>
            {{ __('Where a template helps you re-key an entry on demand, a recurring schedule puts it on a calendar: the app generates the entry for you on each due date, so a standing accrual or an amortization schedule shows up without you having to remember it. Open Accounting → Recurring entries from the sidebar to manage your schedules.') }}
        </flux:text>

        <p><strong>{{ __('To set up a recurring schedule:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Accounting → Recurring entries, then select New memorized entry.') }}</li>
            <li>{{ __('Name the schedule and enter the Memo applied to each generated entry.') }}</li>
            <li>{{ __('Choose a Frequency — weekly, monthly, quarterly, semi-annual, or annual — and a Start date. For monthly and longer cadences you can pin a Day of month, or run on the last day or last business day of the month (quarter-end for quarterly schedules).') }}</li>
            <li>{{ __('Under Ends, leave it on Never, or stop it on a date or after a set number of occurrences.') }}</li>
            <li>{{ __('Add the journal lines exactly as you would on a one-off entry — the schedule must balance before you can save it — then Save schedule.') }}</li>
        </ol>

        <x-docs.callout type="note" heading="{{ __('Generated entries are always drafts') }}">
            {{ __('A schedule never posts on its own — each due date produces a Draft journal entry for you to review and post, so nothing hits your books unseen. Open a schedule and select Generate now to create the next one early, or Pause to stop it. If an account the schedule depends on is deleted, the schedule pauses itself and records the reason on its page rather than failing quietly.') }}
        </x-docs.callout>

        <x-docs.callout type="tip" heading="{{ __('Memorize an entry you already have') }}">
            {{ __('Open a journal entry you post regularly, choose Memorize from the Actions menu, and the schedule form opens pre-filled with that entry’s lines — the quickest way to put a repeating one-off onto a calendar without retyping it.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Period locking ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Period locking') }}</flux:heading>
        <flux:text>
            {{ __('Once a period is finished — books reviewed, taxes filed — lock it so the numbers cannot shift underneath you. A lock date tells the app to refuse any transaction dated on or before it, across every workflow: invoices, bills, payments, cheques, transfers, and journal entries.') }}
        </flux:text>

        <p><strong>{{ __('To lock a period:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the company settings (only an owner or admin can do this).') }}</li>
            <li>{{ __('Enter the lock date — the last day of the period you are closing.') }}</li>
            <li>{{ __('Confirm with your password.') }}</li>
            <li>{{ __('Save.') }}</li>
        </ol>

        <x-docs.callout type="warning">
            {{ __('After the lock date is set, the app will not post, edit, or void anything dated on or before it. Only an owner or admin can move or clear the lock, and every change to the lock date is recorded in the audit log.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Related reports ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reports tied to this area') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('General Ledger — every posting to every account over a date range.') }}</li>
            <li>{{ __('Trial Balance — each account’s ending debit or credit balance, proving the books balance.') }}</li>
            <li>{{ __('Balance Sheet and Income Statement — the financial statements your account types roll up into.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
