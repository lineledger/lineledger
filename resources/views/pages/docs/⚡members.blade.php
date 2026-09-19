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
            {{ __('Roster Members are the people who belong to your organization and pay dues — the contacts you track here. They are different from your organization’s Team members, which are the users who can sign in to LineLedger.') }}
            <a class="underline" href="{{ route('docs.settings') }}" wire:navigate>{{ __('Settings → Team and permissions') }}</a>
            {{ __('covers those sign-in accounts.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('The Members area is where you keep your membership roster — who belongs to your organization, what level they hold, and the dues they owe. Each member record links to a contact and to a full history of dues invoices, so you can always answer "is this member paid up?" from one place. Membership is usually a non-profit or association need, so the examples below use our sample charity, Demo Community Society — the demo organization with Membership switched on — rather than the for-profit Demo Company Inc.') }}
        </flux:text>

        {{-- ───────────────────────── Turn it on ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Turn on membership tracking') }}</flux:heading>
        <flux:text>
            {{ __('Members is an optional feature, so it stays out of the way for businesses that do not need it. To switch it on, open Settings → Organizations, open the organization, and turn on Membership under Features. You can also tick the same Membership switch while creating a new organization in the setup wizard. Either way, a Members item then appears in the sidebar — inside the Sales group, which is labelled Revenues for a non-profit organization — for every team member who has access to the Customers section.') }}
        </flux:text>

        <flux:text>
            {{ __('Next, set up at least one Membership level under Settings → Lists → Membership levels. A level carries the default dues, the billing frequency, the revenue account dues are booked to, and optional default payment terms and tax code. Members inherit those defaults, so you only configure them once. See') }}
            <a class="underline" href="{{ route('docs.lists') }}" wire:navigate>{{ __('Lists') }}</a>
            {{ __('for how to create one. Demo Community Society has two levels: Individual at $50 a year and Family at $90 a year.') }}
        </flux:text>

        <flux:text>
            {{ __('Open Members from the sidebar to see the roster. Each row shows the member number, the contact name, their level, a status badge, the expiry date, and the open dues still owed. Search by member number or name, and tick Show inactive to include members you no longer track — they appear dimmed so you can tell them apart.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/members/list.png') }}"
            alt="{{ __('The Members roster on Demo Community Society showing four members with their member numbers, levels, status badges, expiry dates, and open dues') }}"
            caption="{{ __('The Members roster. Search by member number or name, and tick “Show inactive” to include past members.') }}"
        />

        {{-- ───────────────────────── Add a member ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Add a member') }}</flux:heading>
        <flux:text>
            {{ __('Every member is tied to a contact. You can create a brand-new contact as you add the member, or pick someone who already exists in your contacts.') }}
        </flux:text>

        <p><strong>{{ __('To add a member:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Members from the sidebar, then select New member in the top-right corner.') }}</li>
            <li>{{ __('Under Contact, choose New contact to enter their Name, Company name, Email, Phone, and Address, or Existing contact to pick someone you already have from the Select contact list.') }}</li>
            <li>{{ __('Choose a Membership level. The selector shows each level’s default dues so you can see what the member will be billed. (You can leave it on “No level”, but a level is required before you can bill dues.)') }}</li>
            <li>{{ __('Set the Joined, Term start, and Expires dates. Joined and Term start default to today; leave Expires blank for an open-ended or lifetime membership. A click anywhere in a date field opens the calendar.') }}</li>
            <li>{{ __('Optionally enter a Dues override to bill a different amount than the level default — leave it blank to use the level’s dues.') }}</li>
            <li>{{ __('Turn on Auto-renew to generate dues invoices automatically each billing period (see below).') }}</li>
            <li>{{ __('Add any Notes, leave Active on, and select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/members/member-form.png') }}"
            alt="{{ __('The New member form with the New contact / Existing contact switch, contact and address fields, Membership level, the Joined, Term start and Expires dates, Dues override, Auto-renew, Notes, and Active') }}"
            caption="{{ __('The New member form. Pick a level to see its default dues, then adjust the dates and dues only if you need to.') }}"
        />

        <flux:text>
            {{ __('When you save a new member, the app assigns the next member number automatically — it uses a MEM- prefix with a six-digit sequence, so your first member becomes MEM-000001. You never type the number yourself, and it stays with the member for life.') }}
        </flux:text>

        <x-docs.callout type="note">
            {{ __('Every member is also a customer, so their dues can be invoiced like any other sale — saving a member flags the underlying contact as both a member and a customer for you. A contact can hold only one membership per organization, so the same person cannot be added to the roster twice.') }}
        </x-docs.callout>

        {{-- ───────────────────── The member detail page ───────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('The member detail page') }}</flux:heading>
        <flux:text>
            {{ __('Select a member from the roster to open their detail page. The top card summarizes the Joined, Term start, and Expires dates and the effective Dues, with a status badge beside the name and the member number and level underneath. Below that, the Dues invoices table lists every dues invoice raised for the member, with its date, status, total, and remaining balance — each links to the full invoice.') }}
        </flux:text>

        <flux:text>
            {{ __('Three actions sit in the top-right corner: Edit reopens the member form, Renew rolls the term forward (below), and Bill dues now raises a dues invoice on the spot.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/members/member-detail.png') }}"
            alt="{{ __('A member detail page showing the status badge, the Edit, Renew, and Bill dues now actions, the Joined, Term start, Expires and Dues card, the auto-renew note, and the Dues invoices table') }}"
            caption="{{ __('A member detail page. The Dues invoices table is the running record of everything the member has been billed.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('One person edits a member at a time') }}">
            {{ __('While a teammate has a member’s Edit form open, the member’s page shows who is editing, Renew waits until they are done, and anyone else who opens the form sees who is editing instead of it. Owners and Admins can take over. The Membership levels dialog under Settings → Lists works the same way. See') }}
            <a class="underline" href="{{ route('docs.settings') }}" wire:navigate>{{ __('Settings → Edit locks') }}</a>
            {{ __('for the full rules.') }}
        </x-docs.callout>

        <p><strong>{{ __('To bill dues on demand:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the member and select Bill dues now.') }}</li>
            <li>{{ __('The app creates a draft dues invoice dated today — one line for the member’s dues (their Dues override, or the level’s default if there is none), using the level’s revenue account, tax code, and default payment terms — and drops you straight into the editable draft.') }}</li>
            <li>{{ __('Adjust the lines, or the invoice number, if you need to (the app checks that a number you type is not already in use), then select Post invoice to finalize it.') }}</li>
        </ol>

        <flux:text>
            {{ __('Bill dues now needs the member to hold a level that has a revenue account, and the member’s dues must come to more than zero — that is their Dues override if they have one, otherwise the level’s default. If anything is missing, the app tells you what to fix instead of creating an empty invoice.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('What posting a dues invoice does to your books') }}">
            {{ __('A dues invoice is an ordinary sale: posting it debits Accounts Receivable for the total and credits the membership level’s revenue account, with any sales tax broken out to its own account. Everything downstream is the normal customer flow — the send dialog on a dues invoice reads Send invoice to member, you receive the payment exactly as you would for any customer, clearing the balance from Accounts Receivable, and you can send the member a statement from the Customers list. See') }}
            <a class="underline" href="{{ route('docs.customers') }}" wire:navigate>{{ __('Customers') }}</a>
            {{ __('for sending, payments, and statements.') }}
        </x-docs.callout>

        {{-- ───────────────────── Renew a membership ───────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Renew a membership') }}</flux:heading>
        <flux:text>
            {{ __('Select Renew on a member to roll their term forward by one billing period — the level’s billing frequency, or one year if the member has no level. If the member has an Expires date, Term start moves to that date and Expires moves one period past it, so renewing early never shortens the current term. For an open-ended membership with no Expires date, both dates are worked out from today instead: Term start becomes today and Expires lands one period later. A confirmation tells you the new end date (“Membership renewed through …”). Renewing only updates the term — bill the dues for the new period with Bill dues now, or let auto-renew handle it.') }}
        </flux:text>

        {{-- ───────────────────── Retire or reactivate ───────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Retire or reactivate a member') }}</flux:heading>
        <flux:text>
            {{ __('There is no delete or cancel button for a member — their dues invoices are part of your books, so the record stays. When someone leaves, open the member, select Edit, turn off the Active switch, and Save. An inactive member drops off the Members roster unless you tick Show inactive (they appear dimmed when you do), is left out of the Membership Roster report while its Active only box is ticked, and has their auto-renew schedule paused so no further dues invoices are generated. Nothing else changes: their contact, member number, and invoice history all stay put.') }}
        </flux:text>

        <flux:text>
            {{ __('To bring a member back, edit them and turn Active on again. If Auto-renew was left on, their dues schedule resumes on the same save.') }}
        </flux:text>

        {{-- ───────────────────── Membership status ───────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Membership status') }}</flux:heading>
        <flux:text>
            {{ __('Each member shows a status badge that the app works out from the term dates — it is never something you set by hand, so it is always current:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Active — the term has not ended yet (Expires is today or later), or the membership is open-ended with no expiry date.') }}</li>
            <li>{{ __('Lapsed — the term ended within the last 30 days. This is the grace window for a renewal nudge before the membership is treated as fully expired.') }}</li>
            <li>{{ __('Expired — the term ended more than 30 days ago.') }}</li>
        </ul>

        <flux:text>
            {{ __('The badge describes the term, not whether you still track the member. The Active switch on the member form (see Retire or reactivate a member, above) decides whether they appear on the roster at all, and a retired member keeps whichever badge their dates give them.') }}
        </flux:text>

        {{-- ───────────────────── How auto-renew works ───────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('How auto-renew works') }}</flux:heading>
        <flux:text>
            {{ __('Auto-renew bills a member their dues each term without you having to remember. It needs four things to be true at once: Auto-renew is on, the member is Active, they hold a level with a revenue account, and their dues come to more than zero. When they are, saving the member sets up a recurring invoice for that member, named “Membership renewal —” followed by the level name. The app then generates a fresh dues invoice from it at the start of each new term — the first one on the member’s current Expires date (or their Term start, or today, if there is no expiry), then every billing period after that at the level’s billing frequency, with no end date. Those invoices land as drafts, linked to the member and listed in their Dues invoices table, ready for you to review and post.') }}
        </flux:text>

        <flux:text>
            {{ __('When auto-renew is running, the member detail page tells you the date the next dues invoice will generate. Turning Auto-renew off — or turning the member’s Active switch off — pauses the schedule rather than deleting it: on the Recurring list it shows a Needs attention badge, and opening the schedule shows a Paused — needs attention warning with the reason “Membership auto-renew turned off.” Switch Auto-renew (and Active) back on and the same schedule resumes on save, without you rebuilding anything.') }}
        </flux:text>

        <flux:text>
            {{ __('The schedule itself is an ordinary recurring invoice. Open Revenues → Recurring (Sales → Recurring on a for-profit organization) to see its frequency, next run date, how many invoices it has generated, the dues line it will bill, and every invoice it has produced. Generate now on that page creates the next dues invoice immediately instead of waiting for the scheduled date. See') }}
            <a class="underline" href="{{ route('docs.recurring') }}" wire:navigate>{{ __('Recurring') }}</a>
            {{ __('for how schedules run.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/members/auto-renew-schedule.png') }}"
            alt="{{ __('The recurring invoice page for “Membership renewal — Individual” showing the Frequency, Next run, Generated and Status cards and the single Membership dues template line') }}"
            caption="{{ __('A member’s auto-renew schedule under Revenues → Recurring. It is a normal recurring invoice, but the member record rebuilds it on every save.') }}"
        />

        <x-docs.callout type="warning" heading="{{ __('The member record owns this schedule') }}">
            {{ __('Each time you save the member, the app rebuilds their dues schedule from the level and the member: the name, memo, frequency, start date, and the dues line are all reset, When each invoice is generated goes back to Save as a draft for review, and Runs on goes back to a specific day of the month. Anything you changed on the schedule’s own edit form — an automatic post or email setting, a last-day or last-business-day anchor, an extra line — is overwritten the next time that member is saved. Change the dues or the cadence on the level or the member instead. If a teammate has the schedule open for editing when the member is saved, their page tells them the record changed and asks them to reload rather than saving over the new values.') }}
        </x-docs.callout>

        {{-- ──────────────────────── Related reports ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reports tied to this area') }}</flux:heading>
        <flux:text>
            {{ __('Both reports live under Reports → Membership and only appear once membership tracking is on. See') }}
            <a class="underline" href="{{ route('docs.reports') }}" wire:navigate>{{ __('Reports') }}</a>
            {{ __('for how the reports area works.') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Membership Roster — every member with their Member #, Name, Level, Status, Joined and Expires dates, and Open dues. Active only is ticked by default; untick it to include retired members. Export to Excel or PDF — both exports add a Total row for Open dues at the bottom — or bundle it into a Management report package, where it appears as “Membership List”.') }}</li>
            <li>{{ __('Dues Revenue by Level — dues income grouped by membership level over a From / To range that defaults to the current calendar year, with an invoice count per level. It counts dues invoices that are posted, partially paid, or paid, groups members who have no level under “No level”, and is an on-screen report with no export.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
