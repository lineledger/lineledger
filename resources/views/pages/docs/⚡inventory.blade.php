<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Inventory')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Inventory')"
        :subheading="__('Track quantity on hand and value for the products you stock, and keep them right with adjustments.')"
    >
        <flux:text>
            {{ __('Inventory tracking turns an item into something the app counts and values for you. A tracked item carries a quantity on hand and a running value, and every time you buy or sell it the numbers move on their own — buying on a bill adds stock at the cost you paid, selling on an invoice removes stock and records its cost. The examples below use our sample business, Demo Company Inc., and its tracked item, the Branded Mug.') }}
        </flux:text>

        <flux:text>
            {{ __('Open Inventory → Stock on hand from the sidebar to see the list. Each row shows an item’s quantity on hand, its average unit cost, the asset value of that stock, and its reorder point. The header totals the value of all inventory — the same figure that sits in your inventory asset account on the balance sheet. Manage items (top-right) jumps to the item list where you turn tracking on; Adjustments opens the stock-adjustment log.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/inventory/items.png') }}"
            alt="{{ __('The Inventory list showing the Branded Mug with 50 on hand at 9.00 and an asset value of 450.00') }}"
            caption="{{ __('The Inventory list. Search by name or SKU, or flip on “Low stock only” to see items that have fallen below their reorder point.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('Before you start') }}">
            {{ __('A tracked item holds its value in an inventory-asset account and books its cost to a cost-of-goods-sold account when you sell. On the item form both accounts are pre-filled from the company defaults you set under Settings → Inventory, so set those defaults — and your costing method — before you start trading. You can still override either account on an individual item.') }}
        </x-docs.callout>

        <x-docs.callout type="note">
            {{ __('The account selectors under Settings → Inventory (and on the item form) list only active accounts. If a default Inventory asset or COGS account does not appear, open Lists → Chart of accounts and make sure the account is active.') }}
        </x-docs.callout>

        {{-- ─────────────────── Turn on tracking for an item ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Turn on tracking and set an opening balance') }}</flux:heading>
        <flux:text>
            {{ __('You make an item track stock by setting its Type to Inventory under Lists → Items. Inventory is the only item type that counts quantity on hand and books cost-of-goods-sold — Service, Non-inventory, and Bundle items just sell through. Choosing Inventory reveals an inventory section on the item form, and that is also where you record an opening balance — the stock you already hold the day you start using the app — so you do not need a historical bill to seed it.') }}
        </flux:text>

        <p><strong>{{ __('To turn on tracking and record an opening balance:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Lists → Items and open the item, or select New item.') }}</li>
            <li>{{ __('Set the item’s Type to Inventory. The form reveals an inventory section.') }}</li>
            <li>{{ __('Pick the Inventory asset account and the COGS account. Both are pre-filled from the company defaults under Settings → Inventory — change them only if this item should use different accounts.') }}</li>
            <li>{{ __('Set a Reorder point if you want a low-stock warning on the inventory list.') }}</li>
            <li>{{ __('To seed stock you already hold, enter an Opening quantity and an Opening unit cost. For the Branded Mug, that is 50 at 9.00. These fields appear only the first time you turn on tracking.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/inventory/item-form-inventory.png') }}"
            alt="{{ __('The item form with Type set to Inventory, showing the inventory asset account, COGS account, reorder point, and opening quantity and cost fields') }}"
            caption="{{ __('Set Type to Inventory and the item form reveals the inventory section: asset account, COGS account, reorder point, and an opening quantity and cost.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('What an opening balance does to your books') }}">
            {{ __('Entering an opening quantity posts a one-time opening-balance stock adjustment: it debits the inventory asset account for the stock’s value and credits Opening Balance Equity — setting up your starting position without touching revenue or expense. (Receiving the same item later on a vendor bill credits accounts payable instead, because you actually owe the supplier.)') }}
        </x-docs.callout>

        <x-docs.callout type="warning">
            {{ __('The company costing method — Weighted Average or FIFO — locks the moment an item records its first movement. Pick the method you want under Settings → Inventory before you set any opening balances, because it cannot be switched once stock starts moving.') }}
        </x-docs.callout>

        {{-- ───────────────────── How stock moves on its own ───────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('How buying and selling move stock') }}</flux:heading>
        <flux:text>
            {{ __('Once an item is tracked, most movement happens without any extra steps. Receiving the item on a vendor bill adds quantity at the cost you paid and raises the inventory asset account. Selling it on a customer invoice removes quantity and books its cost to cost-of-goods-sold, using the company costing method to decide which cost flows out. Weighted Average keeps a single running unit cost; FIFO tracks discrete cost layers and releases the oldest first. Voiding the bill or invoice reverses the movement.') }}
        </flux:text>

        <x-docs.callout type="note">
            {{ __('Posting a bill line for a tracked item debits inventory and credits accounts payable. Posting an invoice line for a tracked item credits inventory and debits cost-of-goods-sold for the cost of the units sold — so your profit on the sale is recorded the moment you sell.') }}
        </x-docs.callout>

        {{-- ──────────────────── Tracking by class and location ──────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Tracking by class and location') }}</flux:heading>
        <flux:text>
            {{ __('Turn on Classes or Locations once and every inventory transaction line accepts a Class and a Location tag — bills, invoices, credit memos, stock adjustments, and journal entries. The Inventory area carries the tags through, so you can slice stock movements by department, project, warehouse, or storefront. For Demo Company Inc., that might mean tagging Branded Mug receipts with the Main warehouse Location and online sales with a Web class.') }}
        </flux:text>

        <p><strong>{{ __('To enable class and location tracking:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Organizations.') }}</li>
            <li>{{ __('Turn on Classes, Locations, or both.') }}</li>
            <li>{{ __('Select Save. New transaction lines now show a Class and a Location selector.') }}</li>
            <li>{{ __('Open Lists → Classes and Lists → Locations to create the values you want to choose from.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/inventory/class-location-line.png') }}"
            alt="{{ __('A bill line for the Branded Mug with Class and Location selectors visible on the row') }}"
            caption="{{ __('A bill line tagged with a Class and a Location. The same selectors appear on invoices, credit memos, and stock adjustments once the features are on.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('Tag stock adjustments too — a write-off in the Main warehouse stays separate from a write-off at the Pop-up storefront, so your by-location reports keep telling the truth.') }}
        </x-docs.callout>

        {{-- ──────────────────────── Stock adjustments ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Record a stock adjustment') }}</flux:heading>
        <flux:text>
            {{ __('Use a stock adjustment when quantity changes for a reason that is not a sale or a purchase — an opening balance, shrinkage, damage, a recount, or a write-off. Each adjustment is dated, has one reason, and lists one or more items with a signed quantity change.') }}
        </flux:text>

        <p><strong>{{ __('To record a stock adjustment:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Inventory → Adjustments — or select Adjustments from the inventory list — then New adjustment.') }}</li>
            <li>{{ __('Choose a Reason: recount, shrinkage, damage, opening balance, write-off, or other. Set the Date, and add Notes to explain the change if you like.') }}</li>
            <li>{{ __('Add a line for each item: pick the item and enter the quantity change. A positive number adds stock; a negative number removes it.') }}</li>
            <li>{{ __('When you are adding stock, enter the Unit cost you are bringing it in at. For removals you can leave the cost blank — it is taken from your costing method automatically.') }}</li>
            <li>{{ __('If Classes or Locations are on, tag each line with a Class and a Location.') }}</li>
            <li>{{ __('Select Post adjustment. The adjustment is saved and posted in one step.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/inventory/adjustments.png') }}"
            alt="{{ __('The Stock adjustments list showing an Opening balance adjustment ADJ-000001 with a Posted status') }}"
            caption="{{ __('The Stock adjustments list. Each entry shows its number, date, reason, line count, and status — Posted, Draft, or Voided.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('What an adjustment does to your books') }}">
            {{ __('Posting an adjustment writes one balanced journal entry. Adding stock debits the inventory asset account; removing stock credits it. The offsetting side depends on the reason: an opening balance posts to Opening Balance Equity, while every other reason — recount, shrinkage, damage, write-off, or other — posts to the item’s cost-of-goods-sold (or adjustment) account. Void an adjustment to reverse both the journal entry and the stock it moved.') }}
        </x-docs.callout>

        {{-- ──────────────────────── Item history ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Review an item’s stock history') }}</flux:heading>
        <flux:text>
            {{ __('Open any item from the inventory list to see its full movement history, newest first. Each row shows the date, the source of the movement — an invoice, a bill, or a manual adjustment — the signed quantity, the unit cost, and the resulting value. A voided movement and its reversal are shown dimmed and tagged Reversal. This is the audit trail behind the item’s current quantity and average cost.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/inventory/item-show.png') }}"
            alt="{{ __('The Branded Mug detail page showing 50 on hand at 9.00/unit and its opening-balance movement') }}"
            caption="{{ __('The Branded Mug’s history. The opening-balance count of 50 at 9.00 came in through stock adjustment #1.') }}"
        />

        {{-- ──────────────────────── Where to go next ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Related areas') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Settings → Inventory — set the company costing method and the default inventory-asset and cost-of-goods-sold accounts.') }}</li>
            <li>{{ __('Lists → Items — set an item’s Type to Inventory and record its opening balance.') }}</li>
            <li>{{ __('Reports → Inventory stock status — quantity on hand and reorder status for every tracked item.') }}</li>
            <li>{{ __('Reports → Inventory valuation — the value of your stock by item, reconciled to the inventory asset account.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
