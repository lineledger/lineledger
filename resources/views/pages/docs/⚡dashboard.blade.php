<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Dashboard')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Dashboard')"
        :subheading="__('Your at-a-glance view of the business.')"
    >
        <flux:text>
            {{ __('The dashboard is the first page you land on after signing in to a company. It is a live financial overview built as a Livewire component: four headline numbers across the top, a daily insight, a row of charts, and your most recent transactions — all refreshed straight from the posted books. The example below uses our sample business, Demo Company Inc.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/dashboard/dashboard.png') }}"
            alt="{{ __('The Dashboard showing four KPI cards, charts, and a recent transactions list') }}"
            caption="{{ __('The Dashboard for Demo Company Inc. Four summary cards — Cash on hand, Accounts receivable, Accounts payable, and Net income (MTD) — sit across the top, with charts and a recent transactions list below.') }}"
        />

        <flux:heading size="lg" class="mt-8">{{ __('The four summary cards') }}</flux:heading>
        <flux:text>
            {{ __('Each card shows a single headline number and links into the workflow it summarizes, so the dashboard doubles as a launchpad — select a card to jump straight to the report or register behind it.') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li><strong>{{ __('Cash on hand') }}</strong> — {{ __('every bank and undeposited-funds balance added up as of today, with the percent change versus 30 days ago (green when it grew, red when it shrank). Select the card to open the Cash on Hand report, which lists each bank and undeposited-funds account behind the total.') }}</li>
            <li><strong>{{ __('Accounts receivable') }}</strong> — {{ __('the total your customers still owe you, with a count of open invoices. Select the card to drill into the Open invoices report.') }}</li>
            <li><strong>{{ __('Accounts payable') }}</strong> — {{ __('the total you owe vendors across unpaid bills, with a count of how many fall due within the next seven days. Select the card to open the AP Aging report.') }}</li>
            <li><strong>{{ __('Net income (MTD)') }}</strong> — {{ __('income minus expenses so far this month, with the change versus the same span of last month. Select the card to open the Income Statement.') }}</li>
        </ul>

        <x-docs.callout type="tip" heading="{{ __('AR card → Open invoices') }}">
            {{ __('Clicking the Accounts receivable card opens the Open invoices report — a faster, current-balance view than AR Aging when you just need to see who still owes you. See the') }}
            <a class="underline" href="{{ route('docs.reports') }}" wire:navigate>{{ __('Reports') }}</a>
            {{ __('page for the full set of receivable reports, including AR Aging when you do need it bucketed.') }}
        </x-docs.callout>

        <flux:heading size="lg" class="mt-8">{{ __('The daily insight') }}</flux:heading>
        <flux:text>
            {{ __('Just under the cards, a “Daily insight” card surfaces one thing worth knowing about Demo Company Inc. today — an overdue receivable to chase, a bill due soon, sales tax to set aside, a record sales month, and so on. A background job picks the most relevant insight once each morning; on a quiet day the card simply does not appear.') }}
        </flux:text>
        <flux:text>
            {{ __('Each insight has a headline, a one-line explanation, and a button that takes you to the matching page. Select “Past insights” to browse earlier days, or dismiss the card with the × to hide it until tomorrow (that only affects your own screen, not your colleagues’). When the wording was phrased by AI from your aggregate totals, a small AI badge appears — the numbers themselves always come from your posted ledger.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('Cash running low?') }}">
            {{ __('Two of the daily insights watch your cash runway. If open bills are projected to push your committed cash balance below zero within the next quarter, an urgent “Cash may run short around …” insight appears; a softer “Cash is set to dip …” insight fires when the balance stays positive but drops materially. Both link straight to the Cash flow forecast, described next.') }}
        </x-docs.callout>

        <flux:heading size="lg" class="mt-8">{{ __('Cash flow forecast') }}</flux:heading>
        <flux:text>
            {{ __('The Cash flow forecast is a forward-looking report — open it from Reports → Company & Financial, or from the cash-runway insight on the dashboard. It projects where your cash is headed over the next quarter from your open invoices, your open bills, and your recent run-rate, and warns you before the books would go red.') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li><strong>{{ __('Cash on hand today') }}</strong> — {{ __('the same bank-plus-undeposited total the dashboard card shows, used as the forecast’s starting balance.') }}</li>
            <li><strong>{{ __('Lowest projected balance') }}</strong> — {{ __('the low point of the committed track — opening cash plus open invoices and minus open bills, each landing on its due date. This is the high-confidence number that drives the low-cash alert, and it turns red when it falls below your floor.') }}</li>
            <li><strong>{{ __('Recent run-rate') }}</strong> — {{ __('your net operating cash over the last 90 days, shown per month, so you can see whether ongoing operations generate or burn cash.') }}</li>
        </ul>
        <flux:text>
            {{ __('Switch between a Weekly (13-week) and Monthly (6-month) horizon, and set a “Low-cash alert at” floor — the report raises a warning and names the date your committed balance is projected to cross it. The table below the chart breaks each period into Expected in, Expected out, a Committed balance, and a With run-rate column that layers your ongoing operations on top of the committed figures.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/dashboard/cash-flow-forecast.png') }}"
            alt="{{ __('The Cash flow forecast report with summary tiles, a low-cash alert, a forecast chart, and a period-by-period table') }}"
            caption="{{ __('The Cash flow forecast for Demo Company Inc. Summary tiles sit above the chart, and the period table separates the high-confidence committed balance from the run-rate estimate.') }}"
        />

        <x-docs.callout type="tip" heading="{{ __('Where the forecast lives') }}">
            {{ __('The forecast is one of the receivable- and cash-focused reports. The') }}
            <a class="underline" href="{{ route('docs.reports') }}" wire:navigate>{{ __('Reports') }}</a>
            {{ __('page covers the rest of the reporting suite, including the historical Cash Flow statement that explains where cash already went.') }}
        </x-docs.callout>

        <flux:heading size="lg" class="mt-8">{{ __('Charts') }}</flux:heading>
        <flux:text>
            {{ __('Below the cards, the dashboard draws a few charts straight from your posted books so you can read the trend without opening a report. They are always expanded — there is nothing to set up.') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li><strong>{{ __('Cash flow') }}</strong> — {{ __('the last six months as grouped bars, money in beside money out for each month, so you can see whether cash is trending up or down.') }}</li>
            <li><strong>{{ __('Income & expenses') }}</strong> — {{ __('your fiscal year to date with income, expenses, and the resulting net income side by side. It appears once there is activity in the year.') }}</li>
            <li><strong>{{ __('Cash, receivables & payables') }}</strong> — {{ __('today’s cash on hand, what customers owe you, and what you owe vendors, so your liquidity is one glance away.') }}</li>
        </ul>

        <x-docs.figure
            src="{{ asset('docs/screenshots/dashboard/charts.png') }}"
            alt="{{ __('The Dashboard cash-flow chart showing six months of money-in and money-out bars with PNG, PDF, and Print buttons') }}"
            caption="{{ __('The Cash flow chart on the Dashboard for Demo Company Inc. The Income & expenses and Cash, receivables & payables charts sit alongside it, each with the same PNG, PDF, and Print buttons.') }}"
        />

        <x-docs.callout type="tip" heading="{{ __('Export any chart') }}">
            {{ __('Every chart in the app — on the dashboard and on the financial statements — carries the same three buttons: PNG saves it as an image, PDF wraps it in a branded one-page report, and Print opens your browser’s print dialog. Handy for dropping a cash-flow chart into a board deck or emailing it to your accountant.') }}
        </x-docs.callout>

        <flux:heading size="lg" class="mt-8">{{ __('Recent transactions') }}</flux:heading>
        <flux:text>
            {{ __('At the bottom, a single feed lists your latest activity across the whole business — invoices, bills, cheques, vendor payments, customer receipts, and deposits — newest first, each with its date and amount. Money coming in is marked with a green inbound arrow; money going out with an outbound arrow and a minus sign. Select any row to open the document it came from.') }}
        </flux:text>

        <flux:heading size="lg" class="mt-8">{{ __('Setup helpers') }}</flux:heading>
        <flux:text>
            {{ __('A brand-new company shows a couple of one-time helpers above the cards. If you started a QuickBooks import and did not finish, a “Finish setting up your company” banner offers to continue it — or you can dismiss it for good and resume later from the Import from QuickBooks page in company settings. A short list of getting-started tips also appears and hides itself once you have worked through them.') }}
        </flux:text>

        <flux:heading size="lg" class="mt-8">{{ __('The company switcher') }}</flux:heading>
        <flux:text>
            {{ __('The company switcher in the header shows each company’s logo alongside its name and uses its branding colors, so it is obvious at a glance which set of books you are in — handy when you keep Demo Company Inc. open alongside a real client file. Tidewater, the app’s teal theme, is the default look behind it.') }}
        </flux:text>

        <flux:heading size="lg" class="mt-8">{{ __('Tips') }}</flux:heading>
        <flux:text>
            {{ __('Use the global search in the sidebar to jump straight to any customer, vendor, invoice, or bill. The dashboard itself is read-only — every action you trigger from it lands in the matching feature page, so you can pick up exactly where the workflow expects you.') }}
        </flux:text>
    </x-pages::docs.layout>
</section>
