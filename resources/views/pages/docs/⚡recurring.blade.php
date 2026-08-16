<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Recurring')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Recurring')"
        :subheading="__('Set up a schedule once and let the app generate — and optionally post and email — your repeating invoices and bills.')"
    >
        <flux:text>
            {{ __('A recurring template is a saved document that the app turns into a real invoice or bill on a schedule — monthly retainers, rent, subscriptions, standing orders. One engine handles both sides: a single area sets up customer invoice schedules and vendor bill schedules. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        <flux:text>
            {{ __('Open Sales → Recurring (or Purchases → Recurring) from the sidebar to see your schedules. Each row shows the schedule name, whether it generates an Invoice or a Bill, the contact, the frequency, the next run date, how many documents it has generated, and a status badge — Active, Ended, or Needs attention.') }}
        </flux:text>

        <x-docs.callout type="note">
            {{ __('Recurring invoices and recurring bills are each an optional feature, turned on by default. If you do not see Recurring in the sidebar, open Settings → Organizations, open the organization, find the Features section, and switch on Recurring invoices or Recurring bills. Turning a feature back off later hides its Recurring item without deleting existing schedules.') }}
        </x-docs.callout>

        <x-docs.figure
            src="{{ asset('docs/screenshots/recurring/list.png') }}"
            alt="{{ __('The Recurring list showing a Monthly retainer invoice schedule for Northwind Traders') }}"
            caption="{{ __('The Recurring list. Create schedules with New invoice schedule or New bill schedule in the top-right corner.') }}"
        />

        {{-- ───────────────────────── Create a schedule ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Create a recurring invoice or bill') }}</flux:heading>
        <flux:text>
            {{ __('A schedule has two parts: the document content (contact, terms, and lines, just like the real thing) and the cadence that says when to generate it.') }}
        </flux:text>

        <p><strong>{{ __('To set up a schedule:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Recurring, then select New invoice schedule or New bill schedule.') }}</li>
            <li>{{ __('Choose the Customer (or vendor) and give the schedule a Schedule name like "Monthly retainer" so you can recognize it in the list.') }}</li>
            <li>{{ __('Set the Terms and a Memo if you need them.') }}</li>
            <li>{{ __('On an invoice schedule, choose When each invoice is generated — keep new invoices as drafts to review, or let the app post (and optionally email) them automatically. See "How each invoice is issued" below.') }}</li>
            <li>{{ __('Under Schedule, pick a Frequency — weekly, monthly, quarterly, semi-annual, or annual. Set the Start date, and for monthly and longer, the Day of month to run on.') }}</li>
            <li>{{ __('Choose when it Ends: Never (until paused), on a chosen end date, or after a set number of occurrences.') }}</li>
            <li>{{ __('Under Line items, add each line — Item or Account, Description, Qty, Unit price, and Tax — exactly as you would on the real document.') }}</li>
            <li>{{ __('Select Save schedule.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/recurring/create.png') }}"
            alt="{{ __('The New recurring invoice form with customer, schedule cadence, end condition, and line items') }}"
            caption="{{ __('The New recurring invoice form. The Schedule section controls how often the document is generated and when the run stops.') }}"
        />

        {{-- ───────────────────────── How each invoice is issued ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('How each invoice is issued') }}</flux:heading>
        <flux:text>
            {{ __('On an invoice schedule, the When each invoice is generated setting decides how much the app does for you. Pick the level of automation Demo Company Inc. is comfortable with:') }}
        </flux:text>

        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Save as a draft for review — the default. The app creates an unposted draft and leaves it for you to check and post. Nothing hits your books without a look from you.') }}</li>
            <li>{{ __('Issue automatically (post to the books) — the app posts each generated invoice straight to the general ledger, no review step.') }}</li>
            <li>{{ __('Issue and email each invoice automatically — the app posts the invoice and emails it to the customer in one step.') }}</li>
        </ul>

        <x-docs.figure
            src="{{ asset('docs/screenshots/recurring/automation-mode.png') }}"
            alt="{{ __('The When each invoice is generated dropdown showing the draft, post, and post-and-email options') }}"
            caption="{{ __('The automation choice on an invoice schedule. The default keeps every invoice as a draft until you post it.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('Bill schedules always draft') }}">
            {{ __('Automation applies to invoice schedules only. A vendor bill schedule always generates a draft for you to review and post — there is no auto-post option, because you decide when a bill is approved for payment.') }}
        </x-docs.callout>

        <x-docs.callout type="warning" heading="{{ __('Emailing needs an address, and a locked period falls back to a draft') }}">
            {{ __('Issue and email skips any customer with no email on file — the invoice is still posted, just not sent. If an automatic post would land in a locked period, the app leaves that invoice as a draft and keeps the run going instead of failing. The default email wording comes from Settings → Invoices.') }}
        </x-docs.callout>

        {{-- ───────────────────────── What the scheduler does ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('What the scheduler does') }}</flux:heading>
        <flux:text>
            {{ __('A background task runs once a day and generates every document that has come due, following each schedule\'s automation setting — draft, post, or post-and-email. Tax is recalculated at generation time, so a rate change automatically flows through to future documents. If the scheduler misses a few days, it catches up the missed occurrences rather than skipping them, and every generated document links back to the template it came from.') }}
        </flux:text>

        <flux:text>
            {{ __('On a catch-up that generates several invoices at once, a post-and-email schedule posts them all but emails only the most recent one, so a backdated start date does not blast the customer with a stack of messages.') }}
        </flux:text>

        <x-docs.callout type="tip" heading="{{ __('Generate one on demand') }}">
            {{ __('You do not have to wait for the daily run. Open a schedule and select Generate now to create its next occurrence immediately — it follows the same automation setting (draft, post, or post-and-email) as a scheduled run.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Pausing ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Pausing and auto-pause') }}</flux:heading>
        <flux:text>
            {{ __('You can pause and resume a schedule at any time from its page — a paused schedule stops generating documents until you turn it back on. The app also pauses a schedule for you when something it depends on goes missing.') }}
        </flux:text>

        <x-docs.callout type="note">
            {{ __('If the contact or an account a template depends on is deleted, the app pauses the schedule automatically and records why, rather than failing silently. Restore or fix what is missing, then resume the schedule.') }}
        </x-docs.callout>
    </x-pages::docs.layout>
</section>
