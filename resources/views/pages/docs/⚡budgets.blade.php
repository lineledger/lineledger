<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Budgets')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Budgets')"
        :subheading="__('Set monthly targets for income and expense accounts, then track how you are doing against them.')"
    >
        <flux:text>
            {{ __('A budget is your plan for the year: how much you expect each income account to earn and each expense account to cost, broken down by fiscal month. Once a budget is in place, you can compare it to your actual numbers at any time to see where you are ahead, behind, or right on plan. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        <flux:text>
            {{ __('Budgets live under Reports in the sidebar. Each budget covers one fiscal year and holds twelve monthly amounts for every account you choose to plan against. You can keep as many budgets as you like — one per fiscal year, or several what-if versions for the same year.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/budgets/list.png') }}"
            alt="{{ __('The Budgets list showing each saved budget with its fiscal year, scope, and account count') }}"
            caption="{{ __('The Budgets list. Each row shows the fiscal year, the scope (a class or location, or “All”), and how many accounts are budgeted. Use the Actions menu on a row to open Budget vs. Actual, edit, duplicate, or delete that budget.') }}"
        />

        {{-- ───────────────────────── Show or hide Budgets ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Show or hide Budgets') }}</flux:heading>
        <flux:text>
            {{ __('Budgets is turned on by default, so the link is already under Reports in the sidebar. It is an optional feature, so if your organization does not budget you can switch it off — and switch it back on whenever you need it. Hiding the feature never deletes any budgets you have already saved.') }}
        </flux:text>

        <p><strong>{{ __('To show or hide Budgets:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Organizations from the sidebar.') }}</li>
            <li>{{ __('In the Features section, turn Budgets on or off.') }}</li>
            <li>{{ __('Select Save. The Budgets link appears or disappears under Reports in the sidebar.') }}</li>
        </ol>

        {{-- ──────────────────────── Create a budget ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Create a budget') }}</flux:heading>
        <flux:text>
            {{ __('A budget is a grid of accounts down the side and fiscal months across the top. Each cell is the amount you expect for that account in that month. Only income and expense accounts can be budgeted — balance-sheet accounts are not on the list.') }}
        </flux:text>

        <p><strong>{{ __('To create a budget:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Reports → Budgets, then select New budget.') }}</li>
            <li>{{ __('Enter a Name and choose the Fiscal year. The year is the one your fiscal year starts in — the month columns are anchored to your fiscal-year start month from Settings, so a July start makes the first column July.') }}</li>
            <li>{{ __('Optionally scope the budget to a Class or Location so it plans for a single division or site. Leave both on “All” for a company-wide budget.') }}</li>
            <li>{{ __('Choose how to start under Start from: Blank for an empty grid, Prior-year actuals to seed each cell with what actually happened last year, or Copy existing budget to clone another budget. Select Apply.') }}</li>
            <li>{{ __('For each row, pick an Account, then type the planned amount for each of the twelve months. The Total column updates as you type.') }}</li>
            <li>{{ __('Select Add account to add another row. Use the X on the right of a row to remove it.') }}</li>
            <li>{{ __('Select Save budget.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/budgets/form.png') }}"
            alt="{{ __('The Budget form with a twelve-month grid and one row per account') }}"
            caption="{{ __('The Budget form. Leave a month blank to mean zero; the row total updates as you type.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('Seeding from Prior-year actuals is the fastest way to start. Apply the seed, then tweak the months where you expect this year to look different — a price increase, a new hire, a one-off project. It is much faster than typing every cell from scratch. The seed only fills accounts that actually had activity last year, so empty rows do not clutter the grid.') }}
        </x-docs.callout>

        <x-docs.callout type="note" heading="{{ __('A few rules the form enforces') }}">
            {{ __('An account can appear on only one row, and at least one account must be budgeted before you can save. A row where every month is zero carries no information, so it is dropped on save — that lets you leave blank rows in the grid without saving noise. Each amount field accepts the same quick math you use elsewhere in the app.') }}
        </x-docs.callout>

        <x-docs.callout type="note">
            {{ __('Budgets are a planning tool. They do not post to the general ledger and they never affect your reports outside the Budget reports — your actuals stay completely untouched.') }}
        </x-docs.callout>

        {{-- ────────────────────────── Budget reports ────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Compare budget to actual') }}</flux:heading>
        <flux:text>
            {{ __('Once a budget is saved, three reports use it to show how you are tracking. Find them on the All Reports page under Company & Financial, or open Budget vs. Actual straight from the Actions menu on a budget row. Star any report to pin it to your sidebar.') }}
        </flux:text>

        <p><strong>{{ __('To run Budget vs. Actual:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Reports → All Reports → Budget vs. Actual.') }}</li>
            <li>{{ __('Choose the Budget and the date range you want to compare against. Budget months whose first day falls inside the range are included, so any window — a month, a quarter, or year-to-date — lines up against the matching slice of the plan.') }}</li>
            <li>{{ __('If your organization tracks Classes or Locations, use those filters to narrow the comparison to one division or site. A budget that is itself scoped to a class or location automatically filters the actuals to that same slice.') }}</li>
            <li>{{ __('Read across each row: Actual is what your books say, Budget is what you planned, Variance is the difference, and % is the variance as a share of the budgeted amount. Favourable variances show in green, unfavourable in red — for income, higher actuals are favourable; for expenses, lower actuals are favourable.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/budgets/vs-actual.png') }}"
            alt="{{ __('The Budget vs. Actual report grouped into Income, Cost of Goods Sold, and Expenses with actual, budget, variance, and percentage columns') }}"
            caption="{{ __('The Budget vs. Actual report. Accounts are grouped like an income statement, with section subtotals and Gross Profit and Net Income lines at the bottom.') }}"
        />

        <flux:text>
            {{ __('The report is laid out like an income statement: accounts are grouped into Income, Cost of Goods Sold, and Expenses, each section is subtotalled, and Gross Profit and Net Income lines summarize the whole plan against reality. Rows where both the actual and the budget are zero are hidden to keep the report tight. Select CSV to download the comparison, and use the report title to rename or memorize this view so you can reopen it with the same budget and date range.') }}
        </flux:text>

        <flux:heading size="lg" class="mt-8">{{ __('The two companion reports') }}</flux:heading>
        <flux:text>
            {{ __('Two more reports give you different angles on the same numbers, each with a Budget selector at the top.') }}
        </flux:text>

        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Budget Overview lays the chosen budget out as a twelve-month grid — one row per account, the planned amount in each fiscal month, a row total, plus column totals and a grand total. It shows the plan itself, with no actuals, so it is the view to read or print when you just want to see what is on the budget.') }}</li>
            <li>{{ __('Budget vs. Actual by Month is the same twelve-column grid, but every cell compares against your books. Use the Show selector to switch the whole grid between Variance, Actual, and Budget, so you can spot the month a line drifted off plan.') }}</li>
        </ul>

        <x-docs.figure
            src="{{ asset('docs/screenshots/budgets/by-month.png') }}"
            alt="{{ __('The Budget vs. Actual by Month report showing a twelve-month grid grouped by income-statement section with a Show selector set to Variance') }}"
            caption="{{ __('Budget vs. Actual by Month. The Show selector flips the grid between Variance, Actual, and Budget without changing anything else.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('Keep a budget per fiscal year so you build up history. To start next year, open last year\'s budget and use Duplicate from the Actions menu — then bump the fiscal year and adjust where the plan changes. Or create the new budget with Start from set to Copy existing budget.') }}
        </x-docs.callout>

        <x-docs.callout type="warning">
            {{ __('A budget stays fully editable at any time — there is no period lock on a plan, because budgets never post to the ledger. That is handy, but it also means a budget can quietly drift to match your actuals. Once the year is under way, treat a saved budget as a fixed plan and resist editing it, so the variance you see really is what changed.') }}
        </x-docs.callout>

        {{-- ──────────────────────── Related reports ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reports tied to this area') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Budget vs. Actual — actual, budget, variance, and percentage for the date range you choose, grouped like an income statement, with a CSV export.') }}</li>
            <li>{{ __('Budget Overview — the twelve-month grid of a saved budget, with row, column, and grand totals, ready to read or print.') }}</li>
            <li>{{ __('Budget vs. Actual by Month — a twelve-month grid that shows variance, actual, or budget per fiscal month for every account.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
