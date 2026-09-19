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
            {{ __('Inventory tracking turns an item into something the app counts and values for you. A tracked item carries a quantity on hand and a running value, and every time you buy or sell it the numbers move on their own — buying on a bill adds stock at the cost you paid, selling on an invoice or a sales receipt removes stock and records its cost. The examples below use our sample business, Demo Company Inc., and its tracked item, the Branded Mug.') }}
        </flux:text>

        <flux:text>
            {{ __('Open Inventory → Stock on hand from the sidebar to see the list. Each row shows an item’s Name, SKU, quantity On hand, Avg unit cost, the Asset value of that stock, and the Reorder at point (a dash when none is set). The Total value in the heading adds up every tracked item and should agree with the inventory asset account on your Balance Sheet. Adjustments (top-right) opens the stock-adjustment log and Manage items jumps to the Items list where you turn tracking on. Search by name or SKU, or switch on Low stock only to see just the items whose quantity has dropped below their reorder point — the same ones that carry the amber Low badge. The Inventory group is only in the sidebar while the Inventory feature is on and your role includes the Inventory section. A new organization does not always start with it on: the setup wizard’s Features step suggests switches from the industry you picked, and Inventory starts on only for Contractor / Construction, Manufacturing, Retail, and Restaurant / Food & Beverage — for General business, the default, and every other industry it starts off. Switch it on there, or later under Features on your organization’s page in Settings → Organizations.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/inventory/items.png') }}"
            alt="{{ __('The Stock on hand list showing the Branded Mug with 50 on hand at an average unit cost of 9.00 and an asset value of 450.00, with the Total value in the heading and the Adjustments and Manage items buttons') }}"
            caption="{{ __('The Stock on hand list. Search by name or SKU, or switch on “Low stock only” to see items that have fallen below their reorder point. Select a name to open its movement history.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('Before you start') }}">
            {{ __('A tracked item holds its value in an inventory asset account and books its cost to a cost-of-goods-sold (COGS) account when you sell. On the item form both accounts are pre-filled from the organization defaults under Settings → Inventory — a new organization gets defaults from its chart of accounts — so check those defaults, and choose your costing method, before you start trading. You can still override either account on an individual item.') }}
        </x-docs.callout>

        <x-docs.callout type="note">
            {{ __('The Inventory asset selector — under Settings → Inventory and on the item form — offers active accounts whose subtype is Inventory; the COGS selector offers active accounts whose subtype is Cost of Goods Sold or Expense. An account that is already chosen stays in its list even after it is deactivated. If the account you want is missing, open Accounting → Chart of Accounts and check that it is active and carries the right subtype.') }}
        </x-docs.callout>

        {{-- ─────────────────── Turn on tracking for an item ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Turn on tracking and set an opening balance') }}</flux:heading>
        <flux:text>
            {{ __('You make an item track stock by setting its Type to Inventory under Settings → Lists → Items. Inventory is the only item type that counts quantity on hand and books cost-of-goods-sold — Service, Non-inventory, Other charge, and Bundle items just sell through. Choosing Inventory reveals an inventory section on the item form, and that is also where you record an opening balance — the stock you already hold the day you start using the app — so you do not need a historical bill to seed it.') }}
        </flux:text>

        <p><strong>{{ __('To turn on tracking and record an opening balance:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Lists → Items and open the item, or select New item.') }}</li>
            <li>{{ __('Set the item’s Type to Inventory. The form reveals the inventory section.') }}</li>
            <li>{{ __('Pick the Inventory asset account and the COGS account. Both are pre-filled from the organization defaults under Settings → Inventory — change them only if this item should use different accounts.') }}</li>
            <li>{{ __('Set a Reorder point if you want a Low flag on the Stock on hand list once the quantity drops below it.') }}</li>
            <li>{{ __('To seed stock you already hold, enter an Opening quantity and an Opening unit cost. For the Branded Mug, that is 50 at 9.00. These two fields are offered only on a new item, or on an existing item the first time you switch it to Inventory — after that, use a stock adjustment.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/inventory/item-form-inventory.png') }}"
            alt="{{ __('The New item dialog with Type set to Inventory, showing the pre-filled Inventory asset account and COGS account, the Reorder point, the Opening quantity and Opening unit cost fields, and the note that saving posts DR Inventory Asset / CR Opening Balance Equity') }}"
            caption="{{ __('Set Type to Inventory and the item form reveals the inventory section: asset account, COGS account, reorder point, and an opening quantity and cost.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('What an opening balance does to your books') }}">
            {{ __('Entering an opening quantity posts a one-time opening-balance stock adjustment: it debits the inventory asset account for the stock’s value and credits Opening Balance Equity — setting up your starting position without touching revenue or expense. (Receiving the same item later on a vendor bill credits accounts payable instead, because you actually owe the supplier.)') }}
        </x-docs.callout>

        <x-docs.callout type="note" heading="{{ __('One person edits an item at a time') }}">
            {{ __('If a teammate already has the item open for editing, you see who is editing instead of the form, and Owners and Admins can take over. See') }}
            <a class="underline" href="{{ route('docs.settings') }}" wire:navigate>{{ __('Settings → Edit locks') }}</a>
            {{ __('for how a lock is held and released.') }}
        </x-docs.callout>

        <x-docs.callout type="warning">
            {{ __('The organization’s costing method — Weighted Average or FIFO — locks the moment any item records its first movement. Pick the method you want under Settings → Inventory before you set any opening balances, because it cannot be switched once stock starts moving.') }}
        </x-docs.callout>

        <flux:heading size="md" class="mt-6">{{ __('Seed many items at once') }}</flux:heading>
        <flux:text>
            {{ __('If you are carrying a whole stock list over from another system, you do not have to open every item. A single stock adjustment with the reason Opening balance can hold a line for every item, and it books exactly what the item form does: DR Inventory asset / CR Opening Balance Equity for each line’s quantity and cost.') }}
        </flux:text>

        <p><strong>{{ __('To record opening stock for many items in one adjustment:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Create the items first, each with Type set to Inventory — one at a time as above, leaving Opening quantity blank, or in bulk with Import on the Items list. (The items template’s is_inventory, inventory_asset_account_code, cogs_account_code, and reorder_point columns set up tracking but load no stock.)') }}</li>
            <li>{{ __('Open Inventory → Adjustments and select New adjustment.') }}</li>
            <li>{{ __('Set the Reason to Opening balance and the Date to the day your stock was counted — if you use the Opening balances workspace, its As of date. Add Notes such as “Opening stock count” if you like.') }}</li>
            <li>{{ __('On each line, pick an item, enter the counted quantity in Qty (±) as a positive number, and enter its Unit cost. Select Add line for the next item.') }}</li>
            <li>{{ __('Select Post adjustment.') }}</li>
        </ol>

        <x-docs.callout type="warning" heading="{{ __('Import CSV on the Opening balances workspace') }}">
            {{ __('The Opening balances workspace (Accounting → Opening balances, available to the organization’s owner) has an Inventory on hand card under Other opening data, with an Import CSV button and a three-column template — sku, qty_on_hand, and unit_cost. In this version that import cannot be completed: selecting Preview on a file with valid rows stops with an error instead of listing them, so the Import button never becomes available. Record opening stock with a stock adjustment as above instead.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('Post your opening stock before you finalize opening balances: finalizing locks the books through the As of date, and an adjustment dated on or before a locked date is refused. The rest of the workspace is described under') }}
            <a class="underline" href="{{ route('docs.opening-balances') }}" wire:navigate>{{ __('Opening balances') }}</a>{{ __('.') }}
        </flux:text>

        {{-- ───────────────────── How stock moves on its own ───────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('How buying and selling move stock') }}</flux:heading>
        <flux:text>
            {{ __('Once an item is tracked, most movement happens without any extra steps. Receiving the item on a vendor bill adds quantity at the cost you paid and raises the inventory asset account. Selling it on a customer invoice or a') }}
            <a class="underline" href="{{ route('docs.sales-receipts') }}" wire:navigate>{{ __('sales receipt') }}</a>
            {{ __('removes quantity and books its cost to cost-of-goods-sold, using the organization’s costing method to decide which cost flows out. Weighted Average keeps a single running unit cost; FIFO tracks discrete cost layers and releases the oldest first. Voiding the bill, invoice, or receipt reverses the movement.') }}
        </flux:text>

        <x-docs.callout type="note">
            {{ __('Posting a bill line for a tracked item debits inventory and credits accounts payable. Posting an invoice or sales receipt line for a tracked item credits inventory and debits cost-of-goods-sold for the cost of the units sold — so your profit on the sale is recorded the moment you sell.') }}
        </x-docs.callout>

        <x-docs.callout type="warning" heading="{{ __('You cannot sell what you do not have') }}">
            {{ __('Posting an invoice or a sales receipt checks every tracked line against the quantity on hand — lines for the same item are added together — and refuses the whole document if any item would go below zero. The form shows the error against its lines and nothing is posted, so receive the stock on a bill or a stock adjustment first, then post the sale. A stock adjustment that removes more than you hold is refused too.') }}
        </x-docs.callout>

        <x-docs.callout type="note" heading="{{ __('Returns and credits do not move stock') }}">
            {{ __('A credit memo or a vendor credit changes what is owed, not what is on the shelf. If a customer actually sends goods back, or you return goods to a supplier, record a stock adjustment for the quantity as well so the count stays right.') }}
        </x-docs.callout>

        {{-- ──────────────────── Tracking by class and location ──────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Tracking by class and location') }}</flux:heading>
        <flux:text>
            {{ __('Turn on Classes, Locations, or both, and transaction lines gain a selector for each feature you switch on — a Class selector for Classes, a Location selector for Locations. Bills, invoices, credit memos, journal entries, and stock adjustment lines all carry them; sales receipt lines do not, so a sale recorded on a sales receipt posts without a class or location. The tags travel with the journal entry each document posts, so the inventory and cost-of-goods-sold postings show up in the by-class and by-location versions of your ledger reports. For Demo Company Inc., that might mean tagging Branded Mug purchases with the Merchandise class and each invoiced sale with the Main Chapel or North Branch location. Note the limit: quantity on hand is one number per item. Stock movements carry no class or location, so the Stock on hand list and the inventory reports cannot tell you how many mugs sit at each location.') }}
        </flux:text>

        <p><strong>{{ __('To enable class and location tracking:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Organizations and open your organization with the pencil button.') }}</li>
            <li>{{ __('Under Features, switch on Classes, Locations, or both, then select Save. Transaction lines now show a selector for each one you turned on — Class for Classes, Location for Locations.') }}</li>
            <li>{{ __('Open Settings → Lists → Classes and Settings → Lists → Locations to create the values you want to choose from.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/inventory/class-location-line.png') }}"
            alt="{{ __('A bill line for the Branded Mug with the Class and Location selectors visible on the row') }}"
            caption="{{ __('A bill line tagged with a Class and a Location. The same selectors appear on invoices, credit memos, journal entries, and stock adjustment lines once the features are on — but not on sales receipts.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('Tag stock adjustments too — a write-off tagged North Branch stays separate from one tagged Main Chapel on the ledger reports you slice by location, so those reports keep telling the truth. One gap for non-profits: a stock adjustment line has Class and Location selectors but no Fund selector, so an adjustment always posts without a fund even when Funds are on.') }}
        </x-docs.callout>

        {{-- ──────────────────────── Stock adjustments ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Record a stock adjustment') }}</flux:heading>
        <flux:text>
            {{ __('Use a stock adjustment when quantity changes for a reason that is not a sale or a purchase — an opening balance, shrinkage, damage, a recount, or a write-off. Each adjustment is dated, has one reason, and lists one or more items with a signed quantity change. Adjustments are numbered ADJ-000001 onward for you.') }}
        </flux:text>

        <p><strong>{{ __('To record a stock adjustment:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Inventory → Adjustments — or select Adjustments at the top of the Stock on hand list — then New adjustment.') }}</li>
            <li>{{ __('Choose a Reason: Opening balance, Shrinkage, Damage, Recount (the default), Write-off, or Other. Set the Date — a click anywhere in the field opens the calendar — and add Notes to explain the change if you like.') }}</li>
            <li>{{ __('Add a line for each item: pick the item (only active tracked items are offered) and type the change in Qty (±). A positive number adds stock; a negative number removes it.') }}</li>
            <li>{{ __('When you are adding stock, enter the Unit cost you are bringing it in at. For removals leave it blank — the cost is taken from your costing method automatically.') }}</li>
            <li>{{ __('If Classes or Locations are on, each line also shows a selector for each one you switched on — Class for Classes, Location for Locations — so you can tag the line with a Class, a Location, or both. Select Add line for more items.') }}</li>
            <li>{{ __('Select Post adjustment. The adjustment is saved and posted in one step.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/inventory/adjustment-form.png') }}"
            alt="{{ __('The New stock adjustment dialog with Reason, Date, and Notes fields and a line for the Branded Mug showing the item, Qty (±), Unit cost, Class, and Location cells, with the Post adjustment button') }}"
            caption="{{ __('The New stock adjustment dialog. One reason and date cover every line; a positive quantity adds stock at the unit cost you enter, a negative one removes it at your costing method’s cost.') }}"
        />

        <x-docs.figure
            src="{{ asset('docs/screenshots/inventory/adjustments.png') }}"
            alt="{{ __('The Stock adjustments list showing the Opening balance adjustment ADJ-000001 with one line and a Posted status') }}"
            caption="{{ __('The Stock adjustments list. Each entry shows its number, date, reason, line count, and status — Posted, Draft, or Voided. Search by number or notes; the × at the end of a posted row voids it.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('What an adjustment does to your books') }}">
            {{ __('Posting an adjustment writes one balanced journal entry. Adding stock debits the inventory asset account; removing stock credits it. The offsetting side depends on the reason: an opening balance posts to Opening Balance Equity, while every other reason — recount, shrinkage, damage, write-off, or other — posts to the item’s cost-of-goods-sold (or adjustment) account. A posted adjustment cannot be edited: to correct one, void it and record a replacement. Voiding reverses both the journal entry and the stock it moved. An adjustment dated on or before your closing date is refused.') }}
        </x-docs.callout>

        {{-- ──────────────────────── Item history ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Review an item’s stock history') }}</flux:heading>
        <flux:text>
            {{ __('Open any item from the Stock on hand list to see its full movement history, newest first, under a heading that shows the quantity on hand and the unit cost it is carried at. Each row has six columns: the Date; the Source of the movement — the document type and its record number, such as Invoice #12, Bill #4, SalesReceipt #2, or StockAdjustment #1; the signed Qty, green for stock coming in and red for stock going out; the Unit cost; the resulting Value; and Notes, which carries the document’s own number (for example “Invoice INV-0001”) or the notes typed on an adjustment. When a document is voided, its original row stays as it was and a new reversing row appears, shown dimmed and tagged Reversal. This is the audit trail behind the item’s current quantity and average cost.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/inventory/item-show.png') }}"
            alt="{{ __('The Branded Mug history page showing 50 on hand at 9.00 per unit and one movement row sourced from StockAdjustment #1 with the note “Opening stock count”') }}"
            caption="{{ __('The Branded Mug’s history. The opening count of 50 at 9.00 came in through StockAdjustment #1; the Notes column shows the note typed on the adjustment.') }}"
        />

        {{-- ──────────────────────── Related reports ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reports tied to this area') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Stock Status (in the Inventory group of the Reports hub) — on-hand quantity, reorder point, and unit cost for every tracked item, with a Reorder badge on any item at or below its reorder point. (The Stock on hand list’s Low badge flags only items strictly below it.)') }}</li>
            <li>{{ __('Inventory Valuation — quantity, average cost, and value per item from the remaining FIFO cost layers. Only a FIFO organization has cost layers, so under Weighted Average every row reads zero; use the Total value on the Stock on hand list instead.') }}</li>
            <li>{{ __('Balance Sheet — the inventory asset account carries the value of your unsold stock; the Profit & Loss shows cost-of-goods-sold as you sell it.') }}</li>
        </ul>
        <flux:text>
            {{ __('All of these are described under') }}
            <a class="underline" href="{{ route('docs.reports') }}" wire:navigate>{{ __('Reports') }}</a>{{ __('.') }}
        </flux:text>

        <flux:heading size="md" class="mt-6">{{ __('Related areas') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Settings → Inventory — the organization’s costing method and the default inventory-asset and COGS accounts.') }}</li>
            <li>{{ __('Settings → Lists → Items — set an item’s Type to Inventory, record its opening balance, or import items in bulk. See') }} <a class="underline" href="{{ route('docs.lists') }}" wire:navigate>{{ __('Lists') }}</a>{{ __('.') }}</li>
            <li>{{ __('Accounting → Opening balances — the owner-only workspace for the rest of your opening figures; post opening stock before you finalize it. See') }} <a class="underline" href="{{ route('docs.opening-balances') }}" wire:navigate>{{ __('Opening balances') }}</a>{{ __('.') }}</li>
            <li>{{ __('API and AI assistants — another system can create and post stock adjustments through the API, and the MCP Inventory status tool answers “what is running low?” for an assistant. See') }} <a class="underline" href="{{ route('docs.api') }}" wire:navigate>{{ __('API') }}</a>{{ __('.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
