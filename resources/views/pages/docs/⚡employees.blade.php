<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Employees')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Employees')"
        :subheading="__('Track the people on your team for reimbursements, payroll, time off, the self-service portal, and sales-rep attribution.')"
    >
        <flux:text>
            {{ __('The Employees area is for tracking the people on your team. An employee is a contact record flagged as an employee, so they stay separate from your vendors and customers in selectors and reports. The same record is the shared backbone for everything you do with a team member: paying back out-of-pocket expenses, running payroll, tracking time off, the employee self-service portal, and crediting whoever closed a sale. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        <flux:text>
            {{ __('Open Employees from the sidebar to see the list. Each row shows the employee’s contact details and the balance owed to them — money the business still needs to reimburse. Use the search box to find someone quickly, or toggle “Show inactive” to include people who have left the team.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/employees/list.png') }}"
            alt="{{ __('The Employees list showing each employee, their contact details, and the amount owed to them') }}"
            caption="{{ __('The Employees list. The “Owed to employee” column is what the business still needs to reimburse.') }}"
        />

        {{-- ───────────────────────── Add an employee ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Add an employee') }}</flux:heading>
        <flux:text>
            {{ __('Set up an employee once so you can reimburse them, put them on payroll, and attribute sales to them later. Only a display name is required — fill in the rest as you have it.') }}
        </flux:text>

        <p><strong>{{ __('To add an employee:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Employees from the sidebar.') }}</li>
            <li>{{ __('Select New employee in the top-right corner.') }}</li>
            <li>{{ __('Enter a Display name — this is what you will pick from lists everywhere else in the app.') }}</li>
            <li>{{ __('Fill in any contact details you have: first and last name, email, and phone. Email is what the app uses to send a self-service portal invite.') }}</li>
            <li>{{ __('Optionally record a Job title and an Employee ID, plus a mailing address (street, city, province, postal code) and any notes.') }}</li>
            <li>{{ __('Leave Active on for a current team member, then select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/employees/employee-form.png') }}"
            alt="{{ __('The New employee form with fields for display name, contact details, job title, employee ID, and address') }}"
            caption="{{ __('The New employee form. Only a display name is required — job title, employee ID, address, and notes are all optional.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('When someone leaves the team, mark them inactive instead of deleting: they drop out of selectors but their reimbursement and payroll history stays intact for your records.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Reimbursements ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Reimbursements') }}</flux:heading>
        <flux:text>
            {{ __('When an employee pays for something out of pocket, you owe them back. Reimbursements are their own area under Employees — open Employees → Reimbursements from the sidebar. Each reimbursement is numbered automatically (REIM-…) and works just like a vendor bill, except the money is owed to a teammate instead of a supplier.') }}
        </flux:text>

        <p><strong>{{ __('To create a reimbursement:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Employees → Reimbursements, then select New reimbursement.') }}</li>
            <li>{{ __('Choose the Employee. The reimbursement number fills in automatically — adjust the Expense date and Pay by date if you need to, and add a Memo.') }}</li>
            <li>{{ __('On each line, enter a Description, pick the Expense account the cost belongs to, and enter the Quantity and Amount. The line total calculates as you type.') }}</li>
            <li>{{ __('Choose one or more Tax codes on the line if the expense included recoverable tax. You can override the tax on an individual line when it differs from the default.') }}</li>
            <li>{{ __('Select Post reimbursement to finalize it, or Save draft to keep working on it later.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/employees/reimbursements-list.png') }}"
            alt="{{ __('The Reimbursements list showing REIM-numbered reimbursements with their status and amounts') }}"
            caption="{{ __('The Reimbursements list. Filter by status — Draft, Posted, Partial, Paid, or Void — or search to find a specific one.') }}"
        />

        <x-docs.figure
            src="{{ asset('docs/screenshots/employees/reimbursement-form.png') }}"
            alt="{{ __('The New reimbursement form with employee, dates, and a line grid for description, expense account, quantity, amount, and tax') }}"
            caption="{{ __('The New reimbursement form. Each line points an out-of-pocket cost at the expense account it belongs to.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('What posting does to your books') }}">
            {{ __('Posting a reimbursement works exactly like a vendor bill — it debits the expense accounts on its lines and credits Accounts Payable in the employee’s name — so the money you owe a team member shows up alongside what you owe suppliers until you pay it.') }}
        </x-docs.callout>

        <p><strong>{{ __('To pay an employee back:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the posted reimbursement.') }}</li>
            <li>{{ __('Select Pay employee.') }}</li>
            <li>{{ __('Choose the bank account the money came from, confirm the amount and date, and save the payment.') }}</li>
        </ol>
        <flux:text>
            {{ __('A posted reimbursement shows its status, a link to the journal entry it created, and the balance still owed. The Actions menu also lets you Print it, Edit it, Void it, or delete a draft that has not been posted yet.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/employees/reimbursement-posted.png') }}"
            alt="{{ __('A posted reimbursement showing its Posted status, linked GL entry, line items, and the Pay employee button') }}"
            caption="{{ __('A posted reimbursement. The “Pay employee” button records the payment; the GL entry link opens the journal entry it created.') }}"
        />

        <x-docs.callout type="warning">
            {{ __('A posted reimbursement should not simply be deleted — that would leave a gap in your numbered REIM records. To cancel one, void it: the app reverses the ledger entry and keeps the voided reimbursement on file for your audit trail. Only drafts that were never posted can be deleted outright.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Payroll ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Put an employee on payroll') }}</flux:heading>
        <flux:text>
            {{ __('The same employee records feed payroll. To start paying someone, open Payroll → Employee setup and complete their profile — province of employment, pay basis and rate, TD1 claim amounts, and vacation handling. Payroll appears only when it is turned on for your company.') }}
        </flux:text>
        <flux:text>
            {{ __('Pay runs, CPP/EI deductions, remittances, and T4 / RL-1 slips all live in the Payroll area. See the ') }}<a class="underline" href="{{ route('docs.payroll') }}" wire:navigate>{{ __('Payroll guide') }}</a>{{ __(' for the full walkthrough.') }}
        </flux:text>

        {{-- ───────────────────────── Time off ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Time off and the staff calendar') }}</flux:heading>
        <flux:text>
            {{ __('Time-off policies, time-off requests, and the staff calendar live under the Payroll area, where you can see who is away at a glance and approve requests. Each employee’s vacation and accrual balances tie back to the same employee record you set up here. The ') }}<a class="underline" href="{{ route('docs.payroll') }}" wire:navigate>{{ __('Payroll guide') }}</a>{{ __(' covers them in detail.') }}
        </flux:text>

        {{-- ───────────────────────── Self-service portal ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('The employee self-service portal') }}</flux:heading>
        <flux:text>
            {{ __('Each company has an employee self-service portal — a separate, employee-facing site where your team can see their own pay information without you sending it by hand. Employees sign in to their own area; they never see your books, other employees, or anything beyond their own records.') }}
        </flux:text>
        <flux:text>
            {{ __('Once signed in, an employee can review their year-to-date gross pay, deductions, and net pay; check their vacation and accrual balances; download pay-statement PDFs and finalized T4 / RL-1 slips; request time off; log and submit their hours; and update their own mailing address, TD1 claim amounts, and portal password.') }}
        </flux:text>

        <p><strong>{{ __('To invite an employee to the portal:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Make sure the employee has an email address and an active profile.') }}</li>
            <li>{{ __('Open Payroll → Employee setup and open the employee’s profile.') }}</li>
            <li>{{ __('Select Send portal invite.') }}</li>
        </ol>
        <flux:text>
            {{ __('The invite emails a sign-in link. Signing in is passwordless by default — the employee receives a one-time magic link each time — and they can optionally set a portal password from Edit my info for faster access.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/employees/portal-dashboard.png') }}"
            alt="{{ __('The employee self-service portal dashboard showing year-to-date pay, vacation balance, and downloadable pay statements') }}"
            caption="{{ __('The employee self-service portal. Employees see their own pay, balances, and documents — and nothing else.') }}"
        />

        <x-docs.callout type="note">
            {{ __('No banking or direct-deposit details are ever collected in the portal. Employees can update their address and TD1 amounts, but bank information stays out of the self-service area entirely.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Sales-rep attribution ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Sales-rep attribution') }}</flux:heading>
        <flux:text>
            {{ __('Employees can also be credited for sales. When the Sales rep field is turned on under Settings → Invoices, you can assign an employee as the sales rep on an invoice or other sales document, then credit and report on who closed the work.') }}
        </flux:text>

        <x-docs.callout type="note">
            {{ __('The whole Employees area — including reimbursements — can be hidden for companies that do not need it, under your company’s feature settings. Access is also governed by the Employees section permission, so you can let some team members manage people and reimbursements while keeping it out of view for everyone else.') }}
        </x-docs.callout>
    </x-pages::docs.layout>
</section>
