<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Reports')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Reports')"
        :subheading="__('Run financial statements, aging, sales tax, and analysis — all read live from your posted books.')"
    >
        <flux:text>
            {{ __('Every report in LineLedger reads directly from your posted journal entries. Nothing is hand-calculated and there is no overnight rebuild — whatever you see reflects every transaction posted up to the moment you opened the page. Pick a date and the report recalculates against the ledger on the spot. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        {{-- ───────────────────────── Finding a report ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Finding a report') }}</flux:heading>
        <flux:text>
            {{ __('The Reports hub is the single place every report lives. Open it from Reports in the sidebar. Reports are grouped the way you think about your business — Company & Financial, Customers & Receivables, Vendors & Payables, Sales, Purchases, Inventory, Employees & Payroll, Sales Tax, Accountant & Taxes, Lists, and Combined / Multi-company — so you can scan to the area you need. A few groups appear only when they apply to you: a Non-profit group for non-profits and charities, and Membership and Fundraising once you turn those features on.') }}
        </flux:text>

        <p><strong>{{ __('To open a report:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Select Reports in the sidebar.') }}</li>
            <li>{{ __('Browse to the group you want, or type into the Search reports box at the top-right to filter the catalog by name.') }}</li>
            <li>{{ __('Select the report card to open it.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/reports/hub.png') }}"
            alt="{{ __('The Reports hub showing report cards grouped by area') }}"
            caption="{{ __('The Reports hub. Use the Memorized link in the top-right to jump straight to reports you have saved.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('Run a report often with the same dates? Save it with Memorize and it appears under Memorized at the top of the hub — see Saving and revisiting reports below.') }}
        </x-docs.callout>

        <x-docs.callout type="tip" heading="{{ __('Pin reports to Favorites for one-click access') }}">
            {{ __('On the Reports hub, select the star on any report card to pin it. Pinned reports gather under a Favorites heading at the top of the hub, and appear as shortcuts in the Reports group in the sidebar — which otherwise stays compact with just All Reports and Budgets. Select the star again to unpin. Pin the three or four statements your team runs every week and you will rarely need to open the hub.') }}
        </x-docs.callout>

        <x-docs.figure
            src="{{ asset('docs/screenshots/reports/favorites.png') }}"
            alt="{{ __('The Reports hub with a Favorites section at the top holding the pinned reports') }}"
            caption="{{ __('Pinned reports gather under a Favorites heading at the top of the hub — and also appear as shortcuts in the sidebar\'s Reports group, beneath All Reports and Budgets.') }}"
        />

        {{-- ─────────────────── Reading a financial statement ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reading a financial statement') }}</flux:heading>
        <flux:text>
            {{ __('Most reports share the same control bar across the top, so once you learn one you know them all. These are the controls you will use again and again:') }}
        </flux:text>

        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Period — a quick picker for common ranges like This Fiscal Year-to-date, This Month, or Last Quarter. Choosing Custom lets you set the dates by hand.') }}</li>
            <li>{{ __('As of — point-in-time reports (Balance Sheet, aging, Trial Balance) take a single As of date. Activity reports over a range (Income Statement, Cash Flow) take a Start and End date instead.') }}</li>
            <li>{{ __('Compare to — a dropdown with None, Prior period, or Prior year. Picking either adds a side-by-side column so you can see the change at a glance, and the report subtitle prints both date ranges.') }}</li>
            <li>{{ __('Title — type your own heading here and it prints on the report and its exports; leave it blank to use the default.') }}</li>
            <li>{{ __('Download — export the report as PDF, CSV, or XLSX from the dropdown.') }}</li>
            <li>{{ __('Memorize — save the report with its current settings so you can reopen it later in one click.') }}</li>
            <li>{{ __('Sections — on the Balance Sheet and Income Statement, define your own named sub-groups with subtotals (covered below).') }}</li>
        </ul>

        <x-docs.callout type="note">
            {{ __('The Income Statement and a few others add a Class and a Location filter so you can narrow the report to one part of the business. Leave them on All classes and All locations to include everything.') }}
        </x-docs.callout>

        <p><strong>{{ __('To run the Balance Sheet:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Reports and select Balance Sheet from the Company & Financial group.') }}</li>
            <li>{{ __('Choose a Period, or set Period to Custom and pick the As of date you want the snapshot taken at.') }}</li>
            <li>{{ __('Set Compare to → Prior period or Prior year if you want last period’s or last year’s figures beside this one. Leave it at None for a single-column report.') }}</li>
            <li>{{ __('Read the result on screen, or select Download to save a PDF, CSV, or XLSX copy.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/reports/balance-sheet.png') }}"
            alt="{{ __('The Balance Sheet with the Period, As of, Compare to, Title, Memorize, Sections, and Download controls along the top') }}"
            caption="{{ __('The Balance Sheet control bar. The same Period / As of / Compare to / Download controls appear on most reports.') }}"
        />

        {{-- ─────────────────── Charts on the statements ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Charts on the financial statements') }}</flux:heading>
        <flux:text>
            {{ __('The Balance Sheet, Income Statement, and Cash Flow Statement each carry a chart panel that visualizes the same numbers you are reading. It sits at the top of the report, collapsed by default — select Show to open it, Hide to tuck it away. The charts always reflect the period and comparison you have set, and update the moment you change them.') }}
        </flux:text>

        <p><strong>{{ __('To see a report as a chart:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the Balance Sheet, Income Statement, or Cash Flow Statement and set your period.') }}</li>
            <li>{{ __('Select Show on the Charts panel at the top of the report.') }}</li>
            <li>{{ __('Use the tabs to switch between the available views for that report.') }}</li>
            <li>{{ __('Select PNG, PDF, or Print to take the chart with you.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/reports/chart-panel.png') }}"
            alt="{{ __('The Income Statement chart panel expanded, showing a profit-bridge chart with tabs for other views and PNG, PDF, and Print buttons') }}"
            caption="{{ __('The chart panel on the Income Statement. The tabs along the top switch between the views; the buttons export the active chart.') }}"
        />

        <flux:text>
            {{ __('Each report offers the views that suit it. You switch between named views with the tabs — the chart type for each is chosen for you:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li><strong>{{ __('Balance Sheet') }}</strong> — {{ __('Assets = Liabilities + Equity (the accounting equation as two stacked bars), Asset composition and Liabilities & equity breakdowns, and a Current vs prior comparison when a comparison column is on.') }}</li>
            <li><strong>{{ __('Income Statement') }}</strong> — {{ __('a Profit bridge from revenue down to net income, a Summary bar, and Expense breakdown and Income breakdown rings of your biggest categories.') }}</li>
            <li><strong>{{ __('Cash Flow Statement') }}</strong> — {{ __('a Cash bridge from opening to closing cash, an Activities bar of operating / investing / financing, and an Operating drivers breakdown.') }}</li>
        </ul>

        <x-docs.callout type="note">
            {{ __('Charts only draw what the period actually contains. A view with nothing to show reads “No data to chart for this period.”, and breakdown rings appear only once there are at least a couple of categories to compare. Pick a wider date range if a chart looks empty.') }}
        </x-docs.callout>

        {{-- ─────────────────── The core financial statements ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('The core financial statements') }}</flux:heading>
        <flux:text>
            {{ __('Five reports answer the questions every business owner and accountant asks. They all live in the Company & Financial and Accountant & Taxes groups.') }}
        </flux:text>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Report') }}</flux:table.column>
                <flux:table.column>{{ __('What it answers') }}</flux:table.column>
                <flux:table.column>{{ __('Date control') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ __('Balance Sheet') }}</flux:table.cell>
                    <flux:table.cell>{{ __('What the business owns, owes, and the residual equity at one moment.') }}</flux:table.cell>
                    <flux:table.cell>{{ __('As of') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ __('Income Statement') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Revenue minus expenses over a range — your profit or loss for the period.') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Start / End') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ __('Cash Flow') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Where cash came from and went, split into operating, investing, and financing.') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Start / End') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ __('Trial Balance') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Every account’s debit or credit balance — the first check the books are consistent.') }}</flux:table.cell>
                    <flux:table.cell>{{ __('As of') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ __('General Ledger') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Every journal-entry line in posting order, so you can trace any balance.') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Start / End') }}</flux:table.cell>
                </flux:table.row>
            </flux:table.rows>
        </flux:table>

        <flux:heading size="md" class="mt-6">{{ __('Balance Sheet') }}</flux:heading>
        <flux:text>
            {{ __('The point-in-time snapshot of what the business owns, owes, and the residual equity. Assets always equal liabilities plus equity — if the report does not balance, something was posted incorrectly to the ledger. Net income for the year so far appears inside equity as “Net income (YTD)”.') }}
        </flux:text>

        <x-docs.callout type="tip" heading="{{ __('Comparing to a prior period or year') }}">
            {{ __('Use the Compare to dropdown in the control bar to add a side-by-side column. Choose None for no comparison, Prior period for the immediately-preceding range of the same length, or Prior year for the same dates one calendar year earlier. The report subtitle expands to show both date ranges so the comparison is explicit on every export.') }}
        </x-docs.callout>

        <x-docs.figure
            src="{{ asset('docs/screenshots/reports/comparison-basis.png') }}"
            alt="{{ __('The Compare to dropdown on a report, showing None, Prior period, and Prior year options') }}"
            caption="{{ __('The Compare to dropdown adds a Prior period or Prior year column. Available on the Balance Sheet, Income Statement, and Cash Flow Statement.') }}"
        />

        <x-docs.callout type="tip" heading="{{ __('Drill from an account to its transactions') }}">
            {{ __('Account names on the Balance Sheet and Cash Flow Statement are links. Select one to open the Transactions report scoped to that account for the same date range, so you can see the exact journal-entry lines making up the figure. From there you can open any transaction in place.') }}
        </x-docs.callout>

        <x-docs.figure
            src="{{ asset('docs/screenshots/reports/balance-sheet-drill.png') }}"
            alt="{{ __('A Balance Sheet account name being selected, opening the Transactions report scoped to that account') }}"
            caption="{{ __('Selecting an account on the Balance Sheet opens the Transactions report scoped to that account.') }}"
        />

        <flux:heading size="md" class="mt-6">{{ __('Income Statement (Profit & Loss)') }}</flux:heading>
        <flux:text>
            {{ __('Revenue minus expenses over a date range — your profit or loss for the period. Use it to compare months, track gross margin, and see which expense categories are growing. Set a Start and End date, or narrow it to a single Class or Location with the filters in the control bar.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/reports/income-statement.png') }}"
            alt="{{ __('The Income Statement showing income, expenses, and net income with Start, End, Class, and Location controls') }}"
            caption="{{ __('The Income Statement. Income and Expenses are subtotaled, and the bottom line is Net Income.') }}"
        />

        <x-docs.callout type="tip" heading="{{ __('Comparing to a prior period or year') }}">
            {{ __('The Income Statement carries the same Compare to dropdown as the Balance Sheet. Pick Prior period to set last month beside this month for a quick month-over-month read, or Prior year to compare this quarter against the same quarter last year. The subtitle prints both date ranges so PDF exports are unambiguous.') }}
        </x-docs.callout>

        <flux:heading size="md" class="mt-6">{{ __('Cash Flow Statement') }}</flux:heading>
        <flux:text>
            {{ __('Where your cash came from and went over a period, split into operating, investing, and financing activities. It is built the indirect way — starting from net income and adjusting for the non-cash movements — entirely from the ledger, so it always reconciles the opening cash balance to the closing one. Like the other statements, it supports custom sections.') }}
        </flux:text>

        <x-docs.callout type="tip" heading="{{ __('Comparing to a prior period or year') }}">
            {{ __('The Cash Flow Statement supports the same Compare to dropdown: None, Prior period, or Prior year. Use it to see whether operating cash is improving against the same period last year. Account names are also drill-through links — select one to open the Transactions report scoped to that account.') }}
        </x-docs.callout>

        <x-docs.callout type="note" heading="{{ __('Move an account to a different activity') }}">
            {{ __('By default, every balance-sheet account is classified into Operating, Investing, or Financing for the Cash Flow Statement, but you can override that classification per account. Select Move account to activity from any row in the cash-flow sections, pick the activity you want, and the account moves there on every future run of the statement. Income-statement accounts are not eligible — the override applies to balance-sheet accounts only. Combined report groups offer the same control per combined line, from the group’s Cash Flow sections page or from the line’s edit dialog on the group’s edit page.') }}
        </x-docs.callout>

        <p><strong>{{ __('To move an account to a different cash-flow activity:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Reports and run the Cash Flow Statement.') }}</li>
            <li>{{ __('Find the balance-sheet account you want to reclassify and select Move account to activity from its row menu.') }}</li>
            <li>{{ __('Choose Operating, Investing, or Financing.') }}</li>
            <li>{{ __('Save. The next run of the Cash Flow Statement places the account under the new activity for every period.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/reports/cash-flow-activity.png') }}"
            alt="{{ __('The Move account to activity dialog on the Cash Flow Statement with Operating, Investing, and Financing options') }}"
            caption="{{ __('Move account to activity reclassifies a balance-sheet account on the Cash Flow Statement.') }}"
        />

        <flux:heading size="md" class="mt-6">{{ __('Trial Balance') }}</flux:heading>
        <flux:text>
            {{ __('Every account with its debit or credit balance as of a chosen date. Total debits should equal total credits — the trial balance is the accountant’s first check that the books are internally consistent.') }}
        </flux:text>

        <flux:heading size="md" class="mt-6">{{ __('General Ledger') }}</flux:heading>
        <flux:text>
            {{ __('Every journal-entry line, in the order it was posted, so you can trace exactly how an account reached its current balance. View one account with a running balance and opening/closing figures, or all accounts at once. On-screen results are paginated — pick how many rows per page from the page-size selector — while exports always cover the full date range you set, not just the current page.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/reports/gl-pagination.png') }}"
            alt="{{ __('The General Ledger with pagination controls and a page-size selector beneath the table') }}"
            caption="{{ __('The General Ledger paginates rows on screen. Exports stream the entire date range.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('Exports stream large date ranges') }}">
            {{ __('The General Ledger exports to CSV and XLSX. Both stream the dataset in keyset-chunked passes — multi-year ranges export without loading everything into memory, so a full-history pull will not time out or run out of memory. PDF is not offered for the General Ledger because the row counts are typically too large to render usefully. For a printable view, run the Transactions report on the same dates.') }}
        </x-docs.callout>

        <x-docs.callout type="note" heading="{{ __('Custom report sections') }}">
            {{ __('On the Balance Sheet and Income Statement, the Sections button lets you define your own named sub-groups, each with its own subtotal. Assign accounts to a section within their natural area (a current-asset section can only hold current-asset accounts, an income section only income accounts). Sections only regroup accounts and add subtotals — they never post entries or change the report totals.') }}
        </x-docs.callout>

        {{-- ─────────────────── Cash flow forecast ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Cash flow forecast') }}</flux:heading>
        <flux:text>
            {{ __('The reports above look backward at what already happened. The Cash Flow Forecast looks forward: it projects where your bank balance is headed over the coming weeks or months, so you can spot a cash crunch before it arrives. Open it from Reports → Company & Financial → Cash Flow Forecast. Like every report it reads live from your books — your open invoices, open bills, and recent operating run-rate — so the same data always produces the same projection.') }}
        </flux:text>

        <flux:text>
            {{ __('The forecast works on two tracks, so you can tell a near-certainty from an estimate:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li><strong>{{ __('Committed') }}</strong> — {{ __('your book cash today (cheques you have written but that have not cleared are already deducted), plus each open invoice when it is realistically expected, minus each open bill on its due date, plus any post-dated entry already in the ledger — a post-dated cheque or a future-dated bank charge — on the date it is booked. Overdue invoices and bills are assumed to settle in the first period; invoices not yet due are pushed out by how late your customers typically pay, learned from the past year of receipts. A receivable overdue by more than the cut-off you set (90 days unless you change it) is left out and listed separately as doubtful. This is the high-confidence line, and it is the one that drives the low-cash alert.') }}</li>
            <li><strong>{{ __('With run-rate') }}</strong> — {{ __('the committed line plus an estimate of ordinary day-to-day operations, taken from your net operating cash over the last 90 days. Because it already reflects recurring activity, it reads higher when the business has been generating cash and lower when it has been burning it.') }}</li>
        </ul>

        <p><strong>{{ __('To run the cash flow forecast:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Reports and select Cash Flow Forecast from the Company & Financial group.') }}</li>
            <li>{{ __('Choose Weekly to project the next 13 weeks, or Monthly for the next 6 months.') }}</li>
            <li>{{ __('Optionally type a figure into Low-cash alert at — the balance you never want to drop below. The forecast warns you if the committed balance is projected to fall under it.') }}</li>
            <li>{{ __('Optionally change Ignore receivables overdue past — how many days overdue an invoice can be before the forecast stops counting on it.') }}</li>
            <li>{{ __('Read the three summary cards — Cash on hand today, Lowest projected balance, and Recent run-rate — then scan the table period by period. Hover any Expected in or Expected out figure to see exactly which invoices, bills, and post-dated entries make it up.') }}</li>
        </ol>

        <flux:text>
            {{ __('The Cash on hand today card also ties your book balance to the bank once you reconcile: it shows the balance cleared at the bank, the payments written but not yet cleared, and any deposits still in transit. That is why an outstanding cheque does not appear again as a future outflow — it has already left your book balance.') }}
        </flux:text>

        <flux:text>
            {{ __('For each period the table shows the cash Expected in, the cash Expected out, the running Committed balance, and that same balance With run-rate. The cards above it call out your current cash, the lowest the committed balance is projected to reach across the whole horizon, and your recent monthly run-rate, labelled as net cash generated or net cash burned.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/reports/cash-flow-forecast.png') }}"
            alt="{{ __('The Cash Flow Forecast with a Weekly/Monthly toggle, a low-cash alert field, three summary cards, a chart, and a period-by-period table') }}"
            caption="{{ __('The Cash Flow Forecast for Demo Company Inc. The committed balance drives the low-cash alert; the with-run-rate column adds an estimate of ongoing operations.') }}"
        />

        <x-docs.callout type="warning" heading="{{ __('When cash is projected to run low') }}">
            {{ __('Set a low-cash alert and, if the committed balance is projected to dip below it, a banner names the date it happens. The fix is usually one of two things — collect an overdue invoice sooner, or push a bill’s due date out. Both change the underlying documents, and the forecast updates the moment you do.') }}
        </x-docs.callout>

        <x-docs.callout type="note">
            {{ __('The forecast is an estimate, not a promise. The committed line only counts invoices and bills already on your books — it cannot know about a sale you have not invoiced yet — and the run-rate is a simple 90-day average, so a one-off month can skew it. Anything due past the end of the horizon is left out. Treat it as an early-warning signal, then act on the specific invoices and bills behind it.') }}
        </x-docs.callout>

        {{-- ─────────────────── More financial analysis ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('More financial analysis') }}</flux:heading>

        <flux:heading size="md" class="mt-6">{{ __('Profit Insights') }}</flux:heading>
        <flux:text>
            {{ __('Profit Insights explains what moved your bottom line between two periods, instead of just stating the number. Pick a period and a comparison and it surfaces the customers, vendors, and expense categories that grew or shrank the most — so you can see in plain language why this month’s profit differs from last. It lives in the Company & Financial group.') }}
        </flux:text>

        <flux:heading size="md" class="mt-6">{{ __('Budgets') }}</flux:heading>
        <flux:text>
            {{ __('Once you have entered a budget, three reports compare it against your actual results: Budget vs. Actual (totals with the variance per account), Budget Overview (the monthly target amounts you entered), and Budget vs. Actual by Month (the month-by-month picture across the fiscal year). See') }}
            <a class="underline" href="{{ route('docs.budgets') }}" wire:navigate>{{ __('Budgets') }}</a>{{ __(' for how to build a budget in the first place.') }}
        </flux:text>

        <flux:heading size="md" class="mt-6">{{ __('Management report package') }}</flux:heading>
        <flux:text>
            {{ __('Management Reports bundles several statements into one polished PDF — a cover page, a table of contents, and each report you choose — ready to hand to an owner, a board, or a lender. Pick the reports and the period — a completed month, quarter, or fiscal year, or the current one to date — and the app assembles the package as a single download. Balance-style reports (balance sheet, aging, trial balance) are as at the period end, or as of today for a to-date period. An optional Compare to setting adds a prior-period or prior-year column to every report in the package that supports one. Find it under Company & Financial → Management Reports.') }}
        </flux:text>

        {{-- ─────────────────── Transactions ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Transactions') }}</flux:heading>
        <flux:text>
            {{ __('The Transactions report lists posted journal-entry lines across any combination of accounts, contacts, classes, or locations for a date range. It is the workhorse behind the drill-through links on the Balance Sheet and Cash Flow Statement — when you click an account on either, this is the report that opens, pre-filtered to that account and period.') }}
        </flux:text>

        <flux:heading size="md" class="mt-6">{{ __('Exporting transactions') }}</flux:heading>
        <flux:text>
            {{ __('The Transactions report exports to CSV, XLSX, and PDF from the Download dropdown. Rows are streamed straight from the filtered query, so a multi-year export against every account will not run out of memory — pick the dates you need and download.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/reports/transactions-exports.png') }}"
            alt="{{ __('The Transactions report Download dropdown showing CSV, XLSX, and PDF options') }}"
            caption="{{ __('CSV, XLSX, and PDF exports on the Transactions report. All three stream rows from the filtered query for safe large-range downloads.') }}"
        />

        <x-docs.callout type="note">
            {{ __('Use CSV for the smallest, fastest file when you plan to open it in a spreadsheet or pipe it elsewhere. Use XLSX when you want a formatted workbook with column types preserved. Use PDF when the report is being filed with an accountant or attached to an email.') }}
        </x-docs.callout>

        {{-- ─────────────────── Receivables & payables ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Receivables and payables') }}</flux:heading>
        <flux:text>
            {{ __('These reports tell you who owes you and who you owe, and how overdue each balance is. They live in the Customers & Receivables and Vendors & Payables groups.') }}
        </flux:text>

        <flux:heading size="md" class="mt-6">{{ __('AR Aging and AP Aging') }}</flux:heading>
        <flux:text>
            {{ __('Open customer (AR) and vendor (AP) balances bucketed by how overdue they are — Current, 1–30, 31–60, 61–90, and 90+. Use AR Aging to chase receivables and AP Aging to plan upcoming payments. Set an As of date, sort by any column, and toggle Owing only to hide contacts with a zero balance. Each customer or vendor name is a link to their full statement.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/reports/ar-aging.png') }}"
            alt="{{ __('The AR Aging report with Current, 1–30, 31–60, 61–90, 90+, and Total columns per customer') }}"
            caption="{{ __('AR Aging for Demo Company Inc. Turn on “Owing only” to show just the customers with an open balance.') }}"
        />

        <flux:heading size="md" class="mt-6">{{ __('Open Invoices') }}</flux:heading>
        <flux:text>
            {{ __('A flat list of every unpaid customer invoice as of a chosen date, with its document and due dates, days overdue, total, amount paid, and balance owing — the detail behind AR Aging without the buckets. Open it from Reports → Customers & Receivables → Open Invoices, or from the Accounts Receivable card on the dashboard, which now links straight here. Sort the list any way you like and export to XLSX.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/reports/open-invoices.png') }}"
            alt="{{ __('The Open Invoices report listing every unpaid customer invoice with dates, totals, and balance owing') }}"
            caption="{{ __('The Open Invoices report. Linked from the dashboard AR card and exportable to XLSX.') }}"
        />

        <flux:heading size="md" class="mt-6">{{ __('Open Bills') }}</flux:heading>
        <flux:text>
            {{ __('The vendor-side mirror of Open Invoices: every unpaid vendor bill as of a chosen date, with document and due dates, days overdue, total, amount paid, and balance owing. Sort any way you like and export to XLSX or PDF.') }}
        </flux:text>

        <flux:text>
            {{ __('Both Open Invoices and Open Bills include a Close ledger-settled action for the case where a balance was already cleared by a manual journal entry rather than a normal receipt or payment. It marks those documents settled to match the ledger, without posting anything new, so the open list stops showing balances the GL no longer carries.') }}
        </flux:text>

        <flux:heading size="md" class="mt-6">{{ __('Contact statement') }}</flux:heading>
        <flux:text>
            {{ __('Every transaction with a single customer (AR) or vendor (AP) over a date range, with an opening balance, the lined transactions, and a running balance to close. Open it from the AR/AP Aging links or from a contact record. Download it as CSV for spreadsheets, XLSX for a formatted workbook, or PDF to send to the contact.') }}
        </flux:text>

        <flux:heading size="md" class="mt-6">{{ __('Unattributed AR') }}</flux:heading>
        <flux:text>
            {{ __('Posted Accounts Receivable lines that are not tied to a customer — usually the residue of an import or a manual journal entry. The report lets you select those lines and assign them to a customer in bulk, so receivables that were sitting against the control account without a name get attributed correctly and show up on the right statement.') }}
        </flux:text>

        {{-- ─────────────────── Tax ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Tax') }}</flux:heading>

        <flux:heading size="md" class="mt-6">{{ __('Sales Tax') }}</flux:heading>
        <flux:text>
            {{ __('Per-agency tax collected on your sales versus the input tax credits you claimed on purchases, for a chosen period. Each row shows the agency, its payable account, tax collected on sales, tax paid (ITC), and the net owing — what you remit to the agency. A negative net owing means the agency owes you a refund. This report is the source of truth when filing a sales-tax return.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/reports/sales-tax.png') }}"
            alt="{{ __('The Sales Tax report showing collected on sales, paid (ITC), and net owing per tax agency') }}"
            caption="{{ __('Sales Tax for Demo Company Inc., grouped by agency. Net owing is what you remit; a negative figure is a refund due to you.') }}"
        />

        <x-docs.callout type="note">
            {{ __('When you file a period, a tax return captures it as a permanent record — the tax collected and paid, the net owed or refunded, and your payments to the agency. Once a period is filed, the lines and dates of bills and invoices touching that period become read-only to protect the filed numbers.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('The Sales Tax report feeds the Tax returns workflow — its collected, paid, and net-owing figures are exactly the numbers you file. See') }}
            <a class="underline" href="{{ route('docs.tax-returns') }}" wire:navigate>{{ __('Tax returns') }}</a>{{ __(' for how to file a period, record remittances, and reopen a filing.') }}
        </flux:text>

        <flux:heading size="md" class="mt-6">{{ __('1099 Summary (US only)') }}</flux:heading>
        <flux:text>
            {{ __('For US companies, the 1099 Summary totals what you paid each vendor you flagged for 1099 tracking over a calendar year — counting posted bill payments, cheques, and pay-now expenses, but not refunds. Vendors under the $600 reporting threshold are hidden by default, with a toggle to show everyone. Amounts report as nonemployee compensation (1099-NEC Box 1). Export to CSV, XLSX, or PDF. To use it, flag the vendor and record their Tax ID on the vendor record. The report does not appear for Canadian companies.') }}
        </flux:text>

        {{-- ─────────────────── Sales & purchases analysis ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Sales and purchases analysis') }}</flux:heading>
        <flux:text>
            {{ __('These reports slice your activity by who, what, and which seller, so you can spot your best customers, your top-selling items, and where the money goes. They live in the Sales, Purchases, and Inventory groups.') }}
        </flux:text>

        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Sales by Customer — revenue per customer over a period, so you can see where your income concentrates.') }}</li>
            <li>{{ __('Sales by Customer (Detail) — every sales document per customer over a period — invoices and sales receipts, net of credit memos.') }}</li>
            <li>{{ __('Sales by Item — revenue and quantity sold per item or service over a period.') }}</li>
            <li>{{ __('Sales by Rep — revenue per sales rep over a period.') }}</li>
            <li>{{ __('Purchases by Vendor — total spend per vendor over a period.') }}</li>
            <li>{{ __('Purchases by Item — spend and quantity purchased per item over a period.') }}</li>
            <li>{{ __('Open Purchase Orders — purchase orders not yet fully received (shown when purchase orders are turned on).') }}</li>
            <li>{{ __('Inventory Valuation — the current carrying value of every inventory item still on hand.') }}</li>
            <li>{{ __('Stock Status — on-hand quantities and reorder points so you know what to restock.') }}</li>
        </ul>

        {{-- ─────────────────── Non-profit & charity statements ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reports for non-profits and charities') }}</flux:heading>
        <flux:text>
            {{ __('When your organization is set up as a non-profit, a Non-profit group appears with statements that follow the accounting standards for not-for-profit organizations (ASNPO) — presenting net assets by class rather than owner’s equity:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Statement of Financial Position — the non-profit balance sheet: assets, liabilities, and net assets by class.') }}</li>
            <li>{{ __('Statement of Operations — revenue and expenses with the excess (or deficiency) of revenue over expenses for the period.') }}</li>
            <li>{{ __('Statement of Changes in Net Assets — opening to closing net assets by class, with the period’s surplus and any transfers between funds.') }}</li>
        </ul>
        <flux:text>
            {{ __('Registered charities also get the T3010 Summary in the Accountant & Taxes group — the receipted donations, revenue, expenditures, and balance-sheet totals you need for the annual charity information return.') }}
        </flux:text>

        <flux:heading size="md" class="mt-6">{{ __('Membership and fundraising reports') }}</flux:heading>
        <flux:text>
            {{ __('Turn on membership or fundraising and matching groups appear. Membership adds a roster of every member with their level and term, plus dues revenue by level. Fundraising adds donations by donor, donations by restricted fund, and a grants summary. See') }}
            <a class="underline" href="{{ route('docs.members') }}" wire:navigate>{{ __('Members') }}</a>{{ __(' and ') }}<a class="underline" href="{{ route('docs.fundraising') }}" wire:navigate>{{ __('Fundraising') }}</a>{{ __(' for the workflows that feed these reports.') }}
        </flux:text>

        {{-- ─────────────────── Accountant & tax filings ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Accountant and tax filings') }}</flux:heading>
        <flux:text>
            {{ __('The Accountant & Taxes group gathers the reports your accountant reaches for at year-end. The Trial Balance and General Ledger covered above live here too, alongside the CRA-format schedules:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('GIFI Statement — your balance sheet (S100) and income statement (S125) mapped to the CRA’s General Index of Financial Information codes for the T2 corporate return.') }}</li>
            <li>{{ __('T5013 Partnership — the partnership GIFI schedules plus the income allocation across partners.') }}</li>
            <li>{{ __('T2125 Business Activities — the statement of business or professional activities for a sole proprietor, including the capital cost allowance (CCA) schedule.') }}</li>
        </ul>
        <flux:text>
            {{ __('Which of these you see depends on how the company is set up — GIFI for incorporated companies, T5013 for partnerships, T2125 for sole proprietors — so the schedule that matches your filing is the one that appears.') }}
        </flux:text>

        <x-docs.callout type="note">
            {{ __('Payroll has its own set of reports — the payroll register, PD7A and Revenu Québec remittances, T4, T4A and RL-1 slips, the Record of Employment, and more — under the Employees & Payroll group when payroll is turned on. See the') }}
            <a class="underline" href="{{ route('docs.payroll') }}" wire:navigate>{{ __('Payroll') }}</a>{{ __(' guide for those.') }}
        </x-docs.callout>

        <flux:heading size="md" class="mt-6">{{ __('Audit Logs') }}</flux:heading>
        <flux:text>
            {{ __('A timeline of every change made inside the company — who did what, when, and what changed. The report has two tabs. The Accounting tab records every posting action (invoices, bills, credit memos, receipts, payments, cheques, deposits, journal entries, and tax returns: created, posted, reposted, or voided) with the actor, IP, and a snapshot of what changed. The Security tab records sign-ins, password and two-factor changes, team-membership changes, and API-key lifecycle events (created, rotated, revoked).') }}
        </flux:text>
        <flux:text>
            {{ __('Actions taken through the API are attributed to the API key rather than a person, so you can tell automated activity from manual work. The accounting log is append-only and cryptographically chained — each entry is hashed together with the one before it — so tampering is detectable. Use the Verify chain action to confirm the log has not been altered.') }}
        </flux:text>

        {{-- ─────────────────── List reports ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('List reports') }}</flux:heading>
        <flux:text>
            {{ __('The Lists group holds plain reference exports rather than financial statements: an Account List (every account with its type, currency, and balance), a Customer Contact List, and a Vendor Contact List (names, contact details, terms, and open balances). They are handy for a quick directory or a spreadsheet hand-off.') }}
        </flux:text>

        {{-- ─────────────────── Combined reports across companies ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Combined reports across companies') }}</flux:heading>
        <flux:text>
            {{ __('If you keep the books for more than one company, report groups let you consolidate their financial statements into a single view. A group combines two to ten companies that share the same currency: you create the group, add the member companies, and the app maps their accounts together by account type onto shared combined lines. From a group you can then view a combined Income Statement, Balance Sheet, Cash Flow Statement, and Trial Balance — each reading live from the posted books of every member company.') }}
        </flux:text>

        <p><strong>{{ __('To create a report group:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Go to Settings → Report groups and select New group.') }}</li>
            <li>{{ __('Give the group a name and tick the two to ten companies you want to combine. Each company shows its currency beside its name.') }}</li>
            <li>{{ __('Select Create. The app auto-maps accounts across the member companies by type onto shared combined lines.') }}</li>
            <li>{{ __('On the group’s edit page, rename or retype the combined lines, move accounts between lines, or use Auto-map new accounts after you add a company.') }}</li>
            <li>{{ __('Select View reports to open the combined Income Statement, Balance Sheet, Cash Flow Statement, or Trial Balance.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/reports/report-groups.png') }}"
            alt="{{ __('The Report groups settings page listing a combined group with its member companies and a New group button') }}"
            caption="{{ __('Report groups under Settings. Each group lists its member companies and the shared currency; open one to view its combined statements.') }}"
        />

        <flux:text>
            {{ __('Inside a group you can rename or retype the combined lines and organize them into custom sections, and turn on per-company columns to see each company’s figures beside the combined total — on the Cash Flow Statement that includes each company’s net cash per activity and its beginning and ending cash. Combined statements export to CSV and XLSX. A group is a straight roll-up, not a full consolidation — there are no intercompany eliminations, so balances owed between member companies are not removed.') }}
        </flux:text>

        <x-docs.callout type="note">
            {{ __('Every company in a group must use the same currency — you cannot mix currencies in one group. A group is visible to anyone who belongs to all of its member companies, so a colleague on every member company sees the combined reports too.') }}
        </x-docs.callout>

        {{-- ─────────────────── Saving and revisiting reports ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Saving and revisiting reports') }}</flux:heading>

        <flux:heading size="md" class="mt-6">{{ __('Memorized reports') }}</flux:heading>
        <flux:text>
            {{ __('When you have a report set up just the way you like it, select Memorize to save it with its current period, filters, and title. Memorized reports appear under the Memorized link at the top of the Reports hub, so a report you run every month is one click away with the same settings.') }}
        </flux:text>

        <p><strong>{{ __('To memorize a report:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the report and set the period, filters, and title you want.') }}</li>
            <li>{{ __('Select Memorize in the control bar.') }}</li>
            <li>{{ __('Give it a name and save. It now appears under Memorized on the Reports hub.') }}</li>
        </ol>

    </x-pages::docs.layout>
</section>
