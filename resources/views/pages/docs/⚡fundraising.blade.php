<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Fundraising')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Fundraising')"
        :subheading="__('Record donations and grants, and issue official donation receipts.')"
    >
        <flux:text>
            {{ __('The Fundraising suite is where a non-profit or charity tracks the money it raises: donations (cash or gifts in kind), grants from funders, and — for registered charities — the official donation receipts donors need at tax time. Each record links to the journal entry it creates, so you can always trace a gift from the donor through to your financial statements. Fundraising is for non-profits only, so the examples below use our sample charity, Demo Community Society, rather than the for-profit Demo Company Inc.') }}
        </flux:text>

        <flux:text>
            {{ __('Fundraising is an opt-in module. An owner turns it on under Settings → Organizations → your organization: switch on Donations & grants under Features, and Donations and Grants appear in the sidebar. The same page’s Non-profit & charity card is where you choose your contribution accounting method (Deferral or Restricted fund), turn on Fund accounting, and enter your CRA charity registration number. Issuing official donation receipts is a separate capability that only unlocks once the company is a registered charity — a charity organization type, a Canadian jurisdiction, and a CRA registration number on file. Demo Community Society is set up this way.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('Funds: restricted vs unrestricted') }}">
            {{ __('Donations and grants can be tagged to a Fund so you can report on money the donor restricted to a specific purpose separately from your general, unrestricted giving. Set your funds up first under') }}
            <a class="underline" href="{{ route('docs.lists') }}" wire:navigate>{{ __('Settings → Lists → Funds') }}</a>
            {{ __('— the Fund picker then appears on the donation and grant forms whenever you mark a gift restricted. The Fund dimension is available only when your organization uses the restricted fund method (how Demo Community Society is set up, with a General Fund, a Building Fund, and an Endowment). Under the deferral method there is no per-gift fund tag; restricted gifts are held in a deferred-liability account instead.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Record a donation ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Record a donation') }}</flux:heading>
        <flux:text>
            {{ __('A donation records a gift your organization received — a cash contribution or a gift in kind. Recording one books the gift as revenue and moves the money (or the donated asset) onto your books. Donations start as drafts you can keep editing; posting locks the amounts into the ledger.') }}
        </flux:text>

        <p><strong>{{ __('To record a donation:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Donations from the sidebar, then select Record donation.') }}</li>
            <li>{{ __('Choose the Donor. Leave it on “Anonymous / no contact” for an unattributed gift; picking a contact flags them as a donor for next time.') }}</li>
            <li>{{ __('Pick the Gift type — Cash for money, or Gift in kind for donated goods or property recorded at fair market value.') }}</li>
            <li>{{ __('Set the Date and the Amount.') }}</li>
            <li>{{ __('In Deposit to / record against, choose the bank or undeposited-funds account for cash, or the asset account that receives an in-kind gift.') }}</li>
            <li>{{ __('Pick a Donation revenue account, or leave it on the default donation income account.') }}</li>
            <li>{{ __('Optionally switch on Restricted gift, then pick a Fund (restricted fund method), choose the deferred / restricted-liability account (deferral method), and note how the gift may be used.') }}</li>
            <li>{{ __('Registered charities can switch on Create an official donation receipt to spawn a linked draft receipt you can review and issue.') }}</li>
            <li>{{ __('Select Save draft.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/fundraising/donation-form.png') }}"
            alt="{{ __('The Record donation form showing the donor, gift type, amount, deposit-to account, and the restricted-gift switch') }}"
            caption="{{ __('The Record donation form. Marking the gift restricted reveals the Fund picker (restricted fund method); under the deferral method a deferred-liability account picker appears there instead.') }}"
        />

        <flux:text>
            {{ __('Saving leaves the donation as a draft you can keep editing. To record it on the books, open the donation and select Post. A posted donation shows a Void button that reverses its journal entry if you need to back the gift out.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('What posting does to your books') }}">
            {{ __('Posting a donation debits the deposit-to account (the bank, undeposited funds, or the asset receiving an in-kind gift) and credits donation revenue for the same amount. For a restricted gift under the deferral method it credits the deferred / restricted-liability account instead, holding the money until it is spent; under the restricted fund method it credits donation revenue tagged with the Fund. A receipt spawned from a cash donation carries no debit account, so issuing it never re-books the revenue — there is no double count.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('Open Donations from the sidebar to see the list. Each row shows the donation number, the donor, the amount, whether the gift is restricted, and its status. Use the search box and the status filter to narrow the list.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/fundraising/donations-list.png') }}"
            alt="{{ __('The Donations list showing donation numbers, donors, amounts, restriction badges, and statuses') }}"
            caption="{{ __('The Donations list. The Restricted badge marks gifts the donor tied to a specific purpose.') }}"
        />

        {{-- ───────────────────────── Record a grant ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Record a grant') }}</flux:heading>
        <flux:text>
            {{ __('A grant records an award from a funder — a foundation, a government program, or another organization. Grants are usually restricted to a purpose and recognized as revenue over the funding period rather than all at once. Like donations, a grant starts as a draft and posts its award to the ledger when you are ready.') }}
        </flux:text>

        <p><strong>{{ __('To record a grant:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Grants from the sidebar, then select New grant.') }}</li>
            <li>{{ __('Enter the Grant name and choose the Funder.') }}</li>
            <li>{{ __('Enter the Award amount.') }}</li>
            <li>{{ __('Set the Period start and Period end — the window the grant covers.') }}</li>
            <li>{{ __('In Deposit to / receivable account, choose where the money lands, and pick a Grant revenue account (or leave it on the default).') }}</li>
            <li>{{ __('Leave Restricted grant on for a purpose-restricted award. Under the restricted fund method a Fund picker appears; under the deferral method a deferred / restricted-liability account and a Recognition method appear instead.') }}</li>
            <li>{{ __('Under the deferral method, choose a Recognition method — Manual, or Straight-line over the period. Straight-line does not post on its own; it simply pre-fills each later recognition with an even per-period slice, which you still post yourself.') }}</li>
            <li>{{ __('Switch on Recognize a receivable on award if the funder has committed but not yet paid.') }}</li>
            <li>{{ __('Select Save draft, then open the grant and select Post award to record it on the books.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/fundraising/grant-form.png') }}"
            alt="{{ __('The New grant form showing the grant name, funder, award amount, period dates, and accounts') }}"
            caption="{{ __('The New grant form. The Fund picker shows under the restricted fund method; the deferred-account and recognition-method fields show under the deferral method.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('What posting does to your books') }}">
            {{ __('Posting a grant award debits the deposit-to or receivable account for the full award. For a restricted grant under the deferral method it credits the deferred / restricted-liability account, and the deferred balance waits there until you recognize it (see below). Under the restricted fund method, or for an unrestricted grant, it credits grant revenue right away — so the award is recognized in full at once and there is no deferred balance to release. The Grants list shows the remaining Deferred balance for each award so you can see at a glance how much is still to be recognized.') }}
        </x-docs.callout>

        {{-- ───────────────── Recognize deferred grant revenue ───────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Recognize deferred grant revenue') }}</flux:heading>
        <flux:text>
            {{ __('This step applies only under the deferral method. After you post a restricted award, the whole grant sits in the deferred-liability account; you move it into grant revenue yourself as you spend the funding. There is no scheduler that does this for you — recognition is always a deliberate action, even when you picked Straight-line. (Demo Community Society uses the restricted fund method, so its grants are recognized in full at award and skip this step entirely.)') }}
        </flux:text>

        <p><strong>{{ __('To recognize grant revenue:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open a posted, restricted grant that still has a Deferred balance. A Recognize revenue button appears in the top-right.') }}</li>
            <li>{{ __('Select Recognize revenue. The Amount is pre-filled with the straight-line slice for the period — accept it or type the amount you want to release.') }}</li>
            <li>{{ __('Set the Date and select Recognize.') }}</li>
        </ol>

        <x-docs.callout type="note" heading="{{ __('What recognizing does to your books') }}">
            {{ __('Each recognition debits the deferred / restricted-liability account and credits grant revenue, adds a row to the grant’s Revenue recognized table, and advances its Recognized-to-date and Deferred balance figures. The app refuses to recognize more than the award total. When the full award has been recognized the grant’s status flips from Active to Completed.') }}
        </x-docs.callout>

        <x-docs.callout type="warning">
            {{ __('Voiding a grant reverses both the original award entry and every revenue recognition posted against it, then keeps the voided grant on file for your audit trail. You can void from the grant’s page whenever it is posted.') }}
        </x-docs.callout>

        {{-- ───────────────────── Issue an official donation receipt ───────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Issue an official donation receipt') }}</flux:heading>
        <flux:text>
            {{ __('An official donation receipt is the tax receipt a donor uses to claim their gift. Issuing one is reserved for registered charities — the New receipt button and this whole area only appear once the company is set up as a registered charity. Each receipt carries its own serial number and freezes when issued, so the numbered audit trail the CRA expects stays intact.') }}
        </flux:text>

        <p><strong>{{ __('To issue an official donation receipt:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Donation receipts from the sidebar, then select New receipt. (Switching on “Create an official donation receipt” when you record a donation spawns one of these drafts for you.)') }}</li>
            <li>{{ __('Choose the Donor — the name and address snapshot fills in from the contact.') }}</li>
            <li>{{ __('Pick the Gift type and set the Gift date.') }}</li>
            <li>{{ __('Enter the Fair market value of the gift.') }}</li>
            <li>{{ __('If the donor received anything in return — a meal, a ticket, goods or services — enter its Advantage value and a description. The advantage reduces the amount the donor can claim.') }}</li>
            <li>{{ __('For a gift in kind, describe the property and add the appraiser’s name and the appraisal date (required for non-cash gifts over $1,000).') }}</li>
            <li>{{ __('Pick the Donation revenue account, and for an in-kind gift the asset / expense account to debit at fair market value.') }}</li>
            <li>{{ __('Select Save draft. The eligible amount shown on the receipt is the fair market value minus the advantage.') }}</li>
            <li>{{ __('When everything is correct, open the receipt and select Issue receipt. Issuing freezes the receipt, stamps it with its serial number, and posts any general-ledger entry.') }}</li>
            <li>{{ __('Once issued, select Print to open the official CRA receipt as a PDF you can send to the donor.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/fundraising/donation-receipt-form.png') }}"
            alt="{{ __('The New donation receipt form showing the donor, gift type, fair market value, advantage value, and revenue account') }}"
            caption="{{ __('The New donation receipt form. The eligible amount is the fair market value less any advantage given back to the donor.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('What issuing does to your books') }}">
            {{ __('Issuing a cash receipt posts no general-ledger entry — the money was already booked when you recorded the donation or the deposit, so posting again would double-count the revenue. An in-kind receipt is different: issuing it debits the asset / expense account you chose and credits donation revenue for the fair market value, putting the donated property on your books. Issuing also runs the CRA checks first — the eligible amount must be greater than zero, an advantage needs a description, and an in-kind gift needs its property description (plus an appraisal over $1,000).') }}
        </x-docs.callout>

        <x-docs.callout type="warning">
            {{ __('An issued receipt should never simply be deleted — the CRA requires that cancelled receipts be kept so the numbered sequence has no gaps. To cancel one, void it: the app reverses any in-kind ledger entry and keeps the voided receipt on file with its serial number. To correct an issued receipt, reissue it — that voids the original and opens a fresh draft on a new number that references the cancelled one.') }}
        </x-docs.callout>

        {{-- ──────────────────────── Related reports ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reports tied to this area') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Donations by donor — total giving from each donor over a date range.') }}</li>
            <li>{{ __('Donations by fund — gifts grouped by the fund they were restricted to.') }}</li>
            <li>{{ __('Grants summary — every grant with its award, recognized revenue, and deferred balance.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
