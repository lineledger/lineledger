<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Banking')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Banking')"
        :subheading="__('Watch your bank register, import and reconcile statements, transfer money, write cheques, and group deposits.')"
    >
        <flux:text>
            {{ __('Banking is where your cash transactions live. Use it to review a running register of any bank account, import the statement your bank gives you and match it against your books, work a feed of imported transactions waiting to be categorized, set rules that categorize them for you, reconcile at month end, move money between your own accounts, write cheques outside the bill workflow, and bundle customer receipts into the single deposit that lands at the bank. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        {{-- ───────────────────────── Bank register ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('The bank register') }}</flux:heading>
        <flux:text>
            {{ __('The register is a chequebook-style view of one bank account: every transaction that hit it, in date order, with payments and deposits in separate columns. Use it to confirm a payment cleared, drill into an entry, or spot a duplicate before you reconcile. Open Banking → Bank register from the sidebar and pick an account from the Account selector at the top. The Actions menu in the top-right is your gateway to the rest of Banking — Reconcile, Import statement, and Bank rules all open from there.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/banking/register.png') }}"
            alt="{{ __('The bank register for the Chequing account showing payments, deposits, and a ledger balance') }}"
            caption="{{ __('The bank register. Each row links to the journal entry behind it, and the tiles up top show your ledger, cleared, and statement balances.') }}"
        />

        <flux:text>
            {{ __('Tick the checkbox on a row to mark it cleared against your statement — the Cleared balance tile updates as you go. Use Clear all or Unclear all to mark the whole list at once, and toggle Show cleared to hide rows you have already accounted for. When you are ready to formally close the period, choose Reconcile from the Actions menu.') }}
        </flux:text>

        {{-- ───────────────────────── Import a statement ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Import a bank statement') }}</flux:heading>
        <flux:text>
            {{ __('Instead of ticking off every row by hand, upload the statement file your bank gives you and let the app match it against your books. Matched transactions are cleared for you, brand-new ones can be added with a single category pick, and duplicates are skipped — so by the time you reach the reconciliation screen most of the work is already done. Open the Bank register, choose Import statement from the Actions menu, and pick the account at the top.') }}
        </flux:text>

        <p><strong>{{ __('To import a statement:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('From the Bank register, choose Actions → Import statement, then pick the bank or credit-card account from the Account selector.') }}</li>
            <li>{{ __('Select the statement file and choose Upload & analyze. CSV, Excel, OFX/QFX/QBO, and PDF are all accepted.') }}</li>
            <li>{{ __('If the app needs help reading a CSV or Excel file, map the columns (see below) and select Apply mapping. Structured OFX/QFX/QBO files skip this step.') }}</li>
            <li>{{ __('Review the matched, to-add, suggested, and duplicate lines. For anything new, pick a category from the Add to… selector, optionally the vendor it was paid to, and the sales tax included in the amount — the app splits the tax out of the statement total for your return. Lines the app has seen before — a payee you categorized last month, a bank rule, or an AI guess — arrive pre-filled and marked Suggested; select Confirm to accept each one (or Confirm all suggestions in the summary bar), Change to adjust it, or Skip.') }}</li>
            <li>{{ __('Select Import & reconcile. The app pre-ticks every matched and added line and drops you on the reconciliation screen to finish.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/banking/import-upload.png') }}"
            alt="{{ __('The Import statement page with the account selector and a file picker for the statement') }}"
            caption="{{ __('Step 1 — upload. Pick the account, choose the file your bank gave you, and select Upload & analyze.') }}"
        />

        <x-docs.callout type="tip" heading="{{ __('OFX / QFX / QBO is the most reliable') }}">
            {{ __('Most banks offer a “Download to Quicken” or “Download to QuickBooks” option. That file is already structured, so it needs no column mapping and imports cleanly. A CSV or Excel export works too — the app detects the columns where it can and lets you map the rest.') }}
        </x-docs.callout>

        <flux:heading size="md" class="mt-6">{{ __('Mapping columns (CSV and Excel)') }}</flux:heading>
        <flux:text>
            {{ __('A spreadsheet export does not label its columns the way the app expects, so it asks you which is which. You only do this once per bank format — tick Remember this mapping and give it a name, and the next file from the same bank maps itself.') }}
        </flux:text>

        <p><strong>{{ __('To map a statement’s columns:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Choose whether amounts are in One signed amount column or Separate money in / out columns.') }}</li>
            <li>{{ __('Pick the Date column and the Description column.') }}</li>
            <li>{{ __('Pick the amount column (or the money-out and money-in columns), and the optional running-balance column.') }}</li>
            <li>{{ __('Set the Date format to match the file, and tick Flip the sign if withdrawals are written as positive numbers.') }}</li>
            <li>{{ __('Optionally tick Remember this mapping, name it (for example “BMO Chequing CSV”), and select Apply mapping.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/banking/import-mapping.png') }}"
            alt="{{ __('The column-mapping wizard with selectors for the date, description, and amount columns plus a date-format picker') }}"
            caption="{{ __('Step 2 — map your columns (CSV and Excel only). Save it as a profile and the next file from the same bank skips this step.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('Your statement stays on your server') }}">
            {{ __('By default the importer is fully deterministic — it parses and matches your statement on your own server and nothing ever leaves it. An optional AI assist (off unless an operator turns it on) can infer a CSV’s columns and tidy descriptions for you; even then only a small sample of rows is ever sent. If the AI service is briefly unreachable you simply map the columns by hand instead.') }}
        </x-docs.callout>

        <flux:heading size="md" class="mt-6">{{ __('Reviewing matches') }}</flux:heading>
        <flux:text>
            {{ __('The review screen lists every transaction the file contained with a status badge, so you can see at a glance what the app did with each one before you commit anything:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li><strong>{{ __('Matched') }}</strong> — {{ __('the line already exists in your books; importing will clear it on the reconciliation. Choose Don’t clear to leave it untouched.') }}</li>
            <li><strong>{{ __('To add') }}</strong> — {{ __('a transaction the file has but your books do not. Pick a category in the Add to… selector and the app posts it for you, or Skip to ignore it. Choosing a vendor records the line as an Expense to that vendor; if the vendor has an open bill for the same amount you can choose Pay bill instead, which records a bill payment — or select Pay bills… to settle several open bills (or a reimbursement owed to an employee) with the one payment; the amounts you apply must add up to the transaction, and a bill may be paid in part. Picking a vendor with a default expense account or tax code fills them in for you; picking one without remembers what you choose as that vendor’s defaults.') }}</li>
            <li><strong>{{ __('Suggested') }}</strong> — {{ __('the app has filled in the category (and vendor, when it knows one) from how you categorized the same payee before, from a bank rule, or from AI, and tells you which. Nothing posts until you Confirm the line. If you try to import with unconfirmed suggestions, the app asks whether to confirm them all or leave them waiting in For Review.') }}</li>
            <li><strong>{{ __('Possible match') }}</strong> — {{ __('a likely match to a transaction already in your books that the app is not certain about. Confirm it to treat it as matched, or Skip.') }}</li>
            <li><strong>{{ __('Duplicate') }}</strong> — {{ __('a line that is already accounted for; it is skipped automatically so you never import it twice.') }}</li>
        </ul>

        <x-docs.figure
            src="{{ asset('docs/screenshots/banking/import-review.png') }}"
            alt="{{ __('The import review table showing statement lines with Matched, To add, and Duplicate status badges and a category selector') }}"
            caption="{{ __('Step 3 — review. The summary bar counts matched, to-add, and duplicate lines; Import & reconcile carries them all to the reconciliation screen pre-ticked.') }}"
        />

        <x-docs.callout type="tip" heading="{{ __('Always do this') }}">
            {{ __('Once a line has a category (and vendor), select the lightning-bolt Always do this button beside it. The app writes a bank rule for that payee — matching on the payee part of the description, so next month’s reference number or date does not matter — and pre-fills it the same way on every future import. The rule appears under Bank rules, where you can rename it, tune the pattern, or turn it off.') }}
        </x-docs.callout>

        <x-docs.callout type="note" heading="{{ __('Reading a PDF statement') }}">
            {{ __('PDF statements are supported, but they need a little more from the server. When the poppler toolkit is installed the app uses it to read the PDF’s layout accurately; otherwise it falls back to a pure-PHP reader. A secured or scanned (image-only) PDF cannot be read as text — the import surfaces a clear “We could not read this statement” message rather than hanging, and you can fall back to the CSV or OFX export instead. Large files and PDFs are parsed in the background, so a queue worker should be running.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Bank rules ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Bank rules') }}</flux:heading>
        <flux:text>
            {{ __('A bank rule categorizes an imported transaction automatically when its description matches a pattern you set — so the coffee-shop charge that lands every week is filed to the same expense account without you choosing it each time. A rule can also name the vendor, so the line is recorded as an expense to that vendor rather than a bare journal entry. Rules only suggest; they never post anything on their own, so you stay in control. Open the Bank register and choose Bank rules from the Actions menu.') }}
        </flux:text>

        <p><strong>{{ __('To create a bank rule:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('From the Bank register, choose Actions → Bank rules, then select New rule.') }}</li>
            <li>{{ __('Give the rule a Name you will recognize.') }}</li>
            <li>{{ __('Choose how to Match the description — Contains, Starts with, Equals, Matches regex, or Same payee (which ignores reference numbers, dates, and amounts) — and type the Pattern. Matching is always case-insensitive.') }}</li>
            <li>{{ __('Pick the account to Categorize to, and optionally the Vendor / contact the payee belongs to.') }}</li>
            <li>{{ __('Set a Priority if you have overlapping rules — lower numbers win first — and leave Active on.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/banking/bank-rules.png') }}"
            alt="{{ __('The New rule form with name, match type, pattern, categorize-to account, and priority fields') }}"
            caption="{{ __('A bank rule. When an imported line’s description matches the pattern, its category is filled in for you on the import review and For Review screens.') }}"
        />

        <x-docs.callout type="note">
            {{ __('Rules apply to imported lines your books do not already have. The first active rule that matches, in priority order, wins and pre-fills the category — you still confirm the line before anything posts. When two rules share a priority, the more specific one wins (Equals, then Same payee, Starts with, Contains, Regex). Editing a rule never touches transactions you already categorized. Rules you create with Always do this on the import or For Review screens are listed here too.') }}
        </x-docs.callout>

        {{-- ───────────────────────── For Review ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('The For Review feed') }}</flux:heading>
        <flux:text>
            {{ __('For Review is a standing queue of imported bank transactions, across every account, that are still waiting to be categorized. Any line you left Unmatched or Suggested when you imported a statement waits here until you decide what it is — so you can import now and categorize later. Open the Import statement screen and select Go to For Review at the top.') }}
        </flux:text>

        <p><strong>{{ __('To work the For Review feed:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open For Review. Narrow to one account with the Account selector if you like.') }}</li>
            <li>{{ __('For each line, pick a Category, optionally the Vendor it was paid to (money in: the customer) and the tax included in the amount, then select Accept — or Confirm, when the app has pre-filled the line for you — to post it. When the vendor has open bills you can choose Pay bill (one bill for the same amount) or Pay bills… (several bills, or an employee’s reimbursement) instead of Record as expense.') }}</li>
            <li>{{ __('Select Split to divide one transaction across several categories, each part with its own tax if needed — the parts must add up to the total before you can save. A split outflow is recorded as an expense to the vendor chosen on the row.') }}</li>
            <li>{{ __('Select Exclude to set aside a line you do not want on the books; flip the Excluded toggle to see excluded lines and Include them again.') }}</li>
            <li>{{ __('Tick several lines and use the bulk bar to Categorize them all at once — optionally to one vendor — or Exclude them.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/banking/review.png') }}"
            alt="{{ __('The For Review feed listing imported transactions with category and contact selectors and Accept, Split, and Exclude buttons') }}"
            caption="{{ __('The For Review feed. Bank rules and your own history pre-fill the Category and Contact columns; Confirm or Accept posts the line, Split divides it across categories, Exclude sets it aside.') }}"
        />

        <x-docs.callout type="tip" heading="{{ __('Suggested transfers') }}">
            {{ __('When the feed holds a withdrawal from one account and a matching deposit into another a few days apart, the app surfaces them together as a Suggested transfer. Select Record transfer to book both sides as one transfer instead of two separate transactions — handy for money you moved between your own accounts.') }}
        </x-docs.callout>

        <x-docs.callout type="note" heading="{{ __('What Accept does to your books') }}">
            {{ __('Accepting a line with no vendor or tax posts a balanced journal entry: money in debits the bank account and credits the category you chose; money out debits the category and credits the bank. Accepting an outflow with a vendor or a tax code records an Expense — the statement amount is treated as including the tax, so the tax is split out for your return and the payment still equals the statement to the cent. Choosing Pay bill or Pay bills… records one bill payment applied across the chosen bills (an employee reimbursement posts to Employee Reimbursements Payable). Bulk Categorize records plain expenses with no tax and no bills. Every entry is linked back to the import for your audit trail, and the line leaves the queue.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Reconcile ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reconcile an account') }}</flux:heading>
        <flux:text>
            {{ __('Reconciling proves your records match the bank. You enter the statement’s ending balance and tick off every transaction the bank cleared; when the two agree, you complete the reconciliation and that period is confirmed.') }}
        </flux:text>

        <p><strong>{{ __('To reconcile an account:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('From the Bank register, choose Reconcile on the Actions menu — it opens on the account you were viewing.') }}</li>
            <li>{{ __('Select Reconcile, enter the statement’s ending date and ending balance (plus any service charge or interest earned), and choose Continue. The service charge and interest dates follow the statement date until you change them, and the accounts you pick are remembered for that bank account next month.') }}</li>
            <li>{{ __('Tick each transaction that appears on your statement. The Difference figure shows how far off you still are.') }}</li>
            <li>{{ __('Keep ticking until the Difference reads 0.00.') }}</li>
            <li>{{ __('Select Reconcile now to complete it and lock the period in.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/banking/reconcile.png') }}"
            alt="{{ __('The Reconcile screen listing past reconciliations with their statement dates and ending balances') }}"
            caption="{{ __('The Reconcile screen. Past reconciliations stay listed here — open one to see exactly which transactions were cleared, or use “Undo last reconciliation” to reopen the period.') }}"
        />

        <x-docs.callout type="tip" heading="{{ __('Drop your statement to auto-fill the figures') }}">
            {{ __('On the Begin reconciliation panel, drop your bank statement (PDF or OFX/QFX) onto “Drop your statement to auto-fill” and the app reads the ending balance and statement date for you — both stay editable, and the file is kept to attach to the reconciliation.') }}
        </x-docs.callout>

        <x-docs.callout type="note">
            {{ __('A reconciliation balances only when the Difference is 0.00 — that means every cleared transaction adds up to the statement balance you entered. If you cannot get there, look for a transaction you forgot to record, or one cleared by mistake.') }}
        </x-docs.callout>

        <x-docs.callout type="warning" heading="{{ __('Completing a reconciliation locks the period') }}">
            {{ __('When you complete a reconciliation it locks the account through the statement date: the app will refuse to post or void any cheque, receipt, bill payment, or transfer dated inside the reconciled window, so a finished reconciliation can never drift. To change something in that window, use “Undo last reconciliation” first.') }}
        </x-docs.callout>

        <x-docs.callout type="tip" heading="{{ __('Edit the starting figures mid-reconciliation') }}">
            {{ __('Caught a typo in the statement date or opening balance after you have already ticked off twenty transactions? Select Edit details on the reconciliation panel and change the statement date, opening balance, service charge, or interest in place — your cleared ticks are kept. If the service charge or interest amount changes, the app voids the old adjustment entry and reposts a new one so the books stay correct. Only the date wrong? You can also open the adjustment’s journal entry from the bank register and edit its date, number, or memo there — the accounts and amounts stay locked to the reconciliation.') }}
        </x-docs.callout>

        <x-docs.figure
            src="{{ asset('docs/screenshots/banking/reconcile-edit-details.png') }}"
            alt="{{ __('Editing the starting figures on an in-progress reconciliation') }}"
            caption="{{ __('Editing the starting figures mid-reconciliation. The transactions you already cleared stay cleared.') }}"
        />

        <x-docs.callout type="tip" heading="{{ __('Auto-attach your bank statement on completion') }}">
            {{ __('Drop the bank-statement PDF (or any supporting file) into the Statement & documents drop zone on the reconciliation page. The file waits there until you complete the reconciliation, then attaches itself automatically — no separate Upload now step. Anything you drop in stays as a regular attachment on the reconciliation record for your audit trail.') }}
        </x-docs.callout>

        <x-docs.figure
            src="{{ asset('docs/screenshots/banking/reconcile-attach.png') }}"
            alt="{{ __('The auto-attach drop zone on the reconciliation page') }}"
            caption="{{ __('The auto-attach drop zone. Files attach to the reconciliation when you finalize it.') }}"
        />

        {{-- ───────────────────────── Transfers ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Transfers') }}</flux:heading>
        <flux:text>
            {{ __('A transfer moves money between two of your own accounts — chequing to savings, or a bank to a credit card. No income or expense is recorded; it is pure movement of cash you already have.') }}
        </flux:text>

        <p><strong>{{ __('To transfer between accounts:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Banking → Transfers, then select New transfer.') }}</li>
            <li>{{ __('Choose the From account (where the money leaves) and the To account (where it lands).') }}</li>
            <li>{{ __('Enter the Date and the Amount, plus an optional Memo.') }}</li>
            <li>{{ __('Select Post transfer to finalize it, or Save draft to keep working on it later.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/banking/transfer-create.png') }}"
            alt="{{ __('The New transfer form with From account, To account, date, and amount fields') }}"
            caption="{{ __('The New transfer form. The same amount leaves one account and arrives in the other.') }}"
        />

        <x-docs.callout type="note">
            {{ __('Posting a transfer debits the destination account and credits the source account in one entry. If the two accounts hold different currencies, the form asks for both the amount sent and the amount received, converts each to your home currency at the transfer date’s rates, and books any spread to Exchange Gain or Loss.') }}
        </x-docs.callout>

        <x-docs.callout type="note">
            {{ __('Bank and credit-card accounts always appear in the From and To lists. To move money to or from another account — a line of credit, say — turn on “Include in transfers” for that account in the Chart of Accounts.') }}
        </x-docs.callout>

        <x-docs.callout type="warning">
            {{ __('A posted transfer cannot be edited. To correct one, void it and create a new transfer with the right details.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Cheques ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Cheques') }}</flux:heading>
        <flux:text>
            {{ __('Write a cheque for a payment you made straight from a bank account without a vendor bill — a service fee, an owner draw, a refund, or a one-off purchase. Each cheque has a payee, a date, and one or more expense lines, and it can be printed onto pre-printed cheque stock.') }}
        </flux:text>

        <p><strong>{{ __('To write and print a cheque:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Banking → Cheques, then select Write cheque.') }}</li>
            <li>{{ __('Choose the Bank account it is drawn on. The Cheque # fills in automatically.') }}</li>
            <li>{{ __('Set the Date. In Pay to the order of, start typing and pick the vendor, customer or employee. For a one-off payee choose Add … as Other name — it is created on the spot. To set up a full vendor, customer or employee record instead, choose Create … as a new …, which opens that page in a new tab; come back and pick the new name.') }}</li>
            <li>{{ __('On each line, choose the Account, type a Description, and enter the Amount (add a Tax code if needed).') }}</li>
            <li>{{ __('Select Add line for more than one expense, and check the Total.') }}</li>
            <li>{{ __('Select Post cheque to finalize it, or Save draft to keep working on it later.') }}</li>
            <li>{{ __('To print, open the posted cheque and use the Print action — it renders a cheque-formatted PDF for pre-printed stock.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/banking/cheque-create.png') }}"
            alt="{{ __('The Write cheque form with bank account, the Pay to the order of payee picker, and expense lines') }}"
            caption="{{ __('The Write cheque form. Add as many expense lines as the cheque needs — the Total at the bottom is what gets paid.') }}"
        />

        <x-docs.callout type="note">
            {{ __('Posting a cheque credits the bank account it is drawn on and debits the expense (or other) accounts on its lines, so the payment reaches your books right away.') }}
        </x-docs.callout>

        <x-docs.callout type="warning" heading="{{ __('Editing a posted cheque') }}">
            {{ __('A posted cheque can’t be edited. To correct one, open it and choose Actions → Void — a reversing entry is posted — then write a replacement. Only drafts can be changed on the form.') }}
        </x-docs.callout>

        <x-docs.callout type="note" heading="{{ __('What shows in the cheque list') }}">
            {{ __('The Cheques list gathers the cheques you write here alongside any printed payroll cheques, which link back to the pay run that produced them. For a payment against a vendor bill, use Bill payments instead — those don’t appear here.') }}
        </x-docs.callout>

        <x-docs.callout type="tip" heading="{{ __('Payees and other names') }}">
            {{ __('Every payee you pick is linked to the cheque, so its name is a link on the cheque list and on the cheque itself — select it to see everything paid to that name. Other names are one-time payees that aren’t vendors, customers or employees; they live under Settings → Lists → Other names, where you can rename one, mark it inactive, or convert it into a vendor, customer or employee once it turns out to be more than a one-off.') }}
        </x-docs.callout>

        <x-docs.callout type="tip" heading="{{ __('Do the math right in the amount field') }}">
            {{ __('Each Amount cell on the cheque is a quick calculator. Type an expression like 1050+52.50, watch the tape pop up showing each operation, and press Enter to commit the final value. Handy when you are splitting a single cheque across several expense lines and only know the line totals.') }}
        </x-docs.callout>

        <x-docs.figure
            src="{{ asset('docs/screenshots/banking/cheque-calculator.png') }}"
            alt="{{ __('A cheque line showing the in-place calculator tape with each operation listed') }}"
            caption="{{ __('The amount-field calculator on a cheque line. Type math, review the tape, press Enter to commit.') }}"
        />

        {{-- ───────────────────────── Deposits ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Deposits') }}</flux:heading>
        <flux:text>
            {{ __('A deposit bundles several customer receipts (and any other cash inflows) into the single line that shows up on your bank statement. If you walk three cheques to the bank and they appear as one $1,250 deposit, group those three receipts together so reconciliation matches the statement line for line.') }}
        </flux:text>

        <p><strong>{{ __('To record a deposit:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Banking → Deposits, then select Make deposit.') }}</li>
            <li>{{ __('Choose the bank account in Deposit to. The Deposit # and Date fill in automatically.') }}</li>
            <li>{{ __('Under Undeposited receipts, tick each receipt that is part of this deposit.') }}</li>
            <li>{{ __('Use Add line under Other deposits for any cash that did not come from a receipt — interest, a tax refund, and so on. Enter a negative amount to net out a bank or merchant fee.') }}</li>
            <li>{{ __('Check the Deposit total, then select Save & post — or Save draft to post it later.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/banking/deposit-create.png') }}"
            alt="{{ __('The Make deposit form with a deposit-to account, undeposited receipts, and an other-deposits section') }}"
            caption="{{ __('The Make deposit form. Receipts you sent to Undeposited Funds wait here until you group them into a deposit.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('Why undeposited funds matter') }}">
            {{ __('When you receive a customer payment, you can send it to Undeposited Funds instead of straight to the bank. Those receipts wait here until you make a deposit, which then posts one combined line to your bank account — exactly the way the bank records it. That is what lets your books line up cleanly when you reconcile.') }}
        </x-docs.callout>

        {{-- ────────────── Edit or duplicate a posted deposit ────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Edit or duplicate a posted deposit') }}</flux:heading>
        <flux:text>
            {{ __('A posted deposit can be edited in place when you need to correct an amount, swap a line, or move it to a different date. You can also duplicate one when a similar deposit lands the following week — the form opens pre-filled so you only adjust what changed.') }}
        </flux:text>

        <p><strong>{{ __('To edit a posted deposit:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Banking → Deposits and select the deposit you want to change.') }}</li>
            <li>{{ __('Select Edit. The original lines load into the deposit form.') }}</li>
            <li>{{ __('Adjust the Date, Deposit to account, receipts, or other-deposit lines as needed.') }}</li>
            <li>{{ __('Select Save changes to repost the deposit.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/banking/deposit-edit.png') }}"
            alt="{{ __('A posted deposit reopened in the deposit form for editing') }}"
            caption="{{ __('Editing a posted deposit. The same form you used to make the deposit is reused for changes.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('What a repost does to your books') }}">
            {{ __('Reposting an edited deposit rebuilds the lines on the same journal entry it originally created — no new entry, no void-and-replace. The source link from each receipt stays intact, so the receipt still shows as deposited. If either the old or new date falls inside a completed reconciliation, the app blocks the change until you undo that reconciliation.') }}
        </x-docs.callout>

        <p><strong>{{ __('To duplicate a deposit:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the posted deposit you want to copy.') }}</li>
            <li>{{ __('Select Duplicate. A new deposit form opens with the bank account, memo, and other-deposit lines copied across.') }}</li>
            <li>{{ __('Pick the undeposited receipts that belong on the new deposit, adjust the date, and select Save & post.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/banking/deposit-duplicate.png') }}"
            alt="{{ __('The Duplicate button on a posted deposit') }}"
            caption="{{ __('The Duplicate button on a posted deposit. The new form skips the original receipt lines so you pick fresh ones.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('Source-linked journal entries are read-only') }}">
            {{ __('Every cheque, deposit, transfer, invoice, bill, and receipt creates the journal entry behind it. When you open one of those entries directly, you will see a blue Source badge linking back to the document — and Edit, Void, and Reverse are replaced by a “View source” button. Make changes on the document itself (the deposit, the cheque, the bill) and the journal entry follows along.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Related reports ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reports tied to this area') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('General Ledger — every posting against a bank account over a date range.') }}</li>
            <li>{{ __('Reconciliation history — past reconciliations and the transactions cleared on each.') }}</li>
            <li>{{ __('Cash Flow — how cash moved in and out across the period.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
