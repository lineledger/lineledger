<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Members')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Members')"
        :subheading="__('Track your membership roster, dues, renewals, and dues revenue.')"
    >
        <x-docs.callout type="note">
            {{ __('Roster Members are the people who belong to your organization and pay dues — the contacts you track here. They are different from company Team members, which are the users who can sign in to LineLedger.') }}
            <a class="underline" href="{{ route('docs.settings') }}" wire:navigate>{{ __('Settings → Team and permissions') }}</a>
            {{ __('covers those sign-in accounts.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('The Members area is where you keep your membership roster — who belongs to your organization, what level they hold, and the dues they owe. Each member record links to a contact and to a full history of dues invoices, so you can always answer "is this member paid up?" from one place. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        {{-- ───────────────────────── Turn it on ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Turn on membership tracking') }}</flux:heading>
        <flux:text>
            {{ __('Members is an optional feature, so it stays out of the way for businesses that do not need it. To switch it on, open Settings → Organizations, open the organization, and turn on Membership under Features. A Members item then appears in the sidebar.') }}
        </flux:text>

        <flux:text>
            {{ __('Next, set up at least one Membership level under Settings → Lists → Membership levels. A level carries the default dues, the billing frequency, the revenue account dues are booked to, and optional default payment terms and tax code. Members inherit those defaults, so you only configure them once. See') }}
            <a class="underline" href="{{ route('docs.lists') }}" wire:navigate>{{ __('Lists') }}</a>
            {{ __('for how to create one.') }}
        </flux:text>

        <flux:text>
            {{ __('Open Members from the sidebar to see the roster. Each row shows the member number, the contact name, their level, a status badge, the expiry date, and the open dues still owed. Search by member number or name, and toggle "Show inactive" to include members you no longer track.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/members/list.png') }}"
            alt="{{ __('The Members roster showing member numbers, levels, status badges, and open dues') }}"
            caption="{{ __('The Members roster. Search by member number or name, and toggle “Show inactive” to include past members.') }}"
        />

        {{-- ───────────────────────── Add a member ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Add a member') }}</flux:heading>
        <flux:text>
            {{ __('Every member is tied to a contact. You can create a brand-new contact as you add the member, or pick someone who already exists in your contacts.') }}
        </flux:text>

        <p><strong>{{ __('To add a member:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Members from the sidebar, then select New member in the top-right corner.') }}</li>
            <li>{{ __('Choose New contact to enter their name, company, email, phone, and address, or Existing contact to pick someone you already have.') }}</li>
            <li>{{ __('Choose a Membership level. The selector shows each level’s default dues so you can see what the member will be billed. (You can leave it on “No level”, but a level is required before you can bill dues.)') }}</li>
            <li>{{ __('Set the Joined, Term start, and Expires dates. Joined and Term start default to today; leave Expires blank for an open-ended or lifetime membership.') }}</li>
            <li>{{ __('Optionally enter a Dues override to bill a different amount than the level default — leave it blank to use the level’s dues.') }}</li>
            <li>{{ __('Turn on Auto-renew to bill dues automatically each term (see below).') }}</li>
            <li>{{ __('Add any Notes, leave Active on, and select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/members/member-form.png') }}"
            alt="{{ __('The New member form with contact, membership level, dates, dues, and auto-renew fields') }}"
            caption="{{ __('The New member form. Pick a level to see its default dues, then adjust the dates and dues only if you need to.') }}"
        />

        <flux:text>
            {{ __('When you save a new member, the app assigns the next member number automatically — it uses a MEM- prefix with a six-digit sequence, so your first member becomes MEM-000001. You never type the number yourself, and it stays with the member for life.') }}
        </flux:text>

        <x-docs.callout type="note">
            {{ __('Every member is also a customer, so their dues can be invoiced like any other sale — saving a member flags the underlying contact as both a member and a customer for you. A contact can hold only one membership per company, so the same person cannot be added to the roster twice.') }}
        </x-docs.callout>

        {{-- ───────────────────── The member detail page ───────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('The member detail page') }}</flux:heading>
        <flux:text>
            {{ __('Select a member from the roster to open their detail page. The top card summarizes the Joined, Term start, and Expires dates and the effective dues, with a status badge beside the name. Below that, the Dues invoices table lists every dues invoice raised for the member, with its date, status, total, and remaining balance — each links to the full invoice.') }}
        </flux:text>

        <flux:text>
            {{ __('Three actions sit in the top-right corner: Edit reopens the member form, Renew rolls the term forward (below), and Bill dues now raises a dues invoice on the spot.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/members/member-detail.png') }}"
            alt="{{ __('A member detail page showing the summary card, status badge, Edit, Renew, and Bill dues now actions, and the dues invoices table') }}"
            caption="{{ __('A member detail page. The Dues invoices table is the running record of everything the member has been billed.') }}"
        />

        <p><strong>{{ __('To bill dues on demand:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the member and select Bill dues now.') }}</li>
            <li>{{ __('The app creates a draft dues invoice — one line for the level’s dues, using its revenue account and tax code — and drops you straight into the editable draft.') }}</li>
            <li>{{ __('Adjust the lines if you need to, then post the invoice to finalize it.') }}</li>
        </ol>

        <flux:text>
            {{ __('Bill dues now needs a level that has a revenue account and a dues amount greater than zero. If either is missing, the app tells you what to fix instead of creating an empty invoice.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('What posting a dues invoice does to your books') }}">
            {{ __('A dues invoice is an ordinary sale: posting it debits Accounts Receivable for the total and credits the membership level’s revenue account, with any sales tax broken out to its own account. When the member pays, you receive the payment exactly as you would for any customer, clearing the balance from Accounts Receivable.') }}
        </x-docs.callout>

        {{-- ───────────────────── Renew a membership ───────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Renew a membership') }}</flux:heading>
        <flux:text>
            {{ __('Select Renew on a member to roll their term forward by one billing period. The app moves Term start to the old expiry date, sets a new Expires date one period later (using the level’s billing frequency, or annually if the member has no level), and clears any cancellation so the membership reads as Active again. Renewing only updates the term — bill the dues for the new period with Bill dues now, or let auto-renew handle it.') }}
        </flux:text>

        {{-- ───────────────────── Membership status ───────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Membership status') }}</flux:heading>
        <flux:text>
            {{ __('Each member shows a status badge that the app works out from the term dates and any cancellation — it is never something you set by hand, so it is always current:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Active — the term has not ended yet, or the membership is open-ended (no expiry date).') }}</li>
            <li>{{ __('Lapsed — the term ended within the last 30 days. This is the grace window for a renewal nudge before the membership is treated as fully expired.') }}</li>
            <li>{{ __('Expired — the term ended more than 30 days ago.') }}</li>
            <li>{{ __('Cancelled — the membership was cancelled, regardless of the dates.') }}</li>
        </ul>

        {{-- ───────────────────── How auto-renew works ───────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('How auto-renew works') }}</flux:heading>
        <flux:text>
            {{ __('Auto-renew bills a member their dues each term without you having to remember. When you turn it on — and the member’s level has both a dues amount and a revenue account — the app sets up a recurring dues invoice for that member. The schedule then generates a fresh dues invoice automatically at the start of each term, using the level’s billing frequency. Those invoices land as drafts and show up in the member’s Dues invoices table, ready for you to review and post.') }}
        </flux:text>

        <flux:text>
            {{ __('When auto-renew is on, the member detail page tells you the date the next dues invoice will generate. Turning Auto-renew off pauses that recurring invoice — it is not deleted. The member stops being billed automatically, but the schedule stays on file, so switching Auto-renew back on later resumes billing without you rebuilding anything.') }}
        </flux:text>

        {{-- ──────────────────────── Related reports ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reports tied to this area') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Membership Roster — every member with their level, status, joined and expiry dates, and open dues. Filter to active members only, and export to Excel or PDF.') }}</li>
            <li>{{ __('Dues Revenue by Level — posted dues income grouped by membership level over a date range, with an invoice count per level.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
