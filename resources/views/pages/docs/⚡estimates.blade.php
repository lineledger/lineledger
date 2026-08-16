<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Estimates')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Estimates')"
        :subheading="__('Send customers a price quote before any work is billed.')"
    >
        <flux:text>
            {{ __('An estimate is a quote — a proposed sale you send a customer before any money changes hands. Use it to put a price in front of someone and, once they say yes, turn it into the document that actually bills them. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        <flux:text>
            {{ __('Open Sales → Estimates from the sidebar to see the list. Each row shows the date, the estimate number, the customer, the expiry date, the total, and the current status.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/estimates/list.png') }}"
            alt="{{ __('The Estimates list showing one quote for Riverside Cafe with a Pending status') }}"
            caption="{{ __('The Estimates list. Use the search box or the status filter to narrow it down.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('Estimates never touch your books') }}">
            {{ __('An estimate is non-posting: creating, editing, accepting, or rejecting one does not create a journal entry, a receivable, or any inventory movement. Nothing hits your ledger until you convert the estimate into an invoice and post that invoice.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Create an estimate ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Create an estimate') }}</flux:heading>
        <flux:text>
            {{ __('Build an estimate the same way you build an invoice — the difference is that it only proposes a sale rather than recording one.') }}
        </flux:text>

        <p><strong>{{ __('To create an estimate:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Sales → Estimates, then select New estimate in the top-right corner.') }}</li>
            <li>{{ __('Choose the Customer. The Estimate # (like EST-0001), the Date, and an Expires on date fill in automatically — the expiry defaults to 30 days out. Adjust any of them if you need to.') }}</li>
            <li>{{ __('Optionally set Terms, a Sales rep, a Customer PO #, and a Memo.') }}</li>
            <li>{{ __('Add a Customer message if you want a note to appear on the printed estimate.') }}</li>
            <li>{{ __('On each line, pick an Item or an Account, type a Description, and enter the Qty and Unit price. Add a per-line Disc %, a Tax code, and Class or Location if you use them. The Amount calculates as you type.') }}</li>
            <li>{{ __('Select Add line for more than one thing on the same quote.') }}</li>
            <li>{{ __('Select Save estimate.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/estimates/create.png') }}"
            alt="{{ __('The New estimate form showing the customer, dates, and a line-item grid') }}"
            caption="{{ __('The New estimate form. The Expires on date drives whether a pending estimate later shows as Expired.') }}"
        />

        <flux:text>
            {{ __('A new estimate starts as Pending. You can come back and edit it at any time until it has been converted.') }}
        </flux:text>

        {{-- ───────────────────────── Statuses ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Statuses') }}</flux:heading>
        <flux:text>
            {{ __('An estimate moves through Pending, Accepted, Rejected, and Converted as you and the customer work through it. You set Accepted and Rejected yourself from the Actions menu; Converted is set for you when you convert the estimate.') }}
        </flux:text>

        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Pending — created and awaiting the customer’s decision. This is the starting status.') }}</li>
            <li>{{ __('Accepted — the customer has agreed to the quote.') }}</li>
            <li>{{ __('Rejected — the customer turned it down.') }}</li>
            <li>{{ __('Converted — you turned it into an invoice or a sales order; it is now locked.') }}</li>
        </ul>

        <x-docs.callout type="note" heading="{{ __('Expired is automatic') }}">
            {{ __('Expired is not a status you set. A Pending estimate shows as Expired on its own once the Expires on date has passed. An estimate you have already marked Accepted stays accepted even after that date.') }}
        </x-docs.callout>

        {{-- ───────────────────── Accept or reject an estimate ──────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Accept or reject an estimate') }}</flux:heading>
        <flux:text>
            {{ __('When the customer gets back to you, record their answer so the estimate’s status reflects where things stand. These actions only change the status — they never post anything to your books.') }}
        </flux:text>

        <p><strong>{{ __('To accept or reject an estimate:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the estimate.') }}</li>
            <li>{{ __('Open the Actions menu in the top-right corner.') }}</li>
            <li>{{ __('Choose Accept to mark it Accepted, or Reject to mark it Rejected.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/estimates/actions-menu.png') }}"
            alt="{{ __('An estimate’s Actions menu open, showing Convert to sales order, Print, Edit, Accept, and Reject') }}"
            caption="{{ __('The Actions menu on an estimate holds Accept, Reject, Convert to sales order, Print, and Edit.') }}"
        />

        <x-docs.callout type="note">
            {{ __('Accept is offered only while the estimate is Pending; Reject is offered while it is Pending or Accepted. Accepting is optional — you can convert a still-Pending estimate straight to an invoice without marking it Accepted first.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Convert to an invoice ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Convert an estimate to an invoice') }}</flux:heading>
        <flux:text>
            {{ __('When the customer says yes and you are ready to bill, convert the estimate. This is what finally moves the quote onto your books — by creating an invoice you can post.') }}
        </flux:text>

        <p><strong>{{ __('To convert an estimate to an invoice:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the estimate you want to convert.') }}</li>
            <li>{{ __('Select Convert to invoice in the top-right corner and confirm.') }}</li>
            <li>{{ __('Review the Draft invoice the app creates and opens for you — it copies the customer, terms, sales rep, PO, memo, customer message, and lines, and recalculates the tax against today’s rates.') }}</li>
            <li>{{ __('Post the invoice when you are ready, or keep it as a draft to finish later.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/estimates/show.png') }}"
            alt="{{ __('An estimate showing its Pending status, line items, totals, and a Convert to invoice button') }}"
            caption="{{ __('An open estimate. Convert to invoice creates a Draft invoice; the Actions menu also offers Convert to sales order.') }}"
        />

        <flux:text>
            {{ __('An estimate can be converted only once, in one direction. Convert to a sales order instead — from the Actions menu — when the customer has committed but you will fulfill and bill over time. Either way the estimate is marked Converted and linked to whatever it became, so the trail stays clear.') }}
        </flux:text>

        <x-docs.callout type="note">
            {{ __('If you later delete the invoice or sales order an estimate became, the estimate shows “Converted document was deleted” and the Convert actions come back, so you can convert it again.') }}
        </x-docs.callout>

        <x-docs.callout type="tip">
            {{ __('Use Print in the Actions menu to open a PDF of the estimate to send to the customer. The same menu has Edit while the estimate is still Pending, Accepted, or Rejected — once it is Converted, editing is locked.') }}
        </x-docs.callout>
    </x-pages::docs.layout>
</section>
