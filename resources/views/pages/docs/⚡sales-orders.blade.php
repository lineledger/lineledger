<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Sales orders')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Sales orders')"
        :subheading="__('Track a confirmed order, then bill it by generating invoices over time.')"
    >
        <flux:text>
            {{ __('A sales order records a customer\'s commitment to buy before you have delivered or billed it. It is a plan, not a sale: you fulfill it by generating invoices against it as you ship, and those invoices are what actually post to your books. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        <flux:text>
            {{ __('Open Sales → Sales orders from the sidebar to see the list. Each row shows the order number, the customer, the expected date, the total, and the live status.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/sales-orders/list.png') }}"
            alt="{{ __('The Sales orders list showing one Open order for Acme Studios') }}"
            caption="{{ __('The Sales orders list. Use the search box or the status filter to narrow it down.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('Sales orders are an optional module') }}">
            {{ __('Sales orders are turned on by default. If your business does not need them, open Settings → Organizations, find the Features section, and turn off the Sales orders switch — the Sales orders item then disappears from the sidebar. Turn it back on any time without losing existing orders.') }}
        </x-docs.callout>

        <x-docs.callout type="note" heading="{{ __('Sales orders never touch your books') }}">
            {{ __('A sales order is non-posting: creating or editing one does not create a journal entry, a receivable, or any inventory movement. Your books only change when you fulfill the order by posting the resulting invoice.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Create a sales order ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Create a sales order') }}</flux:heading>
        <flux:text>
            {{ __('Set up the order with everything the customer committed to. You can create one from scratch, or by converting an accepted estimate.') }}
        </flux:text>

        <p><strong>{{ __('To create a sales order:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Sales → Sales orders, then select New sales order in the top-right corner.') }}</li>
            <li>{{ __('Choose the Customer. The Order # and Date fill in automatically — adjust them if you need to, and set an Expected date for delivery.') }}</li>
            <li>{{ __('Optionally fill in Terms, a Sales rep, a Customer PO #, and the shipping fields: Ship date, Ship via, FOB, and Tracking #.') }}</li>
            <li>{{ __('Add a Memo for yourself or a Customer message that prints on the order.') }}</li>
            <li>{{ __('On each line, pick an Item or an Account, type a Description, and enter the Qty and Unit price. Add a Tax code, Class, or Location if you use them.') }}</li>
            <li>{{ __('Select Add line for more than one thing on the order, then select Save sales order.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/sales-orders/create.png') }}"
            alt="{{ __('The New sales order form with customer, dates, shipping fields, and a line-item grid') }}"
            caption="{{ __('The New sales order form. The quantities you enter here become the amounts you fulfill against later.') }}"
        />

        <x-docs.callout type="tip" heading="{{ __('Already sent an estimate?') }}">
            {{ __('When a customer accepts a quote, open that estimate, open its Actions menu, and choose Convert to sales order. The app copies the customer, lines, and amounts straight into a new order and marks the estimate Converted. Converting is one-way and final: an estimate becomes either an invoice or a sales order, not both.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Fulfill the order ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Fulfill the order') }}</flux:heading>
        <flux:text>
            {{ __('Fulfilling means turning part or all of the order into a real invoice. This is the step that records the sale and, for inventory items, moves the stock — fulfill the whole order at once, or invoice it in pieces as you ship.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/sales-orders/show.png') }}"
            alt="{{ __('A sales order showing Ordered, Invoiced, and Backordered quantities per line, with a Create invoice button') }}"
            caption="{{ __('A sales order tracks Ordered, Invoiced, and Backordered quantities on every line. Create invoice generates the next Draft invoice.') }}"
        />

        <p><strong>{{ __('To generate an invoice from a sales order:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the sales order you are shipping against.') }}</li>
            <li>{{ __('Select Create invoice in the top-right corner. A panel titled “Create invoice for these quantities” opens, listing each line with its Ordered, Invoiced, and Backordered counts.') }}</li>
            <li>{{ __('In the Invoice now column, adjust each quantity. It is prefilled with the amount still outstanding, so leave it to bill everything owed or lower it to bill only what you are delivering now.') }}</li>
            <li>{{ __('Select Create draft invoice. The app builds a single Draft invoice linked back to the order and its lines, then opens it for you to review.') }}</li>
            <li>{{ __('Post the invoice when you are ready.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/sales-orders/fulfill.png') }}"
            alt="{{ __('The Create invoice panel listing each order line with Ordered, Invoiced, Backordered, and an editable Invoice now quantity') }}"
            caption="{{ __('The “Create invoice for these quantities” panel. Each Invoice now box defaults to the outstanding quantity — trim it to bill only part of the order.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('What posting the invoice does') }}">
            {{ __('No posting or inventory movement happens at fulfillment — it all happens when you post the resulting invoice, which debits Accounts Receivable, credits revenue, and reduces stock for any inventory items. Voiding an invoice automatically drops it back out of the order\'s fulfilled total, so the backorder quantity stays accurate.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Print and track ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Print and track the order') }}</flux:heading>
        <flux:text>
            {{ __('The sales order page keeps a running record of how the order is being filled. Its Actions menu, in the top-right corner, gives you everything else you need.') }}
        </flux:text>

        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Print — opens a print-ready PDF of the order in a new tab to send or file.') }}</li>
            <li>{{ __('Edit — change the order; available only while it is still Open and nothing has been invoiced.') }}</li>
            <li>{{ __('Cancel order — closes the order out for good (see the warning below).') }}</li>
        </ul>

        <flux:text>
            {{ __('Below the line table, the Invoices list shows every invoice generated from the order — its number, date, total, and status — so you can jump straight to anything you have already billed.') }}
        </flux:text>

        {{-- ───────────────────────── Statuses ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Statuses and backorder') }}</flux:heading>
        <flux:text>
            {{ __('A sales order\'s status is derived live from its invoices, so it always reflects what has actually been billed.') }}
        </flux:text>

        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Open — nothing has been invoiced yet.') }}</li>
            <li>{{ __('Partial — some quantity is invoiced, but some is still outstanding.') }}</li>
            <li>{{ __('Closed — every line has been fully invoiced.') }}</li>
            <li>{{ __('Cancelled — the order was cancelled and is no longer being filled.') }}</li>
        </ul>

        <flux:text>
            {{ __('The backorder quantity on each line is simply ordered minus invoiced. Because editing an order rebuilds its lines, only an Open order can be edited — once anything has been invoiced, the order is locked.') }}
        </flux:text>

        <x-docs.callout type="warning">
            {{ __('Cancelling a sales order is permanent: a cancelled order stays cancelled regardless of its invoices.') }}
        </x-docs.callout>
    </x-pages::docs.layout>
</section>
