<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Inbox')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Inbox')"
        :subheading="__('Capture receipts and bills as they arrive — by drag-and-drop or by email — then turn each one into a draft bill or expense.')"
    >
        <flux:text>
            {{ __('The Inbox is a holding area for paper-trail documents — supplier receipts, vendor bills, expense slips — before they become real transactions. You drop a file in (or have it emailed straight to a private address), the app optionally reads the vendor, total, and date off it, and you promote the result into a draft bill or expense in one click. Nothing touches your books until you review and post that draft, so the inbox is a safe place to park documents the moment they land on your desk. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        <flux:text>
            {{ __('Open Inbox → Review queue from the sidebar to see everything waiting. Each row shows the document, the vendor and total once they have been read, and a status badge tracking where the item is in its life.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/inbox/review-queue.png') }}"
            alt="{{ __('The Inbox review queue with a drag-and-drop zone above a table of staged documents and their status badges') }}"
            caption="{{ __('The Inbox review queue. Drop files in the zone at the top; items appear below and update on their own while they are being read.') }}"
        />

        {{-- ───────────────────────── Add by drag-drop ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Add documents by drag-and-drop') }}</flux:heading>
        <flux:text>
            {{ __('The fastest way to get a receipt into the system is to drop the file onto the inbox. You can add several at once, and each one becomes its own item.') }}
        </flux:text>

        <p><strong>{{ __('To upload documents:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Inbox → Review queue from the sidebar.') }}</li>
            <li>{{ __('Drag one or more files onto the dotted drop zone, or click it to pick files from your computer.') }}</li>
            <li>{{ __('Select Add to inbox to stage them.') }}</li>
        </ol>

        <flux:text>
            {{ __('Accepted file types are PDF, PNG, JPG, JPEG, WEBP, and GIF, up to 10 MB each. Staged items show up immediately with a status of Pending or Reading…, and the list refreshes itself every few seconds until the reading finishes — you do not need to reload the page.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('What the status badges mean') }}">
            {{ __('Pending and Reading… mean the document is queued or being read. Needs review means it is ready for you to check and promote. Promoted means you have already turned it into a draft bill or expense. Failed means the file could not be read automatically — open it and enter the details by hand.') }}
        </x-docs.callout>

        {{-- ───────────────────── Reading receipts automatically ───────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reading receipts automatically') }}</flux:heading>
        <flux:text>
            {{ __('When automatic reading is switched on, the app uses AI to pull the vendor, the total, and the date off each document and pre-fill the review form for you. It also tries to match the vendor name to a contact you already have, so the draft points at the right supplier. Reading is entirely optional: with it off, every document still lands in the queue ready for manual entry — you just type the details yourself.') }}
        </flux:text>

        <p><strong>{{ __('To turn on automatic reading:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Inbox email.') }}</li>
            <li>{{ __('Switch on Read receipts automatically.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.callout type="note" heading="{{ __('Reading is doubly opt-in') }}">
            {{ __('Documents are only ever sent for automatic reading when two switches agree: the person who runs your server has to enable the feature there (and supply an AI key), and your organization has to flip its own Read receipts automatically switch. Until both are on, nothing leaves your books for analysis and every document simply waits in Needs review for you to fill in.') }}
        </x-docs.callout>

        {{-- ───────────────────── Forward documents by email ───────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Forward documents by email') }}</flux:heading>
        <flux:text>
            {{ __('Most receipts and bills arrive in your email in the first place. Instead of saving each one and dropping it in by hand, you can give Demo Company Inc. a private forwarding address and email documents straight into the inbox. Every attachment becomes an inbox item, exactly as if you had uploaded it.') }}
        </flux:text>

        <p><strong>{{ __('To set up email forwarding:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Inbox email.') }}</li>
            <li>{{ __('Switch on Accept documents by email.') }}</li>
            <li>{{ __('Select Save. A forwarding address is generated for you, in the form inbox+yourtoken@your-domain.') }}</li>
            <li>{{ __('Copy that address and forward (or auto-forward) your receipts and bills to it.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/inbox/email-settings.png') }}"
            alt="{{ __('Settings → Inbox email showing the Accept documents by email switch, the generated forwarding address, and the Read receipts automatically switch') }}"
            caption="{{ __('Settings → Inbox email. Turning on Accept documents by email mints the forwarding address; the second switch controls automatic reading.') }}"
        />

        <x-docs.callout type="warning" heading="{{ __('Only your team can email in') }}">
            {{ __('For security, the forwarding address only accepts mail sent from the address of an active member of your organization. Anything from an unknown sender is silently ignored, so a leaked address cannot be used to stuff your books with junk. If the address does get out, select Generate new address to rotate it — the old one stops working immediately.') }}
        </x-docs.callout>

        <x-docs.callout type="note">
            {{ __('Email forwarding only works when the person who runs your server has configured an inbound mail domain. If it has not been set up, Settings → Inbox email tells you so when you try to turn the switch on — drag-and-drop uploads keep working regardless.') }}
        </x-docs.callout>

        {{-- ───────────────────── Review and promote an item ───────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Review a document and create a draft') }}</flux:heading>
        <flux:text>
            {{ __('Once an item reaches Needs review, you check what was read and turn it into a transaction. Select Review on its row in the queue to open the review screen. The document sits on the left — an image previews inline, while a PDF or other file opens in a new tab — and the form on the right holds the details to confirm, with a category-and-tax line grid below it.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/inbox/item-review.png') }}"
            alt="{{ __('The review screen with the document preview on the left, a form for document type, vendor, contact, and date on the right, and a category-and-tax line grid below') }}"
            caption="{{ __('The review screen. Check the figures we read against the document, fill in anything missing, then create the draft.') }}"
        />

        <p><strong>{{ __('To promote a document:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('In Create as, choose what to build: Vendor bill (something you will pay later), Expense (paid now), Employee reimbursement (money an employee or owner is owed back), or Match a bank transaction (clear an imported statement line with this receipt).') }}</li>
            <li>{{ __('Confirm the Vendor / payee. A bill or a reimbursement needs a Contact — a vendor for a bill, an employee for a reimbursement — while an expense can stand on a typed payee name alone.') }}</li>
            <li>{{ __('Check the Date, correcting it if it was misread.') }}</li>
            <li>{{ __('On each line, set the Category account the spend should land in — for example Office Supplies or Meals & Entertainment — the pre-tax Amount, and the Tax. You can pick up to two tax codes (for example GST and PST) and each is broken out for you; use Add line to split one receipt across several categories.') }}</li>
            <li>{{ __('For an expense, also choose Paid from — the bank or credit-card account the money came out of. For a bank match, pick the Bank transaction to clear; the line total must equal that transaction’s amount.') }}</li>
            <li>{{ __('Select Create draft (or Record transaction for a bank match).') }}</li>
        </ol>

        <x-docs.callout type="tip">
            {{ __('When automatic reading is on, the app also suggests a category for the spend — drawn first from how you have coded that vendor before, and falling back to an AI guess. A short “Category suggestion” line explains the pick; change the account whenever the guess is off.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('Creating the draft takes you straight to the new document. The original file rides along automatically and is attached to it, so the receipt and the transaction stay together. The inbox item is marked Promoted and drops off the active queue.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('Promoting does not post to your books') }}">
            {{ __('Creating a draft from an inbox item never touches the general ledger — it only builds an unposted bill or expense for you to check. Your accounts move only when you post that draft afterwards: posting a vendor bill debits the category account and credits Accounts Payable (what you owe the vendor), while posting an expense debits the category account and credits the bank or credit-card account it was paid from.') }}
        </x-docs.callout>

        <x-docs.callout type="tip">
            {{ __('The review form pre-fills with whatever automatic reading found, but your edits always win. Even with reading turned off, the inbox is still useful: it gives you one organized place to keep every receipt waiting to be entered, and promoting it builds the draft and attaches the file in a single step.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Dismissing items ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Dismiss items you do not need') }}</flux:heading>
        <flux:text>
            {{ __('Not every document belongs in your books — duplicates, junk, or a receipt you have already entered another way. Select Dismiss on its row to clear it from the queue. Dismissing does not delete anything from your accounting; it simply hides the item so the review queue only shows work that still needs doing.') }}
        </flux:text>

        {{-- ──────────────────────── Where promoted items go ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Where promoted documents end up') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Vendor bills — Purchases → Bills, ready to review and post, then pay later.') }}</li>
            <li>{{ __('Expenses — Purchases → Expenses, recorded as already paid from the account you chose.') }}</li>
            <li>{{ __('Employee reimbursements — Employees → Reimbursements, ready to review and post, then pay the employee back.') }}</li>
            <li>{{ __('Bank matches — recorded as an Expense against the transaction you picked, so the imported statement line clears in the same step. Find it under Purchases → Expenses.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
