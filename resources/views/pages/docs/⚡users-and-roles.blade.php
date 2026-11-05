<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Users and roles')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Users and roles')"
        :subheading="__('Who can do what in an organization: the Owner, roles, and the permissions each role grants.')"
    >
        <flux:text>
            {{ __('Every organization has one Owner and any number of other users. The Owner always has full access and holds no roles. Everyone else holds one or more roles, and may do whatever any of their roles allows. Roles are managed under Settings → Users and roles. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        {{-- ───────────────────────────── Who's who ──────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('The Owner and everyone else') }}</flux:heading>
        <flux:text>
            {{ __('The Owner, usually the person who created the organization, can open every page and do everything, and nobody can take that away. On the Users list they appear first, badged Owner · Full access, with no Roles or remove button.') }}
        </flux:text>
        <flux:text>
            {{ __('Everyone else holds at least one role. When someone holds several, the most permissive wins: for each activity they get every action that any of their roles allows. In Demo Company Inc., Priya Natarajan holds Accounts Receivable, Marc Tremblay holds Bookkeeper, and Dana Whitfield holds both Sales and a custom Front desk role.') }}
        </flux:text>

        {{-- ─────────────────────── Areas, activities, actions ─────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Areas, activities, and actions') }}</flux:heading>
        <flux:text>
            {{ __('Permissions follow the layout of the app. There are 13 areas — Customers, Vendors, Employees, Payroll, Inventory, Accounting, Banking, Inbox, Fundraising, Reports, Lists, Documents, and Settings — and each area holds a handful of activities, 67 in all: Invoices and Receive payments under Customers, Bills and Bill payments under Vendors, Pay runs under Payroll, Chart of accounts and Journal entries under Accounting, and so on.') }}
        </flux:text>

        <p><strong>{{ __('For each activity, a role grants one of three levels of access:') }}</strong></p>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('None — the activity is out of reach: its pages are left out of the sidebar, and the app refuses them even by direct link.') }}</li>
            <li>{{ __('Full — every action the activity offers.') }}</li>
            <li>{{ __('Partial — only the actions you tick: View, Create, Modify, Delete, Print, and View balance. Each activity offers only the actions that make sense for it, and ticking any action also gives View.') }}</li>
        </ul>

        <flux:text>
            {{ __('What a role cannot do is not offered. The sidebar leaves out the pages a person cannot open, and buttons such as New, Edit, Void, and Delete are hidden when their roles do not allow them.') }}
        </flux:text>

        <flux:heading size="md" class="mt-6">{{ __('Administrative and Owner-only permissions') }}</flux:heading>
        <flux:text>
            {{ __('A few activities are administrative, and by default only Full Access has them, besides the Owner: Organization settings, Closing date, Changes in a closed period, Take over someone else’s edit, API keys, Invitations, and All document folders.') }}
        </flux:text>
        <flux:text>
            {{ __('Four more are Owner-only by default: Opening balances, Audit log, Users, and Roles. No predefined role grants them, but you can give them to a custom role; the role editor badges them Owner-only by default. Deleting the organization, connecting Stripe, and backups always stay with the Owner.') }}
        </flux:text>

        {{-- ───────────────────────── Predefined roles ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('The predefined roles') }}</flux:heading>
        <flux:text>
            {{ __('Every organization starts with 15 predefined roles. You can change what any of them allows and put it back later with Reset to default, but you cannot rename or delete one. To make a role of your own from one of them, use Duplicate.') }}
        </flux:text>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Role') }}</flux:table.column>
                <flux:table.column>{{ __('What it covers') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                <flux:table.row>
                    <flux:table.cell>{{ __('Full Access') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Everything except opening balances, the audit log, and managing users and roles, which stay with the owner.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Accountant') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Every working area — sales, purchases, banking, accounting, payroll and reports — but not organization settings.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('External Accountant') }}</flux:table.cell>
                    <flux:table.cell>{{ __('For an outside accountant: full accounting, reconciliation and reports, and read-only access to everything else except payroll setup.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Bookkeeper') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Day-to-day bookkeeping across sales, purchases, banking, accounting and reports, without payroll.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Accounts Receivable') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Customers, invoices, payments received and deposits, with sales reports.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Sales') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Customers, estimates, sales orders and invoices, without deleting or receiving payments.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Accounts Payable') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Vendors, bills, bill payments and cheques, with purchase reports.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Purchasing') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Purchase orders and entering bills from vendors, with purchase reports.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Banking') }}</flux:table.cell>
                    <flux:table.cell>{{ __('The bank register, imports, deposits, transfers, cheques and reconciliation.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Inventory') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Stock on hand, inventory adjustments, items and purchase orders, with inventory reports.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Payroll Manager') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Everything in payroll and employees, including setup, pay runs and filings.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Payroll Processor') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Running payroll and recording time, without changing payroll setup.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Time Tracking') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Recording time and time off for employees.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Fundraising') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Donations, receipts, grants and memberships, with fundraising reports.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('View-only') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Can open and print everything outside payroll and settings, but change nothing.') }}</flux:table.cell>
                </flux:table.row>
            </flux:table.rows>
        </flux:table>

        {{-- ───────────────────────── Opening the page ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Opening Users and roles') }}</flux:heading>
        <flux:text>
            {{ __('Open Settings → Users and roles; it is the first entry in the Company group of the Settings menu, and the Users and roles card on the organization’s edit page links to it too. Because the page decides who can reach your books, it first asks for your password, or for a fresh code from your authenticator app if you use two-factor authentication. The page has two tabs: Users and Roles.') }}
        </flux:text>

        {{-- ───────────────────────────── Users tab ──────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Inviting a user') }}</flux:heading>
        <p><strong>{{ __('To invite a user:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Users and roles and stay on the Users tab.') }}</li>
            <li>{{ __('Select Invite user.') }}</li>
            <li>{{ __('Enter their Email address and tick one or more Roles.') }}</li>
            <li>{{ __('Select Send invitation.') }}</li>
        </ol>
        <flux:text>
            {{ __('The invitation lasts three days. Until it is accepted it waits under Pending invitations with the roles it will grant — Demo Company Inc. has one for sam@example.com, with the Banking role. Select Cancel on its row to withdraw it.') }}
        </flux:text>

        <flux:heading size="lg" class="mt-8">{{ __('Changing someone’s roles') }}</flux:heading>
        <p><strong>{{ __('To change the roles a user holds:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('On the Users tab, select Roles on the user’s row.') }}</li>
            <li>{{ __('Tick or untick roles. Below the list, What they can open sums up, area by area, whether the roles together give Full or Partial access.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>
        <flux:text>
            {{ __('To remove someone from the organization, select the × on their row, then Remove. They are signed out at once, and the API keys they made for the organization stop working.') }}
        </flux:text>

        {{-- ───────────────────────────── Roles tab ──────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Creating and editing roles') }}</flux:heading>
        <flux:text>
            {{ __('The Roles tab lists every role, predefined ones first, with how many users hold each. A predefined role carries a Predefined badge, or Predefined, edited once its permissions have been changed. An asterisk marks the roles that can open every area, payroll included. Each row has Edit and Duplicate, and a custom role also has Delete.') }}
        </flux:text>

        <p><strong>{{ __('To create a role:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('On the Roles tab, select New role — or select Duplicate on an existing role to start from a copy of it.') }}</li>
            <li>{{ __('Enter a Role name and, if you like, a Description.') }}</li>
            <li>{{ __('Under Role access, open each area and choose None, Full, or Partial in the Access column for each activity. For Partial, tick the actions the role should have. None for all and Full for all set a whole area at once.') }}</li>
            <li>{{ __('Select Save role.') }}</li>
        </ol>

        <p><strong>{{ __('To edit a role or reset it:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('On the Roles tab, select Edit on the role’s row.') }}</li>
            <li>{{ __('Change its access, then select Save role. The change applies to everyone who holds the role, and the editor says how many people that is.') }}</li>
            <li>{{ __('For a predefined role you have changed, select Reset to default instead to put back the permissions it came with. A predefined role’s name cannot be changed.') }}</li>
        </ol>

        <p><strong>{{ __('To delete a custom role:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('On the Roles tab, select the delete (trash) button on the role’s row.') }}</li>
            <li>{{ __('If anyone holds only this role, choose another role under Move people who hold only this role to, so nobody is left without one.') }}</li>
            <li>{{ __('Select Delete role.') }}</li>
        </ol>

        {{-- ─────────────────────── Narrowing access ─────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('When access is taken away') }}</flux:heading>
        <flux:text>
            {{ __('Taking access away never goes unnoticed. When you save a role with less access, or delete a role, the app lists the people who would lose something and asks first — Take access away? Confirming with Save and sign them out, or Delete and sign them out, signs those people out of the organization at once and stops the API keys they made here. Unticking one of a user’s roles on the Users tab does the same for that user when you save. Giving more access takes effect straight away, with no prompt.') }}
        </flux:text>

        <flux:heading size="md" class="mt-6">{{ __('Nobody can give out more than they hold') }}</flux:heading>
        <p><strong>{{ __('Anyone but the Owner who manages users and roles is held to three rules:') }}</strong></p>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('They can only give a person or a role permissions they hold themselves — when inviting, changing someone’s roles, editing a role, or moving people off a deleted role.') }}</li>
            <li>{{ __('They cannot change their own roles.') }}</li>
            <li>{{ __('They cannot edit a role they hold. Ask the Owner, or someone else who manages roles.') }}</li>
        </ul>

        {{-- ─────────────────────── Balances and payroll ─────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Balances and payroll details') }}</flux:heading>
        <flux:text>
            {{ __('Balances need View balance. Without it, the Dashboard leaves out the cash, receivables, and payables cards, the Chart of accounts hides its Balance column, search results show no account balances, and the API and the AI assistant leave balances out of their answers. Net income and the Dashboard charts need access to financial reports, and each daily insight shows only to people who can open the report it links to.') }}
        </flux:text>
        <flux:text>
            {{ __('Payroll names and wages are shown only to people who can open pay runs. Everyone else still sees payroll entries on the General Ledger, the Journal, the Transactions report, and the bank register, but as “Payroll” with the amount, without the employee’s name.') }}
        </flux:text>

        {{-- ─────────────────────── Permission report ─────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Permission access by roles') }}</flux:heading>
        <flux:text>
            {{ __('The Permission access by roles report lists every activity, area by area, against the roles you choose, and says what each role can do there: None, Full, or the actions it has. Open it with View permissions on the Roles tab, or from Reports → Accountant & Taxes.') }}
        </flux:text>
        <p><strong>{{ __('To compare roles:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('On the Roles tab, select View permissions.') }}</li>
            <li>{{ __('Tick the roles you want to compare; with none ticked, the report shows every role.') }}</li>
            <li>{{ __('Turn on Only rows where the roles differ to hide the activities where they all agree.') }}</li>
            <li>{{ __('Select Export CSV to take the table into a spreadsheet.') }}</li>
        </ol>

        {{-- ─────────────────────── API keys and AI ─────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('API keys and the AI assistant') }}</flux:heading>
        <flux:text>
            {{ __('An API key can do no more than the person who created it may do now, and when that person loses access, the keys they made for the organization stop working. An AI assistant connection is held to the permissions of the person who signed in, or of the API key’s creator. See') }}
            <a class="underline" href="{{ route('docs.api') }}" wire:navigate>{{ __('API') }}</a>
            {{ __('and the AI assistant section of') }}
            <a class="underline" href="{{ route('docs.settings') }}" wire:navigate>{{ __('Settings') }}</a>{{ __('.') }}
        </flux:text>

        {{-- ─────────────────────── Upgrade conversion ─────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Coming from the old member roles') }}</flux:heading>
        <flux:text>
            {{ __('Before roles, members were Owner, Admin, Accountant, or Custom with ticked sections. The upgrade converted everyone, keeping exactly the access they had:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('The Owner stays the Owner.') }}</li>
            <li>{{ __('Admin became Full Access.') }}</li>
            <li>{{ __('Accountant became Accountant.') }}</li>
            <li>{{ __('Custom became a new role named after the sections they had, such as “Custom: Customers, Reports”, shared by everyone with the same ticks.') }}</li>
        </ul>
        <flux:text>
            {{ __('You can rename, edit, or delete those converted Custom roles like any other custom role.') }}
        </flux:text>

        <x-docs.callout type="tip">
            {{ __('The demo organizations show roles at work. Demo Company Inc. has Priya Natarajan on Accounts Receivable, Marc Tremblay on Bookkeeper (he works in French), Dana Whitfield on Sales plus a custom Front desk role, and a pending invitation for sam@example.com with Banking. Demo Community Society has Alex Morgan on Accountant and Jordan Lee on a custom Gift entry role that records donations.') }}
        </x-docs.callout>
    </x-pages::docs.layout>
</section>
