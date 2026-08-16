<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Customers')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Customers')"
        :subheading="__('Add the people you bill, send invoices, issue credit memos, and record payments.')"
    >
        <flux:text>
            {{ __('The Customers area is where you track everyone you sell to and the money they owe you. Each customer record links to a full history of invoices, credit memos, and payments, so you can always answer "what does this customer owe?" from one place. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        <flux:text>
            {{ __('Open Customers from the sidebar to see the list. Each row shows the open balance — what that customer still owes you across all their unpaid invoices.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/customers/list.png') }}"
            alt="{{ __('The Customers list showing three customers and their open balances') }}"
            caption="{{ __('The Customers list. Toggle “Show inactive” to include customers you no longer do business with.') }}"
        />

        {{-- ───────────────────────── Add a customer ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Add a customer') }}</flux:heading>
        <flux:text>
            {{ __('Before you can invoice someone, they need a customer record. You only have to set this up once — every future invoice and payment reuses it.') }}
        </flux:text>

        <p><strong>{{ __('To add a customer:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Customers from the sidebar.') }}</li>
            <li>{{ __('Select New customer in the top-right corner.') }}</li>
            <li>{{ __('Enter a Display name — this is what you will pick from lists everywhere else in the app.') }}</li>
            <li>{{ __('Fill in any contact details you have: company name, the contact person, email, and phone. Email is what the app uses when you send an invoice.') }}</li>
            <li>{{ __('Optionally set a billing address, default payment terms, a default tax code, and a credit limit.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/customers/customer-form.png') }}"
            alt="{{ __('The New customer form with fields for name, contact details, and billing address') }}"
            caption="{{ __('The New customer form. Only a display name is required — everything else is optional.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('Set a credit limit and the invoice form will warn you when a new invoice would push the customer over it. It is only a heads-up — you can still bill past the limit. When you stop doing business with a customer, mark them inactive instead of deleting: they disappear from selectors but their history stays intact.') }}
        </x-docs.callout>

        <x-docs.callout type="note">
            {{ __('Looking for Class or Location on a customer or invoice line? Those columns are off by default. Turn them on under Settings → Organizations once, and a Class and Location selector appears on every transaction line you create afterward — invoices, credit memos, bills, journal entries — so you can slice your reports by department, project, or storefront.') }}
        </x-docs.callout>

        {{-- ─────────────────── Create and send an invoice ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Create and send an invoice') }}</flux:heading>
        <flux:text>
            {{ __('An invoice is a bill you send a customer for products or services. Creating one records the sale, adds the amount to what the customer owes you, and — once posted — flows straight into your financial reports.') }}
        </flux:text>

        <p><strong>{{ __('To create an invoice:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Sales → Invoices from the sidebar, then select New invoice.') }}</li>
            <li>{{ __('Choose the Customer. The invoice number, date, and due date fill in automatically — adjust them if you need to.') }}</li>
            <li>{{ __('On the first line, pick an Item or an Account, type a Description, and enter the Quantity and Unit price. The line total calculates as you type.') }}</li>
            <li>{{ __('Choose a Tax code for the line if the sale is taxable. Add a per-line discount or a service date if you need them.') }}</li>
            <li>{{ __('Select Add line to bill for more than one thing on the same invoice.') }}</li>
            <li>{{ __('Select Post invoice to finalize it, or Save draft to keep working on it later.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/customers/create-invoice-form.png') }}"
            alt="{{ __('The New invoice form showing the customer, dates, and a line-item grid') }}"
            caption="{{ __('The New invoice form. Use the Fields menu (top-right) to show or hide optional columns like service date, shipping, and tracking.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('Every amount field on the invoice form doubles as a quick calculator. Start typing math — for example 1050+52.50 — and a tape pops up showing each operation. Press Enter to commit the final value into the field. It works the same on credit memos, bills, cheques, and any other cents field in the app.') }}
        </x-docs.callout>

        <x-docs.figure
            src="{{ asset('docs/screenshots/customers/calculator-tape.png') }}"
            alt="{{ __('An amount field showing the in-place calculator tape with each operation listed') }}"
            caption="{{ __('The amount-field calculator. Type an expression, review the tape, press Enter to commit.') }}"
        />

        <flux:text>
            {{ __('A draft can still be changed or deleted. Posting locks the amounts into your books and assigns the invoice its place on your reports and the customer’s statement.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('What posting does to your books') }}">
            {{ __('Posting an invoice debits Accounts Receivable for the total and credits your revenue accounts, with any sales tax broken out to its own account. If a line uses an inventory-tracked item, posting also reduces that item’s quantity on hand and books its cost to cost-of-goods-sold — so your profit is recorded the moment you sell.') }}
        </x-docs.callout>

        <p><strong>{{ __('To send or print an invoice:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the invoice you want to send.') }}</li>
            <li>{{ __('Open the Actions menu in the top-right corner.') }}</li>
            <li>{{ __('Choose Send to client to email it — on an invoice raised for a membership member this reads Send to member — or choose Print to open a printable PDF copy in a new tab.') }}</li>
            <li>{{ __('In the send dialog, confirm the To address, add any CC or BCC recipients, edit the message, and select Send.') }}</li>
        </ol>
        <flux:text>
            {{ __('Only the To recipient gets the one-click link to view and pay the invoice; CC and BCC recipients receive the same email and PDF without that link. Tick “CC my business email” to copy yourself. The reply-to address comes from Settings → Invoices — the dialog shows where replies will land — and so does the default message; edit either one for an individual send. A posted invoice shows its status, a link to the journal entry it created, and a running Balance due as payments come in.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/customers/send-invoice-modal.png') }}"
            alt="{{ __('The Send invoice to client dialog with To, CC, and BCC fields, a “CC my business email” checkbox, and a message box') }}"
            caption="{{ __('The send dialog. Only the To recipient gets the pay link; CC and BCC get a plain copy with the PDF attached.') }}"
        />

        <x-docs.figure
            src="{{ asset('docs/screenshots/customers/invoice-posted.png') }}"
            alt="{{ __('A posted invoice showing its Posted status, linked GL entry, line items, and balance due') }}"
            caption="{{ __('A posted invoice. The “Receive payment” button records money against it; the GL entry link opens the journal entry it created.') }}"
        />

        <x-docs.callout type="note">
            {{ __('When an invoice mixes more than one tax code — say GST on some lines and a GST-plus-PST combination on others — the totals list each tax on its own line with its rate, instead of lumping everything into a single “Tax” amount. The posted invoice and its PDF then match exactly what each tax authority expects.') }}
        </x-docs.callout>

        <x-docs.callout type="warning">
            {{ __('A posted invoice cannot be edited freely the way a draft can, and it should never simply be deleted — that would leave a gap in your numbered records. To cancel a posted invoice, void it: the app reverses the ledger entry (and any stock movement) and keeps the voided invoice on file for your audit trail.') }}
        </x-docs.callout>

        {{-- ──────────────── Optional invoice fields and columns ─────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Optional invoice fields and columns') }}</flux:heading>
        <flux:text>
            {{ __('The invoice and credit memo forms ship with a lean default layout, but a long list of optional fields and line columns is one click away. Turn on only the ones Demo Company Inc. actually uses so the rest of the team is not staring at empty boxes.') }}
        </flux:text>

        <p><strong>{{ __('To change which fields and columns appear:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open a new or existing invoice.') }}</li>
            <li>{{ __('Open the Fields menu in the top-right corner.') }}</li>
            <li>{{ __('Toggle the header fields and line columns you want. Each change saves as you make it and sticks for the whole company, so the next invoice anyone opens uses the new layout.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/customers/field-visibility-settings.png') }}"
            alt="{{ __('The Fields menu on the invoice form showing header-field and line-column toggles for terms, sales rep, customer PO, ship date, ship via, FOB, tracking no, memo, message, and the line columns') }}"
            caption="{{ __('The Fields menu on the invoice form controls which optional fields and line columns show up on every new invoice.') }}"
        />

        <flux:text>
            {{ __('The Fields menu is split into two groups. Header fields cover Terms, Sales rep, Customer PO #, Ship date, Ship via, FOB, Tracking #, Memo, and the message displayed on the invoice. Line columns cover Item, Qty, Service date, Discount, Markup, Tax, Account, and a whole-document discount. A per-line discount is entered as a percentage in the Disc % column, so turn the Discount column on when you need it. The credit memo form has the same Fields menu with a slimmer set — no shipping or terms fields, since a credit memo is neither shipped nor due.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/customers/invoice-extra-fields.png') }}"
            alt="{{ __('An invoice form with the optional fields enabled — sales rep, customer PO, ship date, ship via, FOB, tracking no, and a per-line discount column') }}"
            caption="{{ __('An invoice form with the optional fields turned on. Hide the ones you do not use to keep new invoices fast to fill in.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('The Fields menu is the right place to declutter: hide any column the business never fills in, and new invoices stop showing it for everyone on the team. You can turn a column back on later without losing the data on past invoices. Settings → Invoices is separate — it controls the printed document instead: your logo, which company details appear, and the tax registration line.') }}
        </x-docs.callout>

        {{-- ───────────────── Deposits and progress billing ───────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Deposits and progress billing') }}</flux:heading>
        <flux:text>
            {{ __('A single invoice can be collected in stages — a deposit up front, progress payments through a project, or a balance on completion. The payment schedule on a posted invoice lets you spell out those milestones so you and the customer both know what is due and when.') }}
        </flux:text>

        <p><strong>{{ __('To set up a payment schedule:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the invoice and find the Payment schedule panel below the totals.') }}</li>
            <li>{{ __('Select Edit, then Add milestone for each stage you want to bill.') }}</li>
            <li>{{ __('Give each milestone a label such as “50% deposit”, choose Percentage or Fixed amount, enter the value, and set an optional due date.') }}</li>
            <li>{{ __('Select Save schedule. The milestones can add up to the full invoice but never more — an exact total folds any rounding into the last row.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/customers/payment-schedule.png') }}"
            alt="{{ __('The Payment schedule panel in edit mode with milestone rows for a deposit and a balance') }}"
            caption="{{ __('The Payment schedule panel in edit mode. Each milestone is a percentage or a fixed amount, with an optional due date.') }}"
        />

        <flux:text>
            {{ __('Each milestone carries a status: Requested until it is covered, Paid once enough payments have landed against the invoice, or Cancelled if you drop it. Paid is worked out automatically from the payments applied to the invoice — you never mark a milestone paid by hand.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('Milestones do not change your books') }}">
            {{ __('A payment schedule is a billing plan, not a ledger entry. It creates no journal entries and never splits Accounts Receivable — the invoice keeps one single balance owing. Record customer payments the usual way under Receive a payment, and the milestones update their status to match.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Credit memos ────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Credit memos') }}</flux:heading>
        <flux:text>
            {{ __('A credit memo reduces what a customer owes you — use one for a return, a billing correction, or a goodwill adjustment. It is the mirror image of an invoice.') }}
        </flux:text>

        <p><strong>{{ __('To create a credit memo:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Sales → Credit memos, then select New credit memo.') }}</li>
            <li>{{ __('Choose the Customer and add lines exactly as you would on an invoice — item or account, quantity, price, and tax.') }}</li>
            <li>{{ __('Select Post credit memo.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/customers/credit-memo-form.png') }}"
            alt="{{ __('The New credit memo form, which mirrors the invoice form') }}"
            caption="{{ __('The New credit memo form mirrors the invoice form.') }}"
        />

        <x-docs.callout type="note">
            {{ __('A credit memo lowers the customer’s balance: it credits Accounts Receivable and debits the revenue (or other) account on each line. The credit then sits on the customer’s account and is automatically offered against their next payment, so you do not have to track it by hand.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('When the customer would rather have their money back than a credit on account, open the posted credit memo and select Refund to client. Choose By cheque to create a draft refund cheque you review and post — it debits Accounts Receivable and credits your bank — or By credit card to record the refund straight away. The card option books a negative customer receipt against Undeposited Funds, so you deposit it with your other card takings on a bank deposit. Either way the credit memo tracks how much has been refunded and shows a Partly refunded or Refunded badge, and you can drop a refund back off by voiding it.') }}
        </flux:text>

        {{-- ────────────────────── Receive a payment ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Receive a payment') }}</flux:heading>
        <flux:text>
            {{ __('When a customer pays, record a receipt to clear the invoice from their balance and move the money into your bank.') }}
        </flux:text>

        <p><strong>{{ __('To record a payment:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Sales → Receipts and select Receive payment — or open an invoice and select Receive payment to start with that invoice already chosen.') }}</li>
            <li>{{ __('Choose the Customer. Their open invoices appear so you can tick which ones the payment covers.') }}</li>
            <li>{{ __('Enter the Amount and the Date received.') }}</li>
            <li>{{ __('In Deposit to, choose the bank account the money went into (or Undeposited Funds if you are grouping it for a later bank deposit).') }}</li>
            <li>{{ __('Select Save & post.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/customers/receipt-form.png') }}"
            alt="{{ __('The Receive payment form with customer, amount, and deposit-to account') }}"
            caption="{{ __('The Receive payment form. “Quick pick from a recent invoice” fills in the customer and amount for you.') }}"
        />

        <x-docs.callout type="note">
            {{ __('Recording a receipt debits the bank or undeposited-funds account and credits Accounts Receivable, clearing the invoices it is applied to. Card payments your customers make through the Customer portal arrive here automatically.') }}
        </x-docs.callout>

        {{-- ────────────────────── Payment reminders ─────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Payment reminders') }}</flux:heading>
        <flux:text>
            {{ __('LineLedger can chase overdue invoices for you. A built-in four-step ladder emails the customer a friendly nudge three days before the due date, then follow-ups one, seven, and fourteen days after it — each one a little firmer. Once a customer is opted in, reminders run on their own every morning, so collections keep moving without anyone remembering to send them.') }}
        </flux:text>
        <flux:text>
            {{ __('Automated reminders are off for every customer until you turn them on. Open the customer, go to the Billing tab, and switch on Send payment reminders. Nothing is emailed to a customer who has not been opted in.') }}
        </flux:text>
        <flux:text>
            {{ __('After that, a reminder only goes out when it makes sense: the invoice is still open with a balance owing, reminders are switched on for that invoice, and the customer has an email address on file. Every reminder quotes the live balance owing, so a partial payment is always reflected.') }}
        </flux:text>

        <p><strong>{{ __('To work today’s reminders by hand:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Sales → Invoices and select Reminders — the bell button at the top of the list.') }}</li>
            <li>{{ __('Review the invoices that are due for a reminder today. Switch on Show reminders-off customers to also see the ones the morning run will skip.') }}</li>
            <li>{{ __('Select Send now to email a reminder immediately, Skip to stop reminders for that one invoice, or Turn off reminders to stop the automated ones for everything that customer owes.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/customers/reminders-worklist.png') }}"
            alt="{{ __('The Payment reminders worklist listing invoices due for a reminder with Send now, Skip, and Turn off reminders actions') }}"
            caption="{{ __('The Payment reminders worklist. Opted-in reminders also send automatically each morning — this page is for working them by hand.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('Skip turns reminders off for a single invoice; Turn off reminders turns the automated ones off for that customer across every invoice. Send now still works either way — it is an explicit, one-off send that leaves the customer’s preference untouched.') }}
        </x-docs.callout>

        {{-- ──────────────────────── Related reports ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reports tied to this area') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('AR Aging — open balances bucketed by how overdue they are.') }}</li>
            <li>{{ __('Contact statement — every transaction with a single customer over a date range.') }}</li>
            <li>{{ __('Sales tax — tax collected on customer invoices, ready for filing.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
