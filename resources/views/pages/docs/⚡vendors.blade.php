<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Vendors')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Vendors')"
        :subheading="__('Add suppliers, enter and pay bills, log pay-now expenses, and record vendor credits.')"
    >
        <flux:text>
            {{ __('The Vendors area is where you track everyone you buy from and the money you owe them. It is the mirror image of the Customers area: instead of recording what people owe you, it records what you owe your suppliers. Each vendor record links to a full history of bills, vendor credits, and payments, so you can always answer "what do we owe this vendor?" from one place. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        <flux:text>
            {{ __('Open Vendors from the sidebar to see the list. Each row shows the open balance — what you still owe that vendor across all their unpaid bills.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/vendors/list.png') }}"
            alt="{{ __('The Vendors list showing two vendors and their open balances') }}"
            caption="{{ __('The Vendors list. Toggle “Show inactive” to include suppliers you no longer buy from.') }}"
        />

        {{-- ───────────────────────── Add a vendor ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Add a vendor') }}</flux:heading>
        <flux:text>
            {{ __('Before you can enter a bill, the supplier needs a vendor record. You only set this up once — every future bill and payment reuses it.') }}
        </flux:text>

        <p><strong>{{ __('To add a vendor:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Vendors from the sidebar.') }}</li>
            <li>{{ __('Select New vendor in the top-right corner.') }}</li>
            <li>{{ __('Enter a name — this is what you will pick from lists everywhere else in the app.') }}</li>
            <li>{{ __('Fill in any contact details you have: company name, the contact person, email, and phone.') }}</li>
            <li>{{ __('Optionally set a default expense account, default tax code, and payment terms so they prefill on every new bill.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.callout type="tip">
            {{ __('When you stop buying from a vendor, mark them inactive instead of deleting: they disappear from selectors but their history stays intact for your reports.') }}
        </x-docs.callout>

        <x-docs.callout type="note" heading="{{ __('Contractor tax tracking (T4A and 1099)') }}">
            {{ __('Canadian companies can flag a vendor for T4A tracking and record their Business Number or SIN. Once flagged, the fees you pay that contractor feed the year-end T4A report (Box 048, fees for services). US companies get the equivalent instead — flag the vendor for 1099 tracking, record their Tax ID (EIN or SSN), and their payments feed the 1099 Summary report. Only the option that matches your company’s country appears.') }}
        </x-docs.callout>

        {{-- ───────────────────── Attachments and notes ───────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Attachments and notes') }}</flux:heading>
        <flux:text>
            {{ __('Bills, vendor credits, and pay-now expenses each have an Attachments panel where you can drop the supplier’s PDF, a scanned receipt, or any supporting paperwork — PDF, images, or Office documents up to 10 MB each. Files appear with their original name and size, and the × button removes one you added by mistake.') }}
        </flux:text>
        <flux:text>
            {{ __('To label a file so a teammate can tell at a glance what it is, open Documents → Attachment index, which lists every file attached to a transaction. Select the description text (or “Add description”) in the Description column, type a note of up to 500 characters, and save.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/vendors/attachment-description-modal.png') }}"
            alt="{{ __('The attachment description modal with a description field') }}"
            caption="{{ __('Adding a description to an attached file from Documents → Attachment index.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('For files that are not tied to a single transaction — contracts, insurance certificates, year-end working papers — use the') }}
            <a class="underline" href="{{ route('docs.documents') }}" wire:navigate>{{ __('Documents') }}</a>
            {{ __('area instead. It gives you folders, sharing controls, and the same attachment plumbing.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Enter a bill ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Enter a bill') }}</flux:heading>
        <flux:text>
            {{ __('A bill records something you owe a vendor — a utility statement, a supply order, a service invoice. Entering one adds the amount to what you owe and, once posted, flows straight into your financial reports.') }}
        </flux:text>

        <p><strong>{{ __('To enter a bill:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Purchases → Bills from the sidebar, then select New bill.') }}</li>
            <li>{{ __('Choose the Vendor. The bill number and dates fill in automatically — adjust them if you need to.') }}</li>
            <li>{{ __('Enter the Vendor reference (their invoice number) and pick the payment Terms if you want a due date calculated for you.') }}</li>
            <li>{{ __('On the first line, pick an Item or an Account, type a Description, and enter the Qty and Unit cost. The line Amount calculates as you type.') }}</li>
            <li>{{ __('Choose a Tax code for the line if the purchase is taxable. Add a per-line discount, class, or location if you track them.') }}</li>
            <li>{{ __('Select Add line for more than one thing on the same bill.') }}</li>
            <li>{{ __('Select Post bill to finalize it, or Save draft to keep working on it later.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/vendors/bill-create.png') }}"
            alt="{{ __('The New bill form showing the vendor, dates, and a line-item grid') }}"
            caption="{{ __('The New bill form. Each line picks an item or account, a quantity, a cost, and a tax code.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('Each line can carry up to two tax codes — handy where GST and PST (or QST) are charged separately. If the supplier’s tax does not match to the penny, type the exact amount in the small field under the tax picker to override the calculated tax for that line.') }}
        </x-docs.callout>

        <x-docs.callout type="note" heading="{{ __('Duplicate bill-number warning') }}">
            {{ __('The app can catch a bill you have likely already entered. If the chosen supplier already has a non-void bill carrying the same Vendor reference, saving opens a “Possible duplicate bill” prompt with Cancel and Save anyway. It is only a heads-up — it never blocks the save — and it stays quiet when the reference is blank or the vendor is new. Turn the check on with “Warn if duplicate bill number is used” under Settings → Organizations.') }}
        </x-docs.callout>

        <x-docs.callout type="note" heading="{{ __('What posting does to your books') }}">
            {{ __('Posting a bill debits the expense or asset accounts on each line and any recoverable sales tax, and credits Accounts Payable for the total — so the amount you owe is recorded the moment the bill arrives. If a line uses an inventory-tracked item, posting also increases that item’s quantity on hand at the cost you paid and adds the value to the inventory asset account, ready to be costed out when you sell.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('A posted bill shows its Posted status, a link to the journal entry it created, and a running Balance due as payments come in. Attach the supplier’s PDF or receipt under Attachments to keep the paper trail in one place.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/vendors/bill-show.png') }}"
            alt="{{ __('A posted bill showing its Posted status, linked GL entry, line items, and balance due') }}"
            caption="{{ __('A posted bill. The “Pay bill” button records a payment against it; the GL entry link opens the journal entry it created.') }}"
        />

        <x-docs.callout type="warning">
            {{ __('A posted bill should never simply be deleted — that would leave a gap in your records. To cancel a posted bill, void it: the app reverses the ledger entry (and any stock it received) and keeps the voided bill on file for your audit trail.') }}
        </x-docs.callout>

        <x-docs.callout type="note">
            {{ __('Class and Location are now available on every bill, vendor credit, and cheque line — turn them on under Settings → Organizations once and the selectors appear on each row, so you can slice AP spend by department, project, or storefront on your reports.') }}
        </x-docs.callout>

        {{-- ─────────────────── Receiving against a PO ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Receiving against a purchase order') }}</flux:heading>
        <flux:text>
            {{ __('When you fulfill a purchase order, the app generates a Draft bill that already lines up with the PO — each bill line is linked back to its purchase-order line, so quantities received stay tied to the original commitment. Open the draft, adjust the cost or Qty if the vendor shipped something different, then post it. Posting the bill is what actually increases stock and credits Accounts Payable. See the') }}
            <a class="underline" href="{{ route('docs.purchase-orders') }}" wire:navigate>{{ __('Purchase orders') }}</a>
            {{ __('page for the full receiving flow.') }}
        </flux:text>

        {{-- ───────────────────────── Pay a bill ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Pay a bill') }}</flux:heading>
        <flux:text>
            {{ __('When you send money to a vendor, record a bill payment to clear the bill from what you owe and move the money out of your bank.') }}
        </flux:text>

        <p><strong>{{ __('To pay a bill:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Purchases → Bill payments and select Pay bills — or open a posted bill and select Pay bill to start with that bill already chosen.') }}</li>
            <li>{{ __('Leave Pay to set to Vendor, then choose the Vendor. Their open bills appear so you can pick which ones the payment covers.') }}</li>
            <li>{{ __('Set the Date and, in Pay from, choose the bank or credit-card account the money came out of.') }}</li>
            <li>{{ __('Choose a Method (cheque, transfer, card, and so on) and add a Reference if you have one.') }}</li>
            <li>{{ __('Select Save & post.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/vendors/bill-payment-create.png') }}"
            alt="{{ __('The Pay bills form with pay-to, vendor, pay-from account, and method') }}"
            caption="{{ __('The Pay bills form. Pick the vendor, the account you are paying from, and which bills the payment clears.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('Vendor credit summary banner') }}">
            {{ __('As soon as you pick a vendor on the Pay bills form, a summary banner appears at the top showing the vendor’s available credit, the total of their open bills, and the net balance after credits are applied. A link on the banner opens the vendor’s AP statement, so you can see exactly which credits and bills make up that net figure before you commit a payment.') }}
        </x-docs.callout>

        <x-docs.figure
            src="{{ asset('docs/screenshots/vendors/bill-payment-credit-banner.png') }}"
            alt="{{ __('The Pay bills form with the credit summary banner at the top') }}"
            caption="{{ __('The credit summary banner. Open credits, open bills, and net balance for the selected vendor, with a link to the AP statement.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('Amount fields on the cheque form (and other money inputs) include a built-in calculator — type something like 1050+52.50 and press Enter, and the app commits the calculated total to the field. A small tape dropdown shows the running math so you can double-check what was added.') }}
        </x-docs.callout>

        <x-docs.figure
            src="{{ asset('docs/screenshots/vendors/cheque-calculator.png') }}"
            alt="{{ __('The cheque amount field showing the inline calculator tape') }}"
            caption="{{ __('The amount-input calculator on a cheque line. Press Enter to commit the calculated total.') }}"
        />

        <x-docs.callout type="note">
            {{ __('A bill payment debits Accounts Payable and credits the bank or credit-card account you paid from, clearing the bills it is applied to. When you choose a cheque-style method, the payment becomes printable — use the Print cheque action to render a cheque-formatted PDF aligned to standard pre-printed stock.') }}
        </x-docs.callout>

        {{-- ───────────────── Pay several suppliers at once ───────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Pay several suppliers at once') }}</flux:heading>
        <flux:text>
            {{ __('When it is time to run payables, you do not have to open each vendor one at a time. The batch screen lists every open bill across all your suppliers on a single page, so you can settle a whole stack in one pass.') }}
        </flux:text>

        <p><strong>{{ __('To pay multiple suppliers at once:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Purchases → Bill payments and select Pay multiple suppliers.') }}</li>
            <li>{{ __('Review the list of open bills. Each row shows the supplier, the bill, and its remaining balance.') }}</li>
            <li>{{ __('Type the amount to pay on each bill you want to cover, or select Full to apply that bill’s entire balance. You cannot enter more than a bill’s balance.') }}</li>
            <li>{{ __('Set the Date and the Pay from account the money leaves.') }}</li>
            <li>{{ __('Select Record payments.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/vendors/bill-payment-batch.png') }}"
            alt="{{ __('The batch payment screen listing open bills from several suppliers with an amount field on each row') }}"
            caption="{{ __('The batch payment screen. Enter an amount per bill or select Full, then Record payments to settle them all at once.') }}"
        />

        <x-docs.callout type="note">
            {{ __('The batch run groups the bills you ticked by supplier and posts one bill payment per supplier — each one debits Accounts Payable and credits the account you paid from. So a batch covering four bills from two vendors writes two payments, not four, keeping each vendor’s statement tidy.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Pay-now expenses ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Pay-now expenses') }}</flux:heading>
        <flux:text>
            {{ __('Not every purchase passes through Accounts Payable. When you pay on the spot — a company card at the supply store, an Interac transfer, an EFT, a debit tap, or petty cash — record it as an expense instead of a bill. There is no “owe then pay” step: the money leaves your account in a single entry.') }}
        </flux:text>

        <p><strong>{{ __('To record a pay-now expense:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Expenses from the sidebar, then select New expense.') }}</li>
            <li>{{ __('In Paid from, choose the bank or credit-card account the money came out of, and pick a Payment method.') }}</li>
            <li>{{ __('Set the Date and add a Reference no. (a confirmation or cheque number) if you have one.') }}</li>
            <li>{{ __('Optionally link a Payee contact, then fill in Paid to with who actually received the money.') }}</li>
            <li>{{ __('On each line, pick an Account, type a Description and Amount, and choose a Tax code. Add a Class or Location if you track them.') }}</li>
            <li>{{ __('Select Post expense to finalize it, or Save draft to finish later.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/vendors/expense-create.png') }}"
            alt="{{ __('The New expense form with paid-from account, payment method, paid-to field, and a line-item grid') }}"
            caption="{{ __('The New expense form. “Paid from” is the account the money leaves; “Paid to” is who received it.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('Use a bill when you owe the vendor and will pay later; use an expense when the money is already gone. Both land the same cost in your books — the difference is whether it passes through Accounts Payable on the way.') }}
        </x-docs.callout>

        <x-docs.callout type="note">
            {{ __('Posting an expense debits the account on each line and books any recoverable sales tax as an input tax credit (non-recoverable tax is folded into the expense), then credits the bank or credit-card account you paid from. There is no repost path: to fix a posted expense you void it and record a fresh one, which keeps the audit trail intact.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Vendor credits ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Vendor credits') }}</flux:heading>
        <flux:text>
            {{ __('A vendor credit is what a vendor owes back to you — for a return, an overbilling, or a goodwill adjustment. It is the purchase-side mirror of a customer credit memo, and it reduces what you owe.') }}
        </flux:text>

        <p><strong>{{ __('To create a vendor credit:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Purchases → Vendor credits, then select New vendor credit.') }}</li>
            <li>{{ __('Choose the Vendor and add lines exactly as you would on a bill — item or account, quantity, cost, and tax.') }}</li>
            <li>{{ __('Select Post.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/vendors/vendor-credit-create.png') }}"
            alt="{{ __('The New vendor credit form, which mirrors the bill form') }}"
            caption="{{ __('The New vendor credit form mirrors the bill form.') }}"
        />

        <x-docs.callout type="note">
            {{ __('Posting a vendor credit debits Accounts Payable and credits the original expense account on each line, reversing any recoverable tax. The credit nets against the vendor’s balance through the AP control account rather than being applied to one specific bill — so it simply lowers what the AP Aging and Open Bills reports show that vendor is owed.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Purchase orders ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Purchase orders') }}</flux:heading>
        <flux:text>
            {{ __('A purchase order records what you have committed to buy from a vendor before the bill arrives. It never posts on its own — you fulfill it by generating bills against it, and those bills are what post and receive stock. Purchase orders are an optional feature you can switch on per company.') }}
        </flux:text>
        <flux:text>
            {{ __('See the') }}
            <a class="underline" href="{{ route('docs.purchase-orders') }}" wire:navigate>{{ __('Purchase orders') }}</a>
            {{ __('page for the full workflow.') }}
        </flux:text>

        {{-- ──────────────────────── Related reports ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reports tied to this area') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('AP Aging — open vendor balances bucketed by how overdue they are.') }}</li>
            <li>{{ __('Open Bills — the flat list of every unpaid bill with its balance.') }}</li>
            <li>{{ __('Contact statement — every transaction with a single vendor over a date range.') }}</li>
            <li>{{ __('Sales tax — input tax paid on vendor bills, ready for filing.') }}</li>
            <li>{{ __('1099 Summary (US only) — total payments to vendors flagged for 1099.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
