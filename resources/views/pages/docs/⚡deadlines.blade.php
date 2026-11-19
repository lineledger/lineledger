<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Deadlines')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Deadlines')"
        :subheading="__('Keep the organization’s filing and payment due dates in one place: who looks after each one, when it was done, and what is coming up.')"
    >
        <flux:text>
            {{ __('Deadlines replaces the spreadsheet many bookkeepers keep for government filings. Each deadline is something the organization has to file or pay on a schedule: a GST/HST return, a PST remittance, payroll source deductions, a WCB report, corporate instalments, a licence renewal. LineLedger works out every due date from the schedule, reminds the person it is assigned to, and records who completed each one, when, for how much and with what confirmation number. Nothing here posts to the books. The examples below use Demo Company Inc.') }}
        </flux:text>

        <flux:text>
            {{ __('Open Deadlines from the sidebar. It sits under Dashboard, at the top, and shows a count of the deadlines assigned to you that are overdue (red) or due in the next week (amber).') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/deadlines/calendar.png') }}"
            alt="{{ __('The Deadlines page in Calendar view: a month grid with coloured chips for each due date, filters for assignee and category above it, and the New deadline, Add suggested deadlines and Email settings buttons') }}"
            caption="{{ __('The Calendar view. Each chip is one due date: red is overdue, amber is due within a week, grey is upcoming, green is done.') }}"
        />

        {{-- ───────────────────────── Getting started ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Add suggested deadlines') }}</flux:heading>
        <flux:text>
            {{ __('The quickest start is Add suggested deadlines. LineLedger reads the organization’s country, province or state, type, fiscal year and payroll settings, and proposes the deadlines it probably has. Each suggestion says why it was suggested and links to the official government page its rule comes from.') }}
        </flux:text>

        <p><strong>{{ __('To add suggested deadlines:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Deadlines and select Add suggested deadlines.') }}</li>
            <li>{{ __('Answer the few questions LineLedger can’t work out for itself, such as your GST/HST filing period, how often you file PST, or which months your VAT quarters end in. The suggestions update as you choose.') }}</li>
            <li>{{ __('Tick the deadlines that apply. The common ones are ticked for you; ones that depend on a threshold, such as instalments, are left for you to decide. Each row shows its schedule in words and the next due date.') }}</li>
            <li>{{ __('Choose who to assign them to, and the Track from date. Due dates before it are not added; set it to the start of your fiscal year to record filings you have already made.') }}</li>
            <li>{{ __('Select Add. Deadlines already added are marked and cannot be added twice.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/deadlines/suggestions.png') }}"
            alt="{{ __('The Add suggested deadlines page with the GST/HST filing period question, a checklist of suggestions grouped by agency, and the Assign them to and Track from fields at the bottom') }}"
            caption="{{ __('Suggestions for a Canadian organization with payroll. Each one links to its official source.') }}"
        />

        <x-docs.callout type="warning" heading="{{ __('Check them against your own requirements') }}">
            {{ __('Suggestions follow the general rules published by CRA, Revenu Québec, the provinces, HMRC, Companies House and the IRS. Your own due dates can differ: an agency may assign you a different filing period, a threshold may not apply, or you may have an extension. Change any deadline after adding it.') }}
        </x-docs.callout>

        {{-- ───────────────────────── A deadline’s schedule ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Create a deadline by hand') }}</flux:heading>
        <flux:text>
            {{ __('Select New deadline for anything the suggestions don’t cover: a licence renewal, an insurance policy, a regulator’s quarterly report. The form has three parts.') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li><strong>{{ __('What’s due') }}</strong> — {{ __('a name, a category, what has to happen (file a return, make a payment, both, or another task), the agency or payee, an account or reference number, the filing site’s address, a usual amount, and How to do it: the steps, reports to run and where to find the numbers. Don’t store passwords there.') }}</li>
            <li><strong>{{ __('When it’s due') }}</strong> — {{ __('how often it repeats, when the first period starts, and when each period is due: a number of months after the period ends, on the same day, a set day or the last day of the month, plus any extra days. A due date on a weekend can stay put or move to the Friday before or the Monday after. Statutory holidays aren’t known, so move those due dates by hand.') }}</li>
            <li><strong>{{ __('Who and reminders') }}</strong> — {{ __('who it is assigned to, and how many days before each due date to email them.') }}</li>
        </ul>
        <flux:text>
            {{ __('As you fill in the schedule, the form puts the rule into words and lists the next six due dates, so you can check it before saving. A few examples:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Quarterly GST/HST for a fiscal year starting August 1: quarterly from August 1, due 1 month after each period ends on the last day of the month. The August–October quarter is due November 30.') }}</li>
            <li>{{ __('A regulator’s quarterly report covering June 16 to September 15: quarterly from June 16, due 0 months after on the last day of the month, so September 30.') }}</li>
            <li>{{ __('Employer health tax instalments on June 15, September 15 and December 15: choose Set dates each year and add the three dates.') }}</li>
            <li>{{ __('UK VAT: quarterly, due 1 month after on the last day of the month, plus 7 days.') }}</li>
        </ul>

        <x-docs.figure
            src="{{ asset('docs/screenshots/deadlines/form.png') }}"
            alt="{{ __('The New deadline form with the schedule section filled in and the preview of the next six due dates below it') }}"
            caption="{{ __('The schedule section and its preview of the next due dates.') }}"
        />

        {{-- ───────────────────────── Views ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Four ways to look at them') }}</flux:heading>
        <flux:text>
            {{ __('Filter any view by who a deadline is assigned to (Everyone, Assigned to me, Unassigned or one person) and by category.') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li><strong>{{ __('Calendar') }}</strong> — {{ __('the month at a glance. Select a chip to open that due date. On a phone, the month is a list of the days that have something due.') }}</li>
            <li><strong>{{ __('List') }}</strong> — {{ __('what is still to do, grouped into Overdue, Due in the next 7 days, Due in the next 30 days and Later, each row with a Mark done button. Switch the status filter to see what is done or not required.') }}</li>
            <li><strong>{{ __('Year at a glance') }}</strong> — {{ __('one panel per deadline for the fiscal year, laid out like a filing spreadsheet: Period, Due, Done (with who completed it) and Amount. Done cells are green, overdue cells red.') }}</li>
            <li><strong>{{ __('All deadlines') }}</strong> — {{ __('every deadline with its schedule in words, who it is assigned to and when it is next due. Select one to open its page, where you can edit, pause, duplicate or archive it, and see every due date it has had.') }}</li>
        </ul>

        <x-docs.figure
            src="{{ asset('docs/screenshots/deadlines/year.png') }}"
            alt="{{ __('The Year at a glance view with panels for the GST/HST return and payroll source deductions, each listing periods, due dates, who completed them and the amounts') }}"
            caption="{{ __('Year at a glance: the fiscal year’s deadlines with who completed each one, when, and for how much.') }}"
        />

        {{-- ───────────────────────── Completing ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Mark a deadline done') }}</flux:heading>

        <p><strong>{{ __('To mark a deadline done:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Select the due date in any view, or Mark done in the list. The dialog shows the period, the agency, the reference number, a link to the filing site and the How to do it notes.') }}</li>
            <li>{{ __('Select Mark done. Enter the date it was done and who completed it (you, unless you change it).') }}</li>
            <li>{{ __('For a payment, enter the amount paid. Add the confirmation number, any notes, and attach the confirmation or receipt.') }}</li>
            <li>{{ __('Select Mark done to save. If someone else already marked it done, LineLedger says who and when instead.') }}</li>
        </ol>
        <flux:text>
            {{ __('From the same dialog you can mark a due date Not required (a period with no payroll, for example) with a reason, or Edit it to reassign it, move its due date for an extension, or add notes and files. A due date you edit keeps your changes when the deadline’s schedule is changed later. Reopen puts a done or not-required due date back on the list.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/deadlines/mark-done.png') }}"
            alt="{{ __('The Mark done dialog with Done on, Completed by, Amount paid and Confirmation number fields and a drop zone for the receipt') }}"
            caption="{{ __('Marking a payroll remittance done, with the amount, confirmation number and receipt.') }}"
        />

        {{-- ───────────────────────── Reminders ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reminders and the summary email') }}</flux:heading>
        <flux:text>
            {{ __('The person a deadline is assigned to gets an email on each of the reminder days the deadline asks for (7 days and 1 day before, unless you change them). If it goes past its due date without being marked done, they get an overdue email the next day and then weekly, up to four times. Unassigned deadlines send no individual reminders.') }}
        </flux:text>
        <flux:text>
            {{ __('Everyone who can open Deadlines can also get a summary email of what is overdue and coming up: their own deadlines, plus the unassigned ones if they can complete deadlines. It arrives on Monday mornings by default; under Email settings on the Deadlines page each person can switch it to every morning or off, and turn their reminders off. These settings are per person and per organization.') }}
        </flux:text>
        <flux:text>
            {{ __('The Dashboard also shows an Upcoming deadlines card with what is overdue or due in the next two weeks.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('Self-hosting') }}">
            {{ __('Emails go out from the scheduled deadlines:notify command, which runs hourly and processes each organization once a day from 07:00 in its own time zone. It also adds due dates as the schedule rolls forward, so the scheduler must be running. The') }}
            <a class="underline" href="{{ route('docs.self-hosting') }}" wire:navigate>{{ __('Self-hosting & upgrades') }}</a>
            {{ __('page describes the scheduler.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Access ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Who can see and do what') }}</flux:heading>
        <flux:text>
            {{ __('Deadlines has its own permission, under Reports in the role editor (Settings → Users and roles): View to open the page, Create to add deadlines, Modify to edit them and mark due dates done, and Delete to archive. Full Access, Accountant, Bookkeeper and External Accountant have it in full, View-only can look, and Payroll Manager can view and complete. A deadline can only be assigned to someone who can view and complete deadlines. Roles you customized before this feature arrived don’t have it until you add it. When someone leaves the organization, or loses access to Deadlines, their open due dates become unassigned; who completed past ones stays on record.') }}
        </flux:text>
    </x-pages::docs.layout>
</section>
