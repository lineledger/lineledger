<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Tax returns')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Tax returns')"
        :subheading="__('Record tax filings and the payments you make against them.')"
    >
        <flux:text>
            {{ __('A tax return records a filing with a tax agency — for example a CRA GST/HST return — for a single period, tracking what you collected and what you paid (your input tax credits), the net owing, and any payments you make against it. Its figures are drawn straight from the journal entries in the period, so the return always reflects what is actually in your books. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        <flux:text>
            {{ __('Tax returns live in the Reports area. Open Reports from the sidebar and select Tax Returns under the Sales Tax heading. Each row shows the agency, the period it covers, the tax collected and paid, the net, and the status — draft, filed, or void.') }}
        </flux:text>

        <x-docs.callout type="tip">
            {{ __('Star the Tax Returns card on the Reports page and a Tax Returns shortcut pins to your sidebar, so you do not have to dig through Reports each time you file.') }}
        </x-docs.callout>

        <x-docs.figure
            src="{{ asset('docs/screenshots/tax-returns/list.png') }}"
            alt="{{ __('The Tax returns list showing each return’s agency, period, collected and paid amounts, net, and status') }}"
            caption="{{ __('The Tax returns list. Filter by status or search by return number or agency.') }}"
        />

        {{-- ──────────────────── Which returns apply to you ───────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Which returns apply to you') }}</flux:heading>
        <flux:text>
            {{ __('LineLedger does not keep a deadline calendar or send filing reminders — you record each filing as a tax return at the time you file it with the agency. To see which CRA returns your organization is responsible for, open Settings → Tax & filing. It lists the forms that apply based on your entity type and legal tier — the primary return plus any information returns — with a link to open the matching report and a link to the CRA’s own form page.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/tax-returns/filing-forms.png') }}"
            alt="{{ __('Settings → Tax & filing listing the CRA forms that apply to the organization, each with an Open report button and a CRA form page link') }}"
            caption="{{ __('Settings → Tax & filing shows which CRA returns apply to your organization. It is general guidance, not tax advice — confirm your obligations with the CRA or your accountant.') }}"
        />

        {{-- ──────────────────────── Create a tax return ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('File a tax return') }}</flux:heading>
        <flux:text>
            {{ __('A return starts as a draft so you can review the numbers before committing them. The app reads your journal entries for the period and lists every line that contributes, so you can check the figures against what the agency expects.') }}
        </flux:text>

        <p><strong>{{ __('To file a tax return:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the Tax returns list and select File new return.') }}</li>
            <li>{{ __('Choose the Tax agency you are filing with. The return number fills in automatically — adjust it if you need to.') }}</li>
            <li>{{ __('Set the Period start and Period end. The form defaults to the previous calendar quarter; change it to match your filing period.') }}</li>
            <li>{{ __('Review the lines the app calculated from your journal entries. Each line is tagged Collected or Paid (an input tax credit) and adds up to the Collected, Paid, and Net owing totals shown above the list.') }}</li>
            <li>{{ __('Uncheck the Include box on any line you want to exclude — for example an imported opening-balance line that does not belong to this filing period. The totals update as you toggle lines.') }}</li>
            <li>{{ __('Add an optional Filing reference (the government confirmation number) and any Notes.') }}</li>
            <li>{{ __('Select Save draft to keep working on it later, or File return to lock in the snapshot. You can also file a saved draft later from its detail page.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/tax-returns/return-form.png') }}"
            alt="{{ __('The File a tax return form showing the agency, period dates, the collected, paid, and net totals, and the line-by-line preview with include checkboxes') }}"
            caption="{{ __('The File a tax return form. Uncheck a line to leave it out of the totals and the filed snapshot.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('What filing does') }}">
            {{ __('Filing has no effect on your ledger — it is record-keeping only. A draft recalculates from your journal entries every time you open it, but once you File a return the contributing lines are captured as a permanent, audit-ready snapshot and the period is locked for that agency: posting or back-dating a transaction that touches that agency’s tax codes inside the filed period is blocked, so the snapshot stays a faithful record of what you reported.') }}
        </x-docs.callout>

        <x-docs.callout type="warning">
            {{ __('You cannot file two returns that overlap for the same agency. If a filed return already covers part of the period, filing is refused — void the existing return first, or adjust your dates so the periods do not overlap.') }}
        </x-docs.callout>

        {{-- ──────────────────────────── Record a payment ─────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Record a payment') }}</flux:heading>
        <flux:text>
            {{ __('Once a return is filed, record the money you send the agency (or the refund you receive) so it clears the tax payable and moves through your bank. Payments can only be recorded against filed returns. When the net is owing the button reads Record payment; when the return is in a refund position it reads Record refund instead.') }}
        </flux:text>

        <p><strong>{{ __('To record a payment:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the filed return and select Record payment (or Record refund).') }}</li>
            <li>{{ __('The Payment number and date fill in for you. Choose the Bank account the money moves through, and optionally set a Payment method and a Reference (the confirmation or tracking number).') }}</li>
            <li>{{ __('The Net tax payment is pre-filled with the return’s net — adjust it if you are paying a different amount.') }}</li>
            <li>{{ __('If they apply, enter any Penalty, Interest, or Commission / processing fee. Each amount you enter reveals its own account selector so it posts separately. (On a refund, only Interest received applies, and it posts to an income account.)') }}</li>
            <li>{{ __('Check the Total moving through bank, then select Record payment (or Record refund). It posts to your books immediately.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/tax-returns/payment-form.png') }}"
            alt="{{ __('The Record tax payment form with the payment number, date, bank account, net amount, and optional penalty, interest, and commission fields each with their own account selector') }}"
            caption="{{ __('The Record tax payment form. Penalty, interest, and commission are optional — each reveals its own account selector once you enter an amount.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('What recording a payment does to your books') }}">
            {{ __('A payment to the agency debits the agency’s Tax Payable account for the net amount, debits the expense account you chose for any penalty, interest, or commission, and credits your bank for the total that leaves it. A refund reverses this: it debits your bank for the total received, credits Tax Payable for the net, and credits an income account for any interest received.') }}
        </x-docs.callout>

        <x-docs.callout type="tip">
            {{ __('A return takes one payment at a time — once a payment is posted, the Record payment button is hidden. Made a mistake? Open the payment and select Void: the app posts a reversing journal entry, and the return is ready for you to record a corrected payment.') }}
        </x-docs.callout>

        {{-- ──────────────────────────── Statuses ─────────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Statuses') }}</flux:heading>
        <flux:text>
            {{ __('A return moves through three statuses: draft → filed → void. A draft can still be edited, and its numbers keep recalculating from your journal entries. Filing freezes the snapshot, locks the period for that agency, and unlocks Record payment. Voiding a return (you must give a reason) does not touch the ledger — it marks the return Void, keeps the snapshot rows for your audit trail, and unlocks the period so you can post in it again.') }}
        </flux:text>

        <flux:text>
            {{ __('Tax returns lean on the figures you can see any time on the') }}
            <a class="underline" href="{{ route('docs.reports') }}" wire:navigate>{{ __('Sales tax report') }}</a>{{ __(', which shows the tax collected and the input tax credits paid, ready for filing. See') }}
            <a class="underline" href="{{ route('docs.reports') }}" wire:navigate>{{ __('Reports') }}</a>
            {{ __('for the full list of financial statements and tax reports.') }}
        </flux:text>

        {{-- ──────────────────────── Related reports ──────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reports tied to this area') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Sales Tax — per-agency tax collected on sales versus input tax credits claimed on purchases, ready for filing.') }}</li>
            <li>{{ __('GIFI Statement and other CRA reports — agency-formatted summaries for filing, where applicable.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
