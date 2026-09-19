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
            {{ __('A tax return records a filing with a tax agency — for a Canadian organization, typically a CRA GST/HST return — for a single period, tracking what you collected and what you paid (your input tax credits), the net owing, and any payment you make against it. Its figures are drawn straight from the journal entries in the period, so the return always reflects what is actually in your books. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        <flux:text>
            {{ __('Tax returns live in the Reports area. Open Reports → All Reports from the sidebar and select Tax Returns under the Sales Tax heading. The list leads with the Period end and the Return # — the number opens the return’s page — followed by the Agency, the Period, the Collected, Paid, and Net amounts, and the Status: Draft, Filed, or Void. Filter by status, or search by return number or agency name. A draft shows 0.00 in the three amount columns until it is filed; its live figures are on the Edit form.') }}
        </flux:text>

        <x-docs.callout type="tip">
            {{ __('Select the star on the Tax Returns card in the Reports hub (Add to favorites) and a Tax Returns shortcut pins to your sidebar, so you do not have to dig through Reports each time you file.') }}
        </x-docs.callout>

        <x-docs.figure
            src="{{ asset('docs/screenshots/tax-returns/list.png') }}"
            alt="{{ __('The Tax returns list showing each return’s period end, return number, agency, period, collected and paid amounts, net, and a Draft or Filed status badge, with the search box, status filter, and File new return button above') }}"
            caption="{{ __('The Tax returns list. The Return # opens the return; filter by status or search by return number or agency. A draft shows 0.00 until it is filed.') }}"
        />

        {{-- ──────────────────── Which returns apply to you ───────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Which returns apply to you') }}</flux:heading>
        <flux:text>
            {{ __('LineLedger does not keep a deadline calendar or send filing reminders — you record each filing as a tax return at the time you file it with the agency. To see which CRA returns your organization is responsible for, open Settings → Tax & filing. The page opens with a Your organization panel showing your Type and Legal tier, then Forms you file: one card per return, marked Primary or Information return, with the form code, its name, a short description, a note on when it applies, and a CRA form page link to the CRA’s own page. Most cards also have an Open report button that jumps to the matching report in the Reports hub. The exception is the T1044 non-profit information return — listed beside the T2 for a non-profit corporation, and on its own for an unincorporated association — which the app does not prepare, so its card has only the CRA form page link. If no CRA income-tax return is generated for your organization type, the page says so and points you at the Sales Tax report instead.') }}
        </flux:text>

        <x-docs.callout type="note">
            {{ __('Settings → Tax & filing is for Canadian organizations only — the entry appears in the Settings menu only when your organization is set up in Canada. A United States organization files sales tax returns exactly the same way — the Tax returns list keeps its heading, and only the line beneath it changes, reading “Filed Sales Tax and other tax returns” instead of “Filed GST/HST and other tax returns” — but it has no CRA forms page.') }}
        </x-docs.callout>

        <x-docs.figure
            src="{{ asset('docs/screenshots/tax-returns/filing-forms.png') }}"
            alt="{{ __('Settings → Tax & filing for Demo Community Society, showing the Your organization panel with its Type and Legal tier, and under Forms you file the T3010 card with its Primary badge, Open report button, and CRA form page link') }}"
            caption="{{ __('Settings → Tax & filing lists the CRA returns that apply to a Canadian organization — here Demo Community Society, a registered charity, which files the T3010. It is general guidance, not tax advice — confirm your obligations with the CRA or your accountant.') }}"
        />

        {{-- ──────────────────────── Create a tax return ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('File a tax return') }}</flux:heading>
        <flux:text>
            {{ __('A return starts as a draft so you can review the numbers before committing them. The app reads your posted journal entries for the period and lists every line that contributes, so you can check the figures against what the agency expects. The lines come from the agency’s payable account — the account each of its tax codes collects into — so the agency you pick must have one. Agencies are set up under Settings → Lists → Tax codes, where a new agency gets a payable account created for it automatically; see') }}
            <a class="underline" href="{{ route('docs.lists') }}" wire:navigate>{{ __('Lists') }}</a>{{ __('.') }}
        </flux:text>

        <p><strong>{{ __('To file a tax return:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the Tax returns list and select File new return.') }}</li>
            <li>{{ __('The Return # is filled in for you — TR-000001, then counting up. You can type your own, but it must be unique within the organization — a duplicate is refused when you save.') }}</li>
            <li>{{ __('Choose the Tax agency you are filing with. Only active agencies are listed.') }}</li>
            <li>{{ __('Set the Period start and Period end. The form defaults to the previous calendar quarter; change the dates to match your filing period. A click anywhere in a date field opens the calendar.') }}</li>
            <li>{{ __('Review the Preview. The Collected, Paid (ITCs), and Net owing tiles total the lines below, and the table lists each one with its Date, Entry #, Document, Bucket, and Amount. Tax on an invoice is Collected; tax on a bill or cheque is Paid — an input tax credit; anything else — a sales receipt, an expense, a credit memo, a manual journal entry — is classified by which side of the payable account it hit. An empty period reads “No transactions in this period for this agency.”') }}</li>
            <li>{{ __('Uncheck Include on any line you want to leave out — for example an imported opening-balance line that belongs to an earlier filing. Excluded lines fade out and the tiles update as you toggle them.') }}</li>
            <li>{{ __('Add an optional Filing reference (the government confirmation number) and any Notes.') }}</li>
            <li>{{ __('Select Save draft to come back to it later, or File return and confirm to lock in the snapshot. Either way you land on the return’s page, where a saved draft can also be filed later.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/tax-returns/return-form.png') }}"
            alt="{{ __('The File a tax return form showing the Tax agency, Return #, Period start, and Period end fields, the Filing reference and Notes fields, and the Preview with its Collected, Paid (ITCs), and Net owing tiles above the line-by-line table with Include checkboxes, plus the Save draft and File return buttons') }}"
            caption="{{ __('The File a tax return form. The Preview recalculates as you change the agency or dates; uncheck a line to leave it out of the totals and the filed snapshot.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('What filing does') }}">
            {{ __('Filing has no effect on your ledger — it is record-keeping only. A draft stores no figures of its own: every time you open it for editing, the Preview recalculates from your journal entries, and filing recalculates once more before it captures the contributing lines as a permanent, audit-ready snapshot. From then on the period is locked for that agency: posting, editing, or back-dating a transaction that uses that agency’s tax codes inside the filed period is refused, so the snapshot stays a faithful record of what you reported.') }}
        </x-docs.callout>

        <x-docs.callout type="warning">
            {{ __('You cannot file two returns that overlap for the same agency. If a filed return already covers part of the period, filing is refused — void the existing return first, or adjust your dates so the periods do not overlap. Only filed returns count, so overlapping drafts are allowed while you work.') }}
        </x-docs.callout>

        <x-docs.callout type="note" heading="{{ __('One person edits a record at a time') }}">
            {{ __('Only a saved draft is locked. If a teammate already has one open on the Edit form, you see who is editing instead of the form, and Owners and Admins can take over. File new return has no saved record yet, so it is never locked — two people can open it at once. The return’s page shows a banner naming who is editing it, with a Take over editing button for Owners and Admins, and while they hold the lock File return and Void are refused straight away with a message — try again once they are done. See') }}
            <a class="underline" href="{{ route('docs.settings') }}" wire:navigate>{{ __('Settings → Edit locks') }}</a>
            {{ __('for how a lock is held and released.') }}
        </x-docs.callout>

        {{-- ─────────────────────────── The return’s page ─────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('The return’s page') }}</flux:heading>
        <flux:text>
            {{ __('Selecting a Return # opens the return’s page, headed Tax return and its number, with the agency and period beneath. Badges show the status — Draft, Filed, or Void — plus Ref: with your filing reference once you have entered one, and a Filed stamp with the date, time, and who filed it. The buttons in the top-right corner depend on the status: a draft shows File return and an Actions menu holding Edit; a filed return shows Record payment (or Record refund, when the agency owes you) until a payment is posted, and an Actions menu holding Void.') }}
        </flux:text>
        <flux:text>
            {{ __('Below the header, three tiles show Collected, Paid (ITCs), and Net owing, and the table lists the snapshot lines with their Date, Entry #, Document, Bucket, and Amount. For a draft the tiles read 0.00 and the table says “No snapshot lines yet — file the return to capture them”, because nothing is stored until you file — open Actions → Edit to see the live Preview. Beneath the table the agency’s registration number appears when one is on file, followed by your Notes. Only a draft can be edited; a filed return is frozen, so to change one, void it and file a new return.') }}
        </flux:text>

        <x-docs.callout type="tip">
            {{ __('Every Entry # in the snapshot table is a link. It opens the invoice, bill, or cheque behind the line, or the journal entry itself for anything else — a sales receipt, an expense, a manual entry — so you can check a figure without leaving the return.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('Once you record a payment, a Payments table appears at the foot of the page: Date, Payment # (a link to the payment’s own page), Direction (Payment to agency or Refund from agency), Net, Add-ons (penalty, interest, and commission together), Total, and Status — Draft, Posted, or Void. A voided return keeps its page and its snapshot lines; a red banner below the Collected, Paid (ITCs), and Net owing tiles, just above the snapshot table, says “This return has been voided”, with the date, who voided it, and the reason.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/tax-returns/return-detail.png') }}"
            alt="{{ __('A filed tax return’s page showing the Filed and Ref badges, the Filed-by stamp, the Record payment button and Actions menu, the Collected, Paid (ITCs), and Net owing tiles, and the snapshot table with underlined Entry # links') }}"
            caption="{{ __('A filed return. Record payment is offered until a payment is posted; Actions holds Void. Each Entry # opens the document behind the line.') }}"
        />

        {{-- ──────────────────────────── Record a payment ─────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Record a payment') }}</flux:heading>
        <flux:text>
            {{ __('Once a return is filed, record the money you send the agency (or the refund you receive) so it clears the tax payable and moves through your bank. Payments can only be recorded against filed returns. When the net is owing the button reads Record payment and the form is headed Record tax payment; when the return is in a refund position it reads Record refund and the form is headed Record tax refund.') }}
        </flux:text>

        <p><strong>{{ __('To record a payment:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the filed return and select Record payment (or Record refund).') }}</li>
            <li>{{ __('The Payment # (TRP-000001 and counting, editable and unique like any document number) and the Payment date (today) are filled in for you. The Bank account defaults to your first bank account — change it if the money moves through a different one. Optionally pick a Payment method and type a Reference (the confirmation or tracking number).') }}</li>
            <li>{{ __('Under Amounts, the Net tax payment (Net tax refund on a refund) is pre-filled with the return’s net — adjust it if you are paying a different amount.') }}</li>
            <li>{{ __('If they apply, enter a Penalty, Interest paid, or Commission / processing fee. Each amount you enter reveals its own selector — Penalty account, Interest account, or Commission account — listing your expense accounts, and you must pick one before the payment can be recorded. On a refund the only extra is Interest received, which needs an Interest income account.') }}</li>
            <li>{{ __('Add Notes if you like — they appear on the bank register row for this payment.') }}</li>
            <li>{{ __('Check the Total moving through bank, then select Record payment (or Record refund) and confirm. It posts to your books immediately and opens the payment’s page.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/tax-returns/payment-form.png') }}"
            alt="{{ __('The Record tax payment form with the Payment #, Payment date, Bank account, and Payment method fields, the Reference field, the Amounts section with Net tax payment, Penalty and its Penalty account selector, Interest paid, and Commission / processing fee, the Notes box, and the Total moving through bank beside the Record payment button') }}"
            caption="{{ __('The Record tax payment form. Penalty, interest, and commission are optional — each reveals its own account selector once you enter an amount.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('What recording a payment does to your books') }}">
            {{ __('A payment to the agency debits the agency’s Tax Payable account for the net amount, debits the expense account you chose for any penalty, interest, or commission, and credits your bank for the total that leaves it. A refund reverses this: it debits your bank for the total received, credits Tax Payable for the net, and credits an income account for any interest received. The bank leg is labelled Tax payment (or Tax refund) followed by your Notes, so the bank register row says which remittance it was. Like any other transaction, a payment dated on or before your closing date is refused — see') }}
            <a class="underline" href="{{ route('docs.accounting') }}" wire:navigate>{{ __('Accounting → Period locking') }}</a>{{ __('.') }}
        </x-docs.callout>

        <flux:heading size="md" class="mt-6">{{ __('The payment record') }}</flux:heading>
        <flux:text>
            {{ __('Recording a payment lands on its own page, headed Tax payment (or Tax refund) and its number, with a link back to the return, the agency, and the date beneath. Badges show Posted, a GL entry link that opens the journal entry it created, and Ref: with your reference. Two cards show the Bank account (with the Method, if you picked one) and the Direction. The table beneath breaks the money down — Net tax remitted (or Net tax refunded), then Penalty, Interest paid or Interest received, and Commission, each with the code of the account it posted to — and totals it as Total through bank. A Posted stamp records when and by whom.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/tax-returns/payment-detail.png') }}"
            alt="{{ __('A posted tax payment’s page showing the Posted badge, the GL entry link, the Ref badge, the Bank account and Direction cards, the breakdown table with Net tax remitted and Penalty rows and the Total through bank, the Posted-by stamp, and the red Void button') }}"
            caption="{{ __('A posted tax payment. The GL entry badge opens the journal entry; Void posts a reversing entry and frees the return for a corrected payment.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('A return takes one payment at a time — while a posted payment exists, the Record payment button is hidden. Made a mistake? Open the payment and select Void: the app posts a reversing journal entry, marks the payment Void with a stamp of who voided it and when, and the return’s Record payment button comes back so you can record a corrected one.') }}
        </x-docs.callout>

        {{-- ──────────────────────────── Statuses ─────────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Statuses') }}</flux:heading>
        <flux:text>
            {{ __('A return moves through three statuses: Draft → Filed → Void. A draft can still be edited from Actions → Edit, and its numbers keep recalculating from your journal entries. Filing freezes the snapshot, locks the period for that agency, and unlocks Record payment. Voiding does not touch the ledger — it marks the return Void, keeps the snapshot rows for your audit trail, and unlocks the period so you can post in it again.') }}
        </flux:text>

        <p><strong>{{ __('To void a filed return:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the return and choose Void from the Actions menu.') }}</li>
            <li>{{ __('In the Void tax return dialog, type a Reason — it is required.') }}</li>
            <li>{{ __('Select Confirm void. The page confirms that the period is unlocked.') }}</li>
        </ol>

        <x-docs.callout type="warning">
            {{ __('A voided return cannot be reopened. To correct a filing, void it and file a new return for the period — the voided one no longer counts for the overlap check. Voiding a return leaves any payment posted against it in place; void the payment separately if it should not stand.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('Tax returns lean on the figures you can see any time on the') }}
            <a class="underline" href="{{ route('docs.reports') }}" wire:navigate>{{ __('Sales tax report') }}</a>{{ __(', which shows the tax collected and the input tax credits paid, ready for filing. See') }}
            <a class="underline" href="{{ route('docs.reports') }}" wire:navigate>{{ __('Reports') }}</a>
            {{ __('for the full list of financial statements and tax reports.') }}
        </flux:text>

        {{-- ──────────────────────── Related reports ──────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reports tied to this area') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Sales Tax — per-agency tax collected on sales versus input tax credits claimed on purchases, ready for filing. Its Download menu offers CSV, Excel, and PDF, and the per-agency drill-down has its own Download — tax returns themselves have no export or print, so this is where to get a file.') }}</li>
            <li>{{ __('Audit Logs — the Accounting tab records each return filed or voided and each payment posted (with a snapshot of its journal entry) or voided, with who did it and when.') }}</li>
            <li>{{ __('GIFI Statement, T2125 Business Activities, T5013 Partnership, and T3010 Summary — CRA income-tax figures for Canadian organizations, where applicable; Settings → Tax & filing tells you which.') }}</li>
        </ul>
        <flux:text>
            {{ __('Integrations can file and void returns and post payments through the API — see') }}
            <a class="underline" href="{{ route('docs.api') }}" wire:navigate>{{ __('API') }}</a>{{ __('.') }}
        </flux:text>
    </x-pages::docs.layout>
</section>
