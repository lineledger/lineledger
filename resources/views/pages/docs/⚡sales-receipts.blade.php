<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Sales receipts')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Sales receipts')"
        :subheading="__('Record a paid-on-the-spot sale — revenue, tax, and the money all booked in one step.')"
    >
        <flux:text>
            {{ __('A sales receipt is for a sale the customer pays in full right away — a walk-in purchase, a point-of-sale transaction, a deposit-and-done job. One posted document records the revenue, breaks out the sales tax, and lands the money in your bank, all at once. There is no "you owe me" stage, because nothing is owed: the customer already paid. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        <flux:text>
            {{ __('Open Sales → Sales receipts from the sidebar to see the list. Each row shows the date, the receipt number, the customer (or "Cash sale" when there is none), the account the money was deposited to, the total, and the status.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/sales-receipts/list.png') }}"
            alt="{{ __('The Sales receipts list showing receipt numbers, customers, deposit accounts, totals, and status badges') }}"
            caption="{{ __('The Sales receipts list. Search by receipt number or customer name; a blank customer shows as “Cash sale.”') }}"
        />

        {{-- ─────────────── Receipt vs. invoice + payment ─────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('How it differs from an invoice') }}</flux:heading>
        <flux:text>
            {{ __('An invoice is for a sale on credit: it records what the customer owes you (Accounts Receivable), and later — when they pay — you record a separate receipt to clear that balance and bank the cash. That is two documents for one sale that happened to be paid later.') }}
        </flux:text>
        <flux:text>
            {{ __('A sales receipt collapses both steps into one. Because the money arrives at the same moment as the sale, there is no Accounts Receivable to track and no payment to chase. That is why a sales receipt has only three states — Draft, Posted, and Void — with no partial-paid or overdue lifecycle. Reach for an invoice when you will be paid later; reach for a sales receipt when you are paid now.') }}
        </flux:text>

        <x-docs.callout type="tip" heading="{{ __('Which one should I use?') }}">
            {{ __('Paid at the counter, by card, or cash on delivery → sales receipt. Billing a customer who will pay on terms → invoice, then a receipt when the money comes in.') }}
        </x-docs.callout>

        {{-- ─────────────────── Create a sales receipt ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Create a sales receipt') }}</flux:heading>
        <flux:text>
            {{ __('Creating a sales receipt looks a lot like writing an invoice, with one extra field — the account the money was deposited to — and no due date, because nothing is due.') }}
        </flux:text>

        <p><strong>{{ __('To create a sales receipt:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Sales → Sales receipts, then select New sales receipt.') }}</li>
            <li>{{ __('Pick the Customer if you want the sale tied to one — or leave it blank for a cash sale or a walk-in you do not track by name. The receipt number and date fill in automatically; adjust them if you need to.') }}</li>
            <li>{{ __('In Deposit to, choose where the money landed: a bank account, or Undeposited Funds if you are grouping it for a later bank deposit.') }}</li>
            <li>{{ __('Optionally record the Payment method (cash, card, cheque) and a Reference such as a cheque number or card authorization code.') }}</li>
            <li>{{ __('On each line, pick an Item or an Account, type a Description, and enter the Quantity and Unit price. The line amount calculates as you type.') }}</li>
            <li>{{ __('Add a per-line discount percentage and a Tax code if the sale is taxable — you can apply up to two tax codes per line, for example GST and PST.') }}</li>
            <li>{{ __('Select Add line for more than one item, then Save & post to finalize — or Save draft to finish later.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/sales-receipts/create.png') }}"
            alt="{{ __('The New sales receipt form showing the optional customer, deposit-to account, payment method, and a line-item grid with totals') }}"
            caption="{{ __('The New sales receipt form. The Deposit to account is what makes this a pay-now sale — there is no Accounts Receivable step.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('The Deposit to account') }}">
            {{ __('Deposit to is the heart of a sales receipt. Choose a bank account to send the money straight there, or choose Undeposited Funds to hold it until you make a physical bank run — then batch it with other takings on a Bank deposit, exactly like a customer receipt. Demo Company Inc. defaults new receipts to Undeposited Funds.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('Picking an Item fills in its account, description, default price, and tax code for you. If the item is a bundle, the line expands into one prefilled line per component. A draft can still be changed or deleted; posting locks the amounts into your books and assigns the receipt its place on your reports.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('What posting does to your books') }}">
            {{ __('Posting a sales receipt debits your Deposit to account (a bank or Undeposited Funds) for the total, credits your revenue accounts for the subtotal, and credits each tax agency’s payable account for the tax collected — all in one balanced journal entry, with no Accounts Receivable leg. If a line uses an inventory-tracked item, posting also reduces that item’s quantity on hand and books its cost to cost-of-goods-sold. Because the sale and the cash settle at the same instant, a foreign-currency receipt converts every line at one locked rate, so it never produces an exchange gain or loss.') }}
        </x-docs.callout>

        {{-- ─────────────────── View, print, void ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('View, print, and void') }}</flux:heading>
        <flux:text>
            {{ __('Open any receipt from the list to see its lines, a per-tax-code breakdown, the totals, and — once posted — a link to the journal entry it created.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/sales-receipts/show.png') }}"
            alt="{{ __('A posted sales receipt showing its Posted badge, the linked GL entry, the line items, the tax breakdown, and the total') }}"
            caption="{{ __('A posted sales receipt. The GL entry badge opens the journal entry; the Actions menu prints, edits, or voids it.') }}"
        />

        <p><strong>{{ __('From the Actions menu you can:') }}</strong></p>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Print — open a printable, PDF-ready copy to hand or email to the customer.') }}</li>
            <li>{{ __('Edit — change a draft freely, or update a posted receipt; saving a posted one reposts it in place, adjusting the same journal entry.') }}</li>
            <li>{{ __('Void — for a posted receipt, reverse it: the app writes a reversing journal entry, returns any issued stock to inventory, and keeps the voided receipt on file.') }}</li>
            <li>{{ __('Delete draft — remove a receipt that was never posted, so it leaves no trace in your books.') }}</li>
        </ul>

        <x-docs.callout type="warning">
            {{ __('A posted sales receipt should never simply be deleted — that would leave a gap in your numbered records. To cancel one, void it instead: the ledger entry and any stock movement are reversed, and the voided receipt stays on file for your audit trail.') }}
        </x-docs.callout>

        {{-- ──────────────────────── Related reports ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reports tied to this area') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Profit and loss — sales-receipt revenue appears alongside invoiced sales.') }}</li>
            <li>{{ __('Sales tax — tax you collected on sales receipts, ready for filing.') }}</li>
            <li>{{ __('Sales — totals by item and customer across invoices and sales receipts.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
