<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Multi-currency')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Multi-currency')"
        :subheading="__('Invoice and pay customers and vendors in foreign currencies while your books stay in one.')"
    >
        <flux:text>
            {{ __('Multi-currency lets you trade with customers and vendors in other currencies while keeping a single set of books. Your company has one home currency — the currency your financial statements are always expressed in — and you enable the foreign currencies you deal in alongside it. Our sample business, Demo Company Inc., keeps its books in CAD with USD enabled and a US customer, Stateside Imports (USD), to bill in dollars.') }}
        </flux:text>

        <flux:heading size="lg" class="mt-8">{{ __('How multi-currency works') }}</flux:heading>
        <flux:text>
            {{ __('Every journal entry is posted in home-currency amounts, so the ledger and every report always balance in one currency. When a transaction is in a foreign currency, the app also stores the foreign amount and the exchange rate it used, as a memo alongside the home figures. Reports read the home amounts; the foreign detail is there for reference and for revaluation.') }}
        </flux:text>

        <flux:text>
            {{ __('Each customer or vendor transacts in one declared currency. All of their invoices, bills, and payments use it, and the app keeps separate receivable and payable control accounts per currency so foreign balances stay visible. Once a contact has activity, its currency is locked.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('Two things you cannot change later') }}">
            {{ __('Your home currency is fixed when the company is created and cannot be changed. And once a customer or vendor has any activity, the currency on that contact is locked. Set both up correctly from the start.') }}
        </x-docs.callout>

        {{-- ──────────────────────── Enable a currency ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Enable a currency') }}</flux:heading>
        <flux:text>
            {{ __('Settings → Currencies is where you enable the currencies you trade in. The page shows your fixed home currency and lists each foreign currency you have turned on, along with the receivable and payable control accounts it uses.') }}
        </flux:text>

        <p><strong>{{ __('To enable a foreign currency:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings and select Currencies.') }}</li>
            <li>{{ __('Under Add a currency, choose the currency from the list.') }}</li>
            <li>{{ __('Select Enable. The app creates its AR and AP control accounts automatically — for example, "Accounts Receivable (USD)" and "Accounts Payable (USD)".') }}</li>
        </ol>
        <flux:text>
            {{ __('Once enabled, a currency appears under Foreign currencies with an Active status. Select Deactivate to stop offering it on new contacts; existing history is unaffected. The currency list covers the major two-decimal currencies — USD, EUR, GBP, AUD, and the like.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('Two accounts appear the first time') }}">
            {{ __('The very first time you enable any foreign currency, the app also creates two profit-and-loss accounts: Exchange Gain or Loss (for realized differences when a foreign invoice or bill settles) and Unrealized Gain or Loss (for period-end revaluation). Single-currency companies never see this clutter, and you will not have to create either account by hand.') }}
        </x-docs.callout>

        <x-docs.figure
            src="{{ asset('docs/screenshots/multi-currency/currencies.png') }}"
            alt="{{ __('The Settings → Currencies page showing CAD as the home currency, USD enabled with its AR and AP control accounts, and sections for exchange rates and period-end revaluation') }}"
            caption="{{ __('Settings → Currencies. Demo Company Inc. keeps its home currency in CAD with USD enabled and active. The Exchange rates and Period-end revaluation sections appear once a foreign currency is on.') }}"
        />

        {{-- ──────────────────────── Exchange rates ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Set an exchange rate') }}</flux:heading>
        <flux:text>
            {{ __('Rates are expressed as home-currency units per one foreign unit — so a USD rate of 1.35 means one US dollar is worth 1.35 Canadian dollars. Daily rates are fetched automatically each morning, but you can enter a rate by hand for a specific date, and your manual rate always wins over the fetched one.') }}
        </flux:text>

        <flux:text>
            {{ __('When the app needs a rate for a date, it looks in this order: your manual override for that date (or the most recent one before it), then the daily fetched rate, then a one-time fetch for that exact date. Fetched rates come from a free European Central Bank feed (Frankfurter), which publishes on weekdays only and serves the last published rate on weekends and holidays.') }}
        </flux:text>

        <p><strong>{{ __('To enter a rate by hand:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('On Settings → Currencies, find the Exchange rates section.') }}</li>
            <li>{{ __('Choose the Currency, enter the Rate, and pick the Date it applies to.') }}</li>
            <li>{{ __('Select Save rate.') }}</li>
        </ol>
        <flux:text>
            {{ __('Each rate you save (and each one the app fetches) shows up in the recent-rates table below the form, tagged Manual or Api so you can tell which is which. When you post a foreign invoice, bill, or payment, the rate in force on its date is locked onto that document — it never moves afterward, even if the rate changes the next day.') }}
        </flux:text>

        <x-docs.callout type="tip" heading="{{ __('No rate on file?') }}">
            {{ __('If no manual rate exists and the provider has no published rate for a date — common for a future date or a market holiday — the app stops and asks you to enter a manual rate for that date before it can continue. This is most likely to come up at period-end revaluation, where you may need to key in the closing rate yourself.') }}
        </x-docs.callout>

        {{-- ──────────────────── Foreign invoices and bills ──────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Foreign invoices and bills') }}</flux:heading>
        <flux:text>
            {{ __('A document takes its currency from the customer or vendor, so when you invoice Stateside Imports or enter a bill from a US vendor, the amounts you type are in their currency — there is no currency picker on the form. Behind the scenes, posting converts the total to your home currency at the rate in force on the document date, locks that rate onto the document, and posts the journal entry in home amounts, keeping the foreign figures as a memo.') }}
        </flux:text>

        <x-docs.callout type="note">
            {{ __('A foreign invoice debits the receivable control account for that currency (in home amounts) and credits revenue, with sales tax broken out; a foreign bill credits the matching payable control account and debits the expense. Because each currency has its own control accounts, you can always see what is owed to and by you in each currency.') }}
        </x-docs.callout>

        <x-docs.callout type="note" heading="{{ __('Why a foreign entry may carry a one-cent adjustment') }}">
            {{ __('Each line of a foreign document is converted to home cents on its own, so the converted lines can add up to a cent more or less than the converted document total. The app nudges the largest line by that single cent so the entry balances exactly — the general ledger only accepts entries whose debits and credits match to the penny. It is a rounding plug, not an error, and it never exceeds one cent.') }}
        </x-docs.callout>

        <x-docs.callout type="tip">
            {{ __('Every amount field on a foreign invoice or bill doubles as a quick calculator. Start typing math, for example 1050+52.50, and a tape pops up showing each operation. Press Enter to commit the final value into the field.') }}
        </x-docs.callout>

        {{-- ──────────────────────── Realized gain/loss ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Realized gains and losses') }}</flux:heading>
        <flux:text>
            {{ __('Exchange rates move between the day you book a foreign invoice or bill and the day it is paid. When you settle it at a different rate than it was booked at, the difference in home-currency terms is a realized exchange gain or loss, and the app posts it for you to the Exchange Gain or Loss account.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('How the gain or loss arises') }}">
            {{ __('Say you invoice Stateside Imports 1,000 USD when the rate is 1.35 — you book 1,350 CAD of receivable. They pay 1,000 USD when the rate is 1.30, worth 1,300 CAD. The app applies the payment, posts the 50 CAD shortfall to Exchange Gain or Loss as a loss, and clears the receivable to exactly zero. A favourable move produces a gain instead. Paying a foreign vendor bill works the same way in reverse.') }}
        </x-docs.callout>

        {{-- ──────────────────────── Revaluation ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Period-end revaluation') }}</flux:heading>
        <flux:text>
            {{ __('Balances still open at period end — foreign bank and credit-card accounts, plus foreign AR and AP — are worth more or less in home currency as rates move, even though no payment has happened. Revaluation, also called the Home Currency Adjustment, restates those open balances at the closing rate so your period-end statements are accurate.') }}
        </flux:text>

        <p><strong>{{ __('To run a revaluation:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('On Settings → Currencies, find the Period-end revaluation section.') }}</li>
            <li>{{ __('Set the As of date — the end of the period you are closing.') }}</li>
            <li>{{ __('Select Run revaluation.') }}</li>
        </ol>

        <x-docs.callout type="note">
            {{ __('Revaluation restates open foreign balances at the closing rate and posts the difference as an unrealized gain or loss to the Unrealized Gain or Loss account. The adjustment is automatically reversed the next day so it never compounds into the new period or double-counts with the realized gain or loss booked when the balance eventually settles. A given date can only be revalued once, and each run lists the As-of date, the rates it used, and the entry it created in the history table below the form.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('If no closing rate is on file for the As of date, the app asks you to enter a manual rate for that date first — set it under Exchange rates, then run the revaluation again.') }}
        </flux:text>

        {{-- ──────────────────────── Related areas ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Related areas') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Settings → Currencies — enable currencies, manage rates, and run revaluation.') }}</li>
            <li>{{ __('Customers and Vendors — set the one currency each contact transacts in.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
