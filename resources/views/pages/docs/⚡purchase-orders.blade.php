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
            {{ __('Purchase orders are an optional module, turned on by default. If you do not see Purchase orders under Purchases in the sidebar, open Settings → Organizations, select your organization, find the Features section, and switch on “Purchase orders.” Turning the module off hides the sidebar entry and the Open Purchase Orders report; it never deletes the orders themselves.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('Open Purchases → Purchase orders from the sidebar to see the list. Each row shows the Date, PO #, Vendor, Expected, Total, and a Status that updates as you bill against it. Search by PO number or vendor name, or narrow the list with the status dropdown — All, Open, Partial, Closed, or Cancelled. On a phone the same orders appear as stacked cards.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/purchase-orders/list.png') }}"
            alt="{{ __('The Purchase Orders list showing one open order to Office Supply Co. with its date, PO number, expected date, total, and Open status badge') }}"
            caption="{{ __('The Purchase Orders list. Filter by status with the dropdown, or search by PO number or vendor name.') }}"
        />

        {{-- ───────────────────────── Create a PO ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Create a purchase order') }}</flux:heading>
        <flux:text>
            {{ __('Raise a purchase order when you place an order with a vendor and want to track it until the goods and the bill arrive.') }}
        </flux:text>

        <p><strong>{{ __('To create a purchase order:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Purchases → Purchase orders from the sidebar, then select New purchase order.') }}</li>
            <li>{{ __('Choose the Vendor. Start typing to pick an existing vendor, or type a new name and select Add “…” as new vendor to create them on the spot. Picking a vendor applies their default payment terms to the order.') }}</li>
            <li>{{ __('The PO # and Date fill in automatically. You can change the number — it only has to be unique within your organization, and the form tells you if it is already taken. Click anywhere in a date field to open the calendar.') }}</li>
            <li>{{ __('Set an Expected date if you want to track when the goods should arrive, and choose Terms — the draft bill you later raise from the order inherits them.') }}</li>
            <li>{{ __('Add a Ship to address. Use Memo for an internal note, and Vendor message for text that prints on the purchase order you send the supplier.') }}</li>
            <li>{{ __('Fill in at least one line — see What goes on a line, below. Select Add line for more, or press Tab from the last field of the last row to add one without leaving the keyboard. The × at the end of a row removes it; the form always keeps at least one line.') }}</li>
            <li>{{ __('Select Save purchase order.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/purchase-orders/create.png') }}"
            alt="{{ __('The New purchase order form showing the Vendor, PO #, Date, Expected date, Terms and Ship to fields, the Memo and Vendor message boxes, and a line grid') }}"
            caption="{{ __('The New purchase order form. The vendor message prints on the PO you send the supplier; the memo stays internal.') }}"
        />

        {{-- ───────────────────────── Line grid ───────────────────────── --}}
        <flux:heading size="md" class="mt-6">{{ __('What goes on a line') }}</flux:heading>
        <flux:text>
            {{ __('Account, Qty, and Unit price are required on every line — Qty and Unit price start at 1 and 0.00 — and everything else is optional. From left to right:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Item — pick from your items list. Choosing an item fills the Account, Description, Unit price, and Tax from the item’s defaults (its expense account, or its income account if it has no expense account), so choose the item first and adjust the rest afterwards.') }}</li>
            <li>{{ __('Description — free text. It prints on the purchase order and is copied onto the bill.') }}</li>
            <li>{{ __('Account — required. The expense, asset, or liability account the purchase will be coded to when you bill it. Picking an account fills a still-blank Tax cell from the account’s default tax code.') }}</li>
            <li>{{ __('Qty and Unit price — required. The quantity ordered and the price per unit; clearing either one stops the order from saving. Unit price doubles as a calculator: type an expression such as 45*10 or 100/4 and press Enter to commit the result.') }}</li>
            <li>{{ __('Disc % — an optional percentage discount from 0 to 100 off the line. The order page and the printed PO show it as “less … disc” under the unit price.') }}</li>
            <li>{{ __('Tax — a checkbox picker that takes up to two tax codes, say GST and PST. Once two are ticked the others are greyed out. The totals under the grid list each tax on its own row with its rate, and so do the order page and the printed PO.') }}</li>
            <li>{{ __('Class and Location — appear only when those dimensions are switched on under Settings → Organizations → Features. They are saved per line and carried onto the bill, so your reports can slice the purchase by department or site.') }}</li>
        </ul>

        <x-docs.figure
            src="{{ asset('docs/screenshots/purchase-orders/line-grid.png') }}"
            alt="{{ __('The purchase order line grid with the Tax picker open and two tax codes ticked, alongside the Item, Description, Account, Qty, Unit price, Disc %, Class, Location, and Amount columns') }}"
            caption="{{ __('The line grid. The Tax picker accepts up to two codes per line, and the Class and Location columns appear once those features are on.') }}"
        />

        <x-docs.callout type="tip" heading="{{ __('How defaults fill in') }}">
            {{ __('Picking a vendor fills the Tax cell on any line that does not have one yet, from the vendor’s default tax code; picking an account does the same from the account’s default. Neither replaces a tax code you have already chosen. Picking an item is different — it replaces the line’s Account, Description, Unit price, and Tax with the item’s defaults, so make any manual changes after choosing the item.') }}
        </x-docs.callout>

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
            <li>{{ __('Select Create bill in the top-right corner — on a phone, open the Actions menu and choose Create bill instead. A “Create bill for these quantities” panel opens showing each line with its Ordered, Billed, and Backordered quantities.') }}</li>
            <li>{{ __('In the Bill now column — prefilled with the quantity still outstanding — adjust each line to match what actually arrived. Set a line to 0 to leave it out. You cannot bill more than the outstanding quantity.') }}</li>
            <li>{{ __('Select Create draft bill. The app builds a draft bill dated today, linked to the order, and opens it in the bill form so you can review it. The order counts the draft’s quantities as billed straight away, before you post anything.') }}</li>
            <li>{{ __('Review the draft, then select Post bill to record the purchase and receive any stock. Read “Saving the bill resets the order’s billed quantities,” below, first.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/purchase-orders/show.png') }}"
            alt="{{ __('A purchase order page showing the status badge, the Create bill and Actions buttons, and a lines table with Ordered, Billed, and Backordered columns') }}"
            caption="{{ __('A purchase order. The Ordered, Billed, and Backordered columns track how much of each line is still outstanding.') }}"
        />

        <x-docs.figure
            src="{{ asset('docs/screenshots/purchase-orders/receive-panel.png') }}"
            alt="{{ __('The Create bill for these quantities panel listing each line with Ordered, Billed, Backordered, and an editable Bill now quantity, with Cancel and Create draft bill buttons') }}"
            caption="{{ __('The Create bill panel. Each Bill now field starts at the outstanding quantity — trim it to receive a partial shipment.') }}"
        />

        <x-docs.callout type="warning" heading="{{ __('Check the draft bill before you post it') }}">
            {{ __('The draft bill copies each line’s item, account, description, quantity, unit price, first tax code, class, and location, plus the order’s terms and memo. It does not carry over a Disc % or a second tax code — if the vendor’s invoice shows either, add it on the bill before posting.') }}
        </x-docs.callout>

        <x-docs.callout type="warning" heading="{{ __('Saving the bill resets the order’s billed quantities') }}">
            {{ __('When you select Save draft or Post bill on a bill raised from a purchase order, the bill stays attached to the order — it is still listed in the order’s Bills table — but its lines lose their link to the order’s lines, so the order stops counting them. Billed drops back, Backordered goes up by the same amount, and the status can return to Open, which brings Create bill and Edit back. Before you create another bill from the same order, check its Bills table so you do not bill the same goods twice. Once everything on an order has arrived and been billed, you can select Cancel order so it cannot be billed again.') }}
        </x-docs.callout>

        <x-docs.callout type="note" heading="{{ __('What posting the bill does to your books') }}">
            {{ __('Nothing touches the ledger until you post the bill. Posting it debits the account on each line — or the item’s inventory asset account for an inventory-tracked item, which also receives the stock at the cost on the bill — debits the tax agency’s payable account for any recoverable tax, and credits Accounts Payable for the total.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('Every bill raised from the order is listed in a Bills table on the purchase order, showing each bill’s number, date, total, and current status, with a link to open it — so you can see the full receiving history at a glance. Paying and voiding bills works exactly as it does for any other bill; see') }}
            <a class="underline" href="{{ route('docs.vendors') }}" wire:navigate>{{ __('Vendors') }}</a>{{ __('.') }}
        </flux:text>

        {{-- ───────────────────────── Print, edit, cancel ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Print, edit, or cancel an order') }}</flux:heading>
        <flux:text>
            {{ __('The Actions menu on a purchase order gathers everything else you can do with it:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Print opens a PDF copy of the purchase order in a new tab — the version you send the vendor. It uses the logo, column choices, and footer message from Settings → Invoices, shows the Terms and Expected date, and prints your vendor message but never the memo.') }}</li>
            <li>{{ __('Edit reopens the form. It is available only while the order is Open (see Statuses below).') }}</li>
            <li>{{ __('Cancel order stops any further billing. It is offered only while the order is Open or Partial, and asks you to confirm.') }}</li>
            <li>{{ __('Create bill also lives here on a phone; on a larger screen it is the button beside the menu.') }}</li>
        </ul>

        <x-docs.callout type="note" heading="{{ __('One person edits an order at a time') }}">
            {{ __('If a teammate has the purchase order open for editing, its page shows who is editing, and Create bill and Cancel order wait until they are done. Opening the edit page yourself shows that notice instead of the form, with Try again and View purchase order — and, for Owners and Admins, Take over editing. See') }}
            <a class="underline" href="{{ route('docs.settings') }}" wire:navigate>{{ __('Edit locks under Settings') }}</a>{{ __(' for how locks are released and taken over.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Statuses ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Statuses') }}</flux:heading>
        <flux:text>
            {{ __('A purchase order’s status is worked out live from the bill lines linked to its lines. A draft bill counts as soon as Create draft bill makes it; voided and deleted bills do not count:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Open — no quantity is counted as billed.') }}</li>
            <li>{{ __('Partial — some quantity is counted as billed, but lines are still outstanding.') }}</li>
            <li>{{ __('Closed — every line is fully billed.') }}</li>
            <li>{{ __('Cancelled — you stopped the order with Cancel order; no further billing is allowed.') }}</li>
        </ul>

        <x-docs.callout type="warning">
            {{ __('Because editing an order rebuilds its lines, only an Open order can be edited; the order locks as soon as any quantity is counted as billed. An order whose bills have been saved can show Open, and be editable, again — see “Saving the bill resets the order’s billed quantities,” above. Cancelling an order is permanent.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Reports ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reports tied to this area') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Open Purchase Orders — every purchase order you have not cancelled, newest first, with its vendor, date, expected date, and total, plus a CSV export. It goes by the order’s saved status rather than the live one, so an order stays on it, however much has been billed, until you cancel the order.') }}</li>
            <li>{{ __('Purchases by Vendor — spend per vendor over a period, net of vendor credits.') }}</li>
            <li>{{ __('Purchases by Item — spend and quantity purchased per item over a period.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
