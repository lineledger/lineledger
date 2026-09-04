<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Lists')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Lists')"
        :subheading="__('Items, tax codes, payment terms, payment methods, other names, classes, and locations — the building blocks the rest of the app reuses.')"
    >
        <flux:text>
            {{ __('Lists hold the reusable pieces that fill in the rest of your bookkeeping: the products you sell, the taxes you charge, when invoices come due, and how money moves. Setting them up well early means cleaner invoices, accurate tax filing, and consistent reports later. Every list lives under Settings → Lists — open Settings from the sidebar, then choose the list you want to edit from the Lists group. Settings → Lists → All lists is a one-stop hub that links to every list with a live record count. Some lists only appear once their feature is switched on under Settings → Organizations. The examples below use our sample business, Demo Company Inc., and the screenshots reflect the Tidewater teal theme the app ships with.') }}
        </flux:text>

        {{-- ───────────────────────────── Items ───────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Items') }}</flux:heading>
        <flux:text>
            {{ __('An item is a saved product or service you sell or buy. Each item has a Type — Service, Non-inventory, Other charge, Inventory, or Bundle — plus a name, description, optional category, a default price, a default income (sales) account, an optional purchase/expense account for when you buy it, and up to two default tax codes. Picking an item on an invoice or bill line pre-fills all of those — change anything inline when you need to.') }}
        </flux:text>

        <p><strong>{{ __('To add an item:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Lists → Items, then select New item.') }}</li>
            <li>{{ __('Choose a Type: Service, Non-inventory, or Other charge (a fee or surcharge such as shipping) for things you do not stock, Inventory to track quantity on hand, or Bundle to group several items that expand into separate lines on a sale.') }}</li>
            <li>{{ __('Enter a Name and, if you use them, a SKU, description, and Category.') }}</li>
            <li>{{ __('Set a Default price and the Income / sales account the sale should post to. If you also buy the item, set a Purchase / expense account — it falls back to the income account when left blank.') }}</li>
            <li>{{ __('Under Default tax, pick up to two tax codes (for example GST and PST) so the right taxes apply whenever you add the item to a line — each is applied separately.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/lists/items.png') }}"
            alt="{{ __('The Items list showing an inventory product and an hourly service with their type and category') }}"
            caption="{{ __('The Items list. The Type column shows whether a row is a service, non-inventory, other-charge, inventory, or bundle item; “On hand” shows the live quantity for inventory items.') }}"
        />

        <x-docs.callout type="tip" heading="{{ __('Import items from a spreadsheet') }}">
            {{ __('Already have a product list? Select Import (top-right of the Items list) to bulk-add from a CSV. Download the template to see the columns — sku, name, type, income/expense/inventory/COGS accounts by code, default price, default tax, reorder point, and more — then upload your file to preview it before committing. Rows whose SKU matches an existing item are skipped, and any item category named in the file is created automatically if it does not exist yet.') }}
        </x-docs.callout>

        <flux:heading size="lg" class="mt-8">{{ __('Inventory tracking') }}</flux:heading>
        <flux:text>
            {{ __('Set an item’s Type to Inventory and the app maintains a live quantity on hand and the value of that stock. An inventory item needs two accounts: an inventory asset account (where the value of unsold stock sits on the balance sheet) and a cost-of-goods-sold (COGS) account (where that value moves when you sell). New items fall back to the company-wide default inventory and COGS accounts if you leave these blank.') }}
        </flux:text>
        <flux:text>
            {{ __('When you first create an inventory item you can record an opening balance — the quantity and unit cost already on your shelves. That posts a one-time entry, debiting the inventory asset account and crediting Opening Balance Equity, so you seed the stock without running it through a bill. From then on, purchasing the item on a bill increases stock at the cost you paid, and selling it on an invoice decreases stock and books the cost to COGS automatically.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('Pick a costing method before you trade') }}">
            {{ __('Set a reorder point to flag an item when it runs low. The company chooses a costing method — weighted average or FIFO — which determines how cost flows out as you sell. The method is locked once the item has any stock movement, so pick it before you start trading. See Settings → Inventory to set the company default accounts and costing method.') }}
        </x-docs.callout>

        <x-docs.callout type="tip" heading="{{ __('Inactive items do not disappear retroactively') }}">
            {{ __('Mark an item, tax code, payment term, or payment method as inactive and it stops showing up in new pickers — but every invoice, bill, or journal entry that already used it keeps displaying it as before. Hiding a stale choice from the dropdown will never silently rewrite history.') }}
        </x-docs.callout>

        <x-docs.callout type="note" heading="{{ __('Bundles group items together') }}">
            {{ __('A Bundle item is a saved group of other items — say a service plus the parts it uses. Add component items and quantities to the bundle, and choosing the bundle on an invoice expands it into one line per component, so each part still books to its own account and adjusts its own stock.') }}
        </x-docs.callout>

        {{-- ─────────────────────── Item categories ────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Item categories') }}</flux:heading>
        <flux:text>
            {{ __('Item categories group your products and services so they are faster to pick on sales forms and easier to slice in reporting. A category is just a name, and categories can nest: set a Parent category to build a simple hierarchy — for example Merchandise as the parent of Caskets and Urns. QuickBooks calls these Categories. Assign a category to an item from the Item form.') }}
        </flux:text>

        <p><strong>{{ __('To add an item category:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Lists → Item categories, then select New category.') }}</li>
            <li>{{ __('Enter a Name.') }}</li>
            <li>{{ __('Optionally pick a Parent category to nest it under another.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/lists/item-categories.png') }}"
            alt="{{ __('The Item categories list showing categories and their parent categories') }}"
            caption="{{ __('The Item categories list. The Parent column shows where each category nests in the hierarchy.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('Categories import from a CSV too — select Import on the Item categories list and download its template (columns: name, parent_name, is_active). Names that already exist are skipped; to nest a category, list the parent before its children.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Tax codes ───────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Tax codes') }}</flux:heading>
        <flux:text>
            {{ __('Tax codes describe how much tax to apply to a line and which agency the tax is owed to. Define one code per rate you have to collect or pay — for example GST 5%, HST Ontario 13%, or Exempt 0%. Posting tax to its own liability account is what makes the Sales Tax report and tax returns work. Each code is tied to a tax agency, which carries the registration number and the payable account the tax collects into.') }}
        </flux:text>

        <p><strong>{{ __('To add a tax code:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Lists → Tax codes, then select New code.') }}</li>
            <li>{{ __('Enter a short Code (such as GST), a descriptive Name, and the Rate as a percentage.') }}</li>
            <li>{{ __('Set Applies to — sales only, purchases only, or both — and turn on Recoverable (input tax credit) for a tax you can claim back from the government.') }}</li>
            <li>{{ __('Choose the tax Agency the tax is remitted to. To add one, select New agency (or New authority next to the picker) and give it a name, registration number, and payable account — leave the account blank and one is created for you.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/lists/tax-codes.png') }}"
            alt="{{ __('The Tax codes list with GST, HST, zero-rated, and exempt codes, plus the tax agency below') }}"
            caption="{{ __('The Tax codes list. The Tax agencies section below shows each agency’s registration number and the account its tax collects into.') }}"
        />

        {{-- ──────────────────────── Payment terms ─────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Payment terms') }}</flux:heading>
        <flux:text>
            {{ __('Payment terms define when an invoice or bill is due — Due on receipt, Net 15, Net 30, Net 60, and so on. Assign default terms to customers and vendors so new transactions inherit them and aging reports work without manual intervention.') }}
        </flux:text>

        <p><strong>{{ __('To add a payment term:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Lists → Payment terms, then select New term.') }}</li>
            <li>{{ __('Enter a Name (such as Net 30) and the number of Days until the document is due.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/lists/payment-terms.png') }}"
            alt="{{ __('The Payment terms list showing Due on receipt, Net 15, Net 30, and Net 60') }}"
            caption="{{ __('The Payment terms list. “Due on receipt” is simply a term with zero days.') }}"
        />

        {{-- ─────────────────────── Payment methods ────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Payment methods') }}</flux:heading>
        <flux:text>
            {{ __('Payment methods describe how money moved: cash, cheque, EFT, credit card, e-transfer, wire. They are reused across receipts, bill payments, deposits, and cheques. Each method can be tied to behavior — marking a method as a cheque method, for example, unlocks the Print cheque action on bill payments.') }}
        </flux:text>

        <p><strong>{{ __('To add a payment method:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Lists → Payment methods, then select New method.') }}</li>
            <li>{{ __('Enter a Name.') }}</li>
            <li>{{ __('Turn on the Cheque option if this method should enable cheque printing.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/lists/payment-methods.png') }}"
            alt="{{ __('The Payment methods list with cash, cheque, credit card, e-transfer, EFT, and wire') }}"
            caption="{{ __('The Payment methods list. The check mark in the Cheque column marks methods that enable cheque printing.') }}"
        />

        {{-- ───────────────────────── Other names ──────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Other names') }}</flux:heading>
        <flux:text>
            {{ __('An other name is a one-time payee — a raffle winner, a walk-in refund, “Cash” — that is not a vendor, customer, or employee and does not need a full record. Cheques and expenses written to an other name stay linked to it, so you can see everything paid to that name without inventing a fake vendor. QuickBooks calls these Other Names. The fastest way to add one is straight from the Pay to the order of field on a cheque (or Paid to on an expense): choose Add … as Other name and it is created on the spot. Every name added that way lands on this list.') }}
        </flux:text>

        <p><strong>{{ __('To add an other name:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Lists → Other names, then select New other name.') }}</li>
            <li>{{ __('Enter the Name as it should print on cheques, and optionally a note for your own reference.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.callout type="note" heading="{{ __('Transactions and one-way Convert') }}">
            {{ __('Select Transactions on any row to open the Transactions report filtered to that name across all dates. If a one-time payee turns into a regular supplier, use the row menu to convert it to a vendor, customer, or employee. It keeps the same record, so every cheque and expense already written to it stays linked — but the change cannot be undone, and the name moves from this list to that page.') }}
        </x-docs.callout>

        {{-- ──────────────────── Classes and locations ─────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Classes and locations') }}</flux:heading>
        <flux:text>
            {{ __('Classes and locations are optional tracking dimensions — extra labels you can attach to every transaction line to slice your reporting beyond the chart of accounts. A class might be a department, program, or product line; a location might be a store, region, or property. In Demo Company Inc. the classes are Funeral Services, Cremation Services, and Merchandise, and the locations are Main Chapel and North Branch. Each is its own list you maintain here.') }}
        </flux:text>
        <flux:text>
            {{ __('Both are off by default. Switch on Classes (features_classes), Locations (features_locations), or both under Settings → Organizations. Once on, a Class and Location selector appears on every transaction line — invoices, credit memos, bills, vendor credits, cheques, deposits, and journal entries — so you can tag where each line belongs. Tags follow the line into the general ledger and let you filter the income statement, balance sheet, and GL by class or location, without changing any totals.') }}
        </flux:text>

        <p><strong>{{ __('To add a class or location:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Lists → Classes (or Settings → Lists → Locations).') }}</li>
            <li>{{ __('Select New class (or New location).') }}</li>
            <li>{{ __('Enter a Name and select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/lists/classifications.png') }}"
            alt="{{ __('The Classes list showing Cremation Services, Funeral Services, and Merchandise') }}"
            caption="{{ __('The Classes list for Demo Company Inc. QuickBooks calls these Classes.') }}"
        />

        <x-docs.figure
            src="{{ asset('docs/screenshots/lists/locations.png') }}"
            alt="{{ __('The Locations list showing Main Chapel and North Branch') }}"
            caption="{{ __('The Locations list. QuickBooks calls these Locations.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('Tagging lines with classes and locations never changes a single total — it only adds a way to filter and group your reports. If you are not sure you need them yet, leave them off; you can switch them on later and the dropdowns simply appear.') }}
        </x-docs.callout>

        {{-- ───────────────────────────── Funds ───────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Funds') }}</flux:heading>
        <flux:text>
            {{ __('Funds power restricted-fund accounting for non-profits (the ASNPO restricted fund method). Each fund has a name and a type — General, Restricted, or Endowment — that determines how its net assets present on your Statement of Changes in Net Assets. You tag donations, grants, and journal lines to a fund, and your reports roll up per fund so you can show donors exactly how their money was used. This list requires the Funds feature; turn it on under Settings → Organizations.') }}
        </flux:text>

        <p><strong>{{ __('To add a fund:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Lists → Funds, then select New fund.') }}</li>
            <li>{{ __('Enter a Name and choose a Type (General, Restricted, or Endowment).') }}</li>
            <li>{{ __('Leave Active on so the fund shows up in pickers.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/lists/funds.png') }}"
            alt="{{ __('The Funds list showing restricted and unrestricted funds with their types') }}"
            caption="{{ __('The Funds list. The “default” marker shows the fund new lines fall back to when none is chosen.') }}"
        />

        <x-docs.callout type="note">
            {{ __('Funds are how restricted contributions stay accountable. See') }}
            <a class="underline" href="{{ route('docs.fundraising') }}" wire:navigate>{{ __('Fundraising') }}</a>
            {{ __('for how donations and grants are tagged to a fund and reported.') }}
        </x-docs.callout>

        {{-- ──────────────────────── Membership levels ─────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Membership levels') }}</flux:heading>
        <flux:text>
            {{ __('Membership levels define the dues tiers used by your membership roster. Each level carries a name, a default dues amount, a billing frequency (such as monthly or annual), and the revenue account dues post to. When you add a member at a level, those defaults pre-fill the dues invoices the app raises for them. This list requires the Membership feature.') }}
        </flux:text>

        <p><strong>{{ __('To add a membership level:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Lists → Membership levels, then select New level.') }}</li>
            <li>{{ __('Enter a Name and the default dues amount.') }}</li>
            <li>{{ __('Choose a billing frequency and the revenue account dues should post to.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/lists/membership-levels.png') }}"
            alt="{{ __('The Membership levels list showing tiers with their default dues, billing frequency, and revenue account') }}"
            caption="{{ __('The Membership levels list. Each tier’s default dues and revenue account flow onto the dues invoices raised for members at that level.') }}"
        />

        <x-docs.callout type="note">
            {{ __('Levels are the tiers you assign on the') }}
            <a class="underline" href="{{ route('docs.members') }}" wire:navigate>{{ __('Members') }}</a>
            {{ __('roster, where dues, renewals, and billing run from each member’s level.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Asset categories ─────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Asset categories') }}</flux:heading>
        <flux:text>
            {{ __('Asset categories group your fixed assets and carry the default GL accounts — asset, accumulated depreciation, and depreciation expense — plus a default useful life that pre-fill whenever you add a new asset to the category. You can also set a CCA class — the Capital Cost Allowance class the assets fall into — which flows onto the T2125 / CCA depreciation schedule at tax time. Maintain them under Settings → Lists → Asset categories: select New category, enter a Name, set the default accounts, useful life, and CCA class, then Save. This list requires the Fixed assets feature.') }}
        </flux:text>

        {{-- ───────────────────────── Form styles ──────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Form styles') }}</flux:heading>
        <flux:text>
            {{ __('A form style is a named invoice template. Each style can override your logo, an accent colour, and the footer message, so you can keep, say, a branded style for customers and a plain one for internal copies. You pick a style on an individual invoice; invoices that do not pick one fall back to the default style, or to your plain invoice settings if you have not added any styles yet.') }}
        </flux:text>

        <p><strong>{{ __('To add a form style:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Lists → Form styles, then select New style.') }}</li>
            <li>{{ __('Enter a Name.') }}</li>
            <li>{{ __('Optionally set an Accent colour as a 6-digit hex value (such as #2563eb) to tint the invoice title, table headers, and total.') }}</li>
            <li>{{ __('Add a Footer message to override the footer from your invoice settings, and turn Show logo on or off.') }}</li>
            <li>{{ __('Turn on Default style to make this the style new invoices use when none is chosen.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/lists/form-styles.png') }}"
            alt="{{ __('The Form styles list showing a named style with a default badge and an accent-colour swatch') }}"
            caption="{{ __('The Form styles list. The Default badge marks the style new invoices use, and the Accent swatch previews the style’s colour.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('No form styles yet? Invoices simply use the logo, colours, and footer from Settings → Invoices — adding a style only matters when you want more than one look.') }}
        </x-docs.callout>
    </x-pages::docs.layout>
</section>
