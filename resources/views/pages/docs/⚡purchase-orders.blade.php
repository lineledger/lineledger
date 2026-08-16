<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Purchase orders')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Purchase orders')"
        :subheading="__('Track what you have ordered from a vendor and receive it onto bills.')"
    >
        <flux:text>
            {{ __('A purchase order records what you have committed to buy from a vendor before the bill arrives. It is the purchasing mirror of a sales order: it never posts to the ledger on its own. You fulfill a purchase order by generating bills against it, and those bills are what actually post and receive stock. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        <x-docs.callout type="note">
            {{ __('Purchase orders are an optional module, turned on by default. If you do not see Purchase orders under Purchases in the sidebar, open Settings → Organizations, find the Features section, and switch on “Purchase orders.”') }}
        </x-docs.callout>

        <flux:text>
            {{ __('Open Purchase orders from the sidebar to see the list. Each row shows the order date, PO number, vendor, expected date, total, and a status that updates as you bill against it.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/purchase-orders/list.png') }}"
            alt="{{ __('The Purchase Orders list showing one open order to Office Supply Co.') }}"
            caption="{{ __('The Purchase Orders list. Filter by status with the dropdown, or search by PO number or vendor name.') }}"
        />

        {{-- ───────────────────────── Create a PO ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Create a purchase order') }}</flux:heading>
        <flux:text>
            {{ __('Raise a purchase order when you place an order with a vendor and want to track it until the goods and the bill arrive.') }}
        </flux:text>

        <p><strong>{{ __('To create a purchase order:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Purchase orders from the sidebar, then select New purchase order.') }}</li>
            <li>{{ __('Choose the Vendor. Start typing to pick an existing vendor, or type a new name and add them on the spot. The PO number and date fill in automatically — adjust them if you need to.') }}</li>
            <li>{{ __('Set an Expected date and choose Terms if you want them tracked.') }}</li>
            <li>{{ __('Add a Ship to address. Use Memo for an internal note, and Vendor message for text that prints on the purchase order you send the supplier.') }}</li>
            <li>{{ __('On each line, pick an Item or Account, type a Description, and enter the Qty, Unit price, an optional discount, and a Tax code. Select Add line for more items.') }}</li>
            <li>{{ __('Select Save purchase order.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/purchase-orders/create.png') }}"
            alt="{{ __('The New purchase order form showing vendor, dates, ship-to, and a line-item grid') }}"
            caption="{{ __('The New purchase order form. The vendor message prints on the PO you send the supplier; the memo stays internal.') }}"
        />

        <x-docs.callout type="note">
            {{ __('Saving a purchase order writes only the order itself — nothing posts to your books and no stock moves. A PO is a record of intent until you bill against it.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Receive against it ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Receive against a purchase order') }}</flux:heading>
        <flux:text>
            {{ __('When the goods and the vendor’s bill arrive, you receive against the order by generating a bill — for the full order or just the part that showed up.') }}
        </flux:text>

        <p><strong>{{ __('To receive against a purchase order:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the purchase order you want to receive.') }}</li>
            <li>{{ __('Select Create bill in the top-right corner. A panel opens showing each line with its Ordered, Billed, and Backordered quantities.') }}</li>
            <li>{{ __('In the Bill now column — prefilled with the quantity still outstanding — adjust each line to match what actually arrived. You cannot bill more than the outstanding quantity.') }}</li>
            <li>{{ __('Select Create draft bill. The app builds a draft bill and opens it so you can review it.') }}</li>
            <li>{{ __('Post the draft bill from the bill form to record the purchase and receive any stock.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/purchase-orders/show.png') }}"
            alt="{{ __('A purchase order showing Ordered, Billed, and Backordered quantities and the Create bill button') }}"
            caption="{{ __('A purchase order. The Ordered, Billed, and Backordered columns track how much of each line is still outstanding.') }}"
        />

        <x-docs.figure
            src="{{ asset('docs/screenshots/purchase-orders/receive-panel.png') }}"
            alt="{{ __('The Create bill panel listing each line with Ordered, Billed, Backordered, and an editable Bill now quantity') }}"
            caption="{{ __('The Create bill panel. Each Bill now field starts at the outstanding quantity — trim it to receive a partial shipment.') }}"
        />

        <x-docs.callout type="note">
            {{ __('The bill it generates is linked back to the order and to the specific order lines, so the order always knows how much has been billed. No posting or stock movement happens at the order — it all happens when you post the resulting bill, at which point inventory-tracked lines receive stock at the cost on the bill. Voiding a bill drops it back out of the order’s billed total.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('Every bill raised from the order is listed in a Bills table on the purchase order, each linking to the bill and showing its current status — so you can see the full receiving history at a glance.') }}
        </flux:text>

        {{-- ───────────────────────── Print, edit, cancel ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Print, edit, or cancel an order') }}</flux:heading>
        <flux:text>
            {{ __('The Actions menu on a purchase order gathers everything else you can do with it:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Print opens a clean, printable copy of the purchase order in a new tab — this is the version you send the vendor, including your vendor message.') }}</li>
            <li>{{ __('Edit reopens the form. It is available only while the order is Open (see Statuses below).') }}</li>
            <li>{{ __('Cancel order stops any further billing. It is offered only while the order is Open or Partial, and asks you to confirm.') }}</li>
        </ul>

        {{-- ───────────────────────── Statuses ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Statuses') }}</flux:heading>
        <flux:text>
            {{ __('A purchase order’s status is derived live from its (non-void) bills:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Open — nothing has been billed yet.') }}</li>
            <li>{{ __('Partial — some quantity has been billed, but lines are still outstanding.') }}</li>
            <li>{{ __('Closed — every line is fully billed.') }}</li>
            <li>{{ __('Cancelled — you stopped the order with Cancel order; no further billing is allowed.') }}</li>
        </ul>

        <x-docs.callout type="warning">
            {{ __('Because editing an order rebuilds its lines, only an Open order can be edited — once anything has been billed, the order is locked. Cancelling an order is permanent.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Reports ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reports tied to this area') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Open Purchase Orders — orders not yet fully received, so you know what is still on the way.') }}</li>
            <li>{{ __('Purchases by Vendor — spend per vendor over a period, net of vendor credits.') }}</li>
            <li>{{ __('Purchases by Item — spend and quantity purchased per item over a period.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
