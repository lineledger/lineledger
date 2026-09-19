<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Settings')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Settings')"
        :subheading="__('Your profile, security, organizations, team, features, branding, and more.')"
    >
        <flux:text>
            {{ __('Settings cover two kinds of configuration. Personal settings change things only for you — your profile, security, appearance, sidebar, and legal agreements. Most of them follow you into every organization you belong to; the exceptions are your sidebar layout, which is kept separately for each organization, and the theme, which each browser remembers on its own. Per-organization settings — branding, features, invoice customization, backups, and so on — apply to everyone who works in that organization. Open Settings from the account menu at the bottom of the sidebar. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        <flux:text>
            {{ __('The menu on the left of every Settings page lists your personal pages first — Profile, Security, Organizations, Combined reports, Appearance, Sidebar, and Legal — then a Company group for the organization you are working in (Invoices, Tax & filing, Payroll, Currencies, Inventory, and, for Owners, Backup & Export) and a Lists group. Combined reports, Tax & filing, Payroll, and the lists have their own pages:') }}
            <a class="underline" href="{{ route('docs.reports') }}" wire:navigate>{{ __('Reports') }}</a>{{ __(',') }} <a class="underline" href="{{ route('docs.tax-returns') }}" wire:navigate>{{ __('Tax returns') }}</a>{{ __(',') }} <a class="underline" href="{{ route('docs.payroll') }}" wire:navigate>{{ __('Payroll') }}</a>{{ __(', and') }} <a class="underline" href="{{ route('docs.lists') }}" wire:navigate>{{ __('Lists') }}</a>{{ __('.') }}
        </flux:text>

        {{-- ───────────────────────────── Profile ──────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Profile') }}</flux:heading>
        <flux:text>
            {{ __('Your profile holds the Name and Email used to sign in and to deliver account notifications. It is account-level — the same no matter which organization you are working in. If you change your email, the page shows “Your email address is unverified” with a link to re-send the verification email until you confirm the new address.') }}
        </flux:text>

        <p><strong>{{ __('To update your profile:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Profile.') }}</li>
            <li>{{ __('Edit your Name or Email.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/profile.png') }}"
            alt="{{ __('The Profile settings page with Name and Email fields, a Save button, and the Delete account section below') }}"
            caption="{{ __('The Profile page. The Delete account section at the bottom permanently removes your account and everything it owns.') }}"
        />

        {{-- ──────────────────────────── Security ──────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Security') }}</flux:heading>
        <flux:text>
            {{ __('Settings → Security gathers everything about how you sign in: Update password, Two-factor authentication, Passkeys, and Browser sessions, followed by the API keys panel for the organization you are working in and the Authorized applications panel for your whole account (see API keys below). Two-factor authentication is TOTP-based — you pair an authenticator app and enter a rotating code at sign-in. Passkeys let you sign in without a password using your device’s fingerprint, face, or PIN.') }}
        </flux:text>

        <flux:heading size="md" class="mt-6">{{ __('Confirming it is you') }}</flux:heading>
        <flux:text>
            {{ __('Opening Settings → Security or an organization’s edit page is a sensitive step, so the app first checks it is really you. If two-factor authentication is on, you land on a Confirm your identity screen and enter a fresh code from your authenticator app — or select “confirm using a recovery code” — and the confirmation lasts 15 minutes. A passkey does not satisfy this check, and neither does a device you asked the app to remember at sign-in. If you do not use two-factor, the app asks for your password instead.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/security.png') }}"
            alt="{{ __('The Confirm your identity screen asking for an authentication code, with a link to confirm using a recovery code instead') }}"
            caption="{{ __('With two-factor on, the Security page and an organization’s edit page open only after a fresh authenticator code. Without two-factor, you confirm with your password.') }}"
        />

        <flux:heading size="md" class="mt-6">{{ __('Two-factor authentication') }}</flux:heading>
        <p><strong>{{ __('To turn on two-factor authentication:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Security.') }}</li>
            <li>{{ __('Select Enable 2FA.') }}</li>
            <li>{{ __('Scan the QR code with your authenticator app — or, if you cannot scan, enter the setup key shown below the code manually.') }}</li>
            <li>{{ __('Enter the 6-digit confirmation code from the app to verify the pairing.') }}</li>
            <li>{{ __('Save the recovery codes somewhere safe — they are your only way back in if you lose your authenticator.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/two-factor-setup.png') }}"
            alt="{{ __('The two-factor setup modal showing a QR code and a copyable manual setup key') }}"
            caption="{{ __('The two-factor setup modal. Scan the QR code in your authenticator app, or copy the setup key to enter it by hand.') }}"
        />

        <flux:text>
            {{ __('Once 2FA is on, the Security page lets you view, hide, or regenerate your recovery codes at any time, and Disable 2FA if you no longer want it. At sign-in, the two-factor step offers “Remember this device for 60 days”; devices you remember appear under Trusted devices with their IP address, when they were added or last used, and when the trust expires. They skip the two-factor prompt at login only — they never bypass the identity check above. Select Forget all trusted devices to make every device ask for a code at its next sign-in.') }}
        </flux:text>

        <x-docs.callout type="warning">
            {{ __('Keep your recovery codes somewhere outside the app; without them and without your authenticator you can be locked out. Never remember a shared or public computer.') }}
        </x-docs.callout>

        <flux:heading size="md" class="mt-6">{{ __('Passkeys, password, and browser sessions') }}</flux:heading>
        <flux:text>
            {{ __('Under Passkeys, add a passkey to sign in with your device’s fingerprint, face, or PIN; each one lists its authenticator and when it was added and last used, and can be removed. Under Update password, enter your Current password, a New password, and Confirm password, then select Save. Browser sessions lists every device where you are signed in, with its IP address and when it was last active; the one you are using is badged This device. Select Sign out next to any session you do not recognise, or Sign out other sessions to end all of them at once — that one asks for your password.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/trusted-devices-sessions.png') }}"
            alt="{{ __('The Security page showing the Trusted devices list with Forget all trusted devices, and the Browser sessions list with This device badged and Sign out other sessions') }}"
            caption="{{ __('Trusted devices and Browser sessions. Forget all trusted devices forces a two-factor code at every device’s next sign-in; Sign out other sessions ends every session but this one.') }}"
        />

        <flux:heading size="md" class="mt-6">{{ __('Require two-factor for an organization') }}</flux:heading>
        <flux:text>
            {{ __('Owners and Admins can make two-factor authentication mandatory for an organization. On its edit page, turn on “Require two-factor authentication for owners & admins” and save. Every Owner and Admin is then prompted to set up 2FA before they can use that organization; lower-privilege roles such as Accountant and Custom members are unaffected.') }}
        </flux:text>

        {{-- ─────────────────────────── Organizations ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Organizations') }}</flux:heading>
        <flux:text>
            {{ __('Settings → Organizations lists every organization you belong to, and a New organization button that asks for an Organization name, Country, province or state, Base currency, and Timezone. Each organization keeps its own books, contacts, and reports; organizations never share data. The button at the end of each row opens the edit page: Edit organization for Owners and Admins, View organization (read-only) for everyone else. See') }}
            <a class="underline" href="{{ route('docs.creating-a-company') }}" wire:navigate>{{ __('Creating a company') }}</a>
            {{ __('for the full setup wizard and for restoring an organization from a backup.') }}
        </flux:text>
        <flux:text>
            {{ __('The top of the edit page holds the details that print on documents and drive the books: Company name, Legal name (printed when “Show legal name” is on in invoice settings, and used on donation tax receipts), Address, City, province or state, Country (set at creation and cannot be changed), Telephone number, Website, Email address, Currency (your home currency — leave it as it was set when the organization was created; see Currencies below), Fiscal year start month, and Timezone — transaction dates default to today in this timezone, so a late-evening entry posts on the correct local day. The Save button at the bottom of the form saves this block along with the preferences, features, and branding described below.') }}
        </flux:text>

        <flux:heading size="md" class="mt-6">{{ __('Switching organizations') }}</flux:heading>
        <flux:text>
            {{ __('The organization switcher at the top of the sidebar lists your organizations under an Organizations heading, with a check mark on the current one. Choosing another opens it in a new browser tab — the tab you were in stays on the organization you were in — so you can keep two sets of books open side by side. The same menu ends with New organization.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/company-switcher-new-tab.png') }}"
            alt="{{ __('The organization switcher menu open above the sidebar, showing Demo Community Society with an open-in-new-tab icon and Demo Company Inc. checked as current') }}"
            caption="{{ __('The organization switcher. Each other organization opens in a new tab, marked by the trailing icon; the current one is checked.') }}"
        />

        <flux:heading size="md" class="mt-6">{{ __('Delete company') }}</flux:heading>
        <flux:text>
            {{ __('At the bottom of the edit page, Owners see a Delete company section. Select Delete company and type the organization’s name to confirm. It disappears for every member at once, but it is not erased on the spot — a site administrator can still restore it or purge it permanently (see') }}
            <a class="underline" href="{{ route('docs.site-administration') }}" wire:navigate>{{ __('Site administration') }}</a>{{ __('). An organization badged Personal on the Organizations list cannot be deleted.') }}
        </flux:text>

        {{-- ──────────────────── Team and permissions ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Team and permissions') }}</flux:heading>
        <flux:text>
            {{ __('Invite teammates to an organization and control what each can reach. Beyond the role, every member has section access: an Owner or Admin sees everything, an Accountant sees the books, and a Custom member is granted only the specific areas you choose — Customers, Vendors, Banking, Reports, and so on. People only see the sidebar sections they have access to, and the app blocks the rest even by direct link.') }}
        </flux:text>

        <p><strong>{{ __('The roles:') }}</strong></p>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Owner — full access, including company settings and ownership.') }}</li>
            <li>{{ __('Admin — full access to every section, including settings.') }}</li>
            <li>{{ __('Accountant — access to every section except settings.') }}</li>
            <li>{{ __('Custom — access only to the sections you select.') }}</li>
        </ul>

        <flux:text>
            {{ __('The sections you can grant a Custom member are: Customers, Vendors, Employees, Payroll, Inventory, Accounting, Banking, Inbox, Fundraising, Reports, Lists, Documents, and Settings. Owners and Admins can invite people and cancel invitations; only the Owner can change a member’s role or remove a member, and the Owner’s own row has no Edit or remove control.') }}
        </flux:text>

        <p><strong>{{ __('To invite a teammate:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the organization’s edit page (Settings → Organizations → Edit organization).') }}</li>
            <li>{{ __('In the Company members section, select Invite member.') }}</li>
            <li>{{ __('Enter their Email address and choose a Role — Admin, Accountant, or Custom.') }}</li>
            <li>{{ __('For Custom, tick the Sections they should be able to reach.') }}</li>
            <li>{{ __('Select Send invitation.') }}</li>
        </ol>

        <p><strong>{{ __('To change a member’s role or remove them (Owner only):') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('In the Company members section, select Edit next to the member, pick a new Role — and, for Custom, adjust the Sections they can reach — then select Save.') }}</li>
            <li>{{ __('To remove someone instead, select the remove (×) button next to the member and confirm.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/team-members.png') }}"
            alt="{{ __('The Company members section listing each member with their role badge, Edit and remove controls, an Invite member button, and the Pending invitations list below') }}"
            caption="{{ __('The Company members section. Each member shows their role; the Owner uses Edit to change it or the × to remove them. Pending invitations sit just below with a cancel control.') }}"
        />

        <x-docs.callout type="note">
            {{ __('Lowering someone’s role or removing them takes effect immediately — their active sessions and API keys for the organization are revoked on the spot, so access never outlives the change. Pending invitations expire after 3 days; you can cancel one any time before it is accepted.') }}
        </x-docs.callout>

        {{-- ─────────────────── Edit locks (one editor at a time) ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Edit locks (one editor at a time)') }}</flux:heading>
        <flux:text>
            {{ __('When several teammates work in the same organization, only one person can edit a record at a time — an invoice, bill, cheque, journal entry, customer, account, item, and so on. Whoever opens the record for editing first holds it until they save and leave, so nobody’s changes quietly overwrite someone else’s. There is nothing to switch on; it works for every member.') }}
        </flux:text>

        <p><strong>{{ __('What your teammates see while you are editing:') }}</strong></p>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Anyone who opens the same edit page sees who is editing and when they were last active, instead of the form. They can view the record or try again, and the page offers Start editing as soon as you are done — it never takes the record on its own.') }}</li>
            <li>{{ __('On the record’s own page, a notice says who is editing, and actions that change the record — voiding, posting, deleting and the like — are refused until you are done.') }}</li>
            <li>{{ __('Edit dialogs on list pages — accounts, customers, vendors, employees, items, tax codes, bank rules and the other lists — behave the same way: opening a record someone else has open shows who is editing instead of the dialog, and making it inactive, merging or deleting it is refused.') }}</li>
            <li>{{ __('If a record changes after you opened it — a teammate took it over, or it was updated from somewhere else — your page says so and asks you to reload rather than saving over the newer version.') }}</li>
        </ul>

        <p><strong>{{ __('To take over a record someone else is editing (Owners and Admins only):') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the record, or its edit page or dialog.') }}</li>
            <li>{{ __('Select Take over editing and confirm.') }}</li>
            <li>{{ __('The editor opens for you. The other person’s page tells them you took over, and anything they had not saved can no longer be saved.') }}</li>
        </ol>

        <x-docs.callout type="note" heading="{{ __('When a lock is released') }}">
            {{ __('Saving and leaving the page releases the record straight away — pressing Escape to go back counts as leaving, too. If someone closes the tab, loses their connection or their computer goes to sleep, the lock expires on its own about 2 minutes after the page was closed. A page left open without any typing or clicking pauses editing after 15 minutes, so the record is free for others; select Continue editing to pick up where you left off, as long as nobody else opened or changed the record in the meantime.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('The same rule applies outside the web app. Integrations using the API get a 423 response while a record is being edited and should retry a little later, and never see who is editing — see the') }}
            <a class="underline" href="{{ route('docs.api') }}" wire:navigate>{{ __('API documentation') }}</a>{{ __('. Employees updating their address or TD1 amounts in the') }}
            <a class="underline" href="{{ route('docs.employee-portal') }}" wire:navigate>{{ __('employee portal') }}</a>
            {{ __('are asked to try again in a few minutes while someone is editing their employee record, or to reload if it was opened or changed by someone else while their page was open.') }}
        </flux:text>

        {{-- ──────────────────── Bookkeeping preferences ──────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Bookkeeping and dashboard preferences') }}</flux:heading>
        <flux:text>
            {{ __('Between the address block and the Features list, the organization edit page carries a handful of preferences that shape day-to-day bookkeeping. Most save with the form’s Save button; Show tips and the two AI switches apply the moment you use them.') }}
        </flux:text>

        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Auto-apply customer credits on invoice post — when a new invoice is posted, the oldest unapplied customer receipts are used to pay it down automatically.') }}</li>
            <li>{{ __('Warn if duplicate bill number is used — when entering a bill, warns if this supplier already has a bill with the same reference number. You can still save it.') }}</li>
            <li>{{ __('Getting-started tips — Show tips restarts the dashboard tour from the first tip, for new users who dismissed it too soon.') }}</li>
            <li>{{ __('Daily insight — “Write my daily insight with AI” sends only summarized totals — never customer names, transaction details, or descriptions — to Anthropic to word the note on your dashboard. When off, insights still appear using LineLedger’s built-in wording. The block appears only when your operator has enabled the AI layer.') }}</li>
            <li>{{ __('Cheque print alignment — Horizontal offset and Vertical offset, in points (1 pt = 1/72 in), nudge printed cheque text onto your pre-printed stock. Print a cheque, measure the drift, then adjust; leave both blank for the default.') }}</li>
        </ul>

        <flux:text>
            {{ __('The AI assistant writes (MCP) block sits alongside these and is covered under AI assistant below. For what the daily insight shows and the per-person switch that hides it, see') }}
            <a class="underline" href="{{ route('docs.insights') }}" wire:navigate>{{ __('Insights') }}</a>{{ __('; for printing cheques, see') }}
            <a class="underline" href="{{ route('docs.banking') }}" wire:navigate>{{ __('Banking') }}</a>{{ __('.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/company-preferences.png') }}"
            alt="{{ __('The organization edit page showing the Auto-apply customer credits and Warn if duplicate bill number switches, the Getting-started tips block with Show tips, the Daily insight AI switch, the AI assistant writes (MCP) switch, and the Cheque print alignment offsets') }}"
            caption="{{ __('The preference blocks on the organization edit page. Show tips and the AI switches apply immediately; the rest save with the form.') }}"
        />

        {{-- ───────────────────────── Feature toggles ─────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Feature toggles') }}</flux:heading>
        <flux:text>
            {{ __('Not every organization needs every module. The Features section of the edit page switches modules on or off: Employees, Payroll, Inventory, Fixed assets, Estimates, Sales orders, Purchase orders, Recurring invoices, Recurring bills, Classes and Locations (the two reporting dimensions), Budgets, Membership, and — when the Fundraising section is available to your organization — Donations & grants. Turning a feature off simply hides its sidebar entries and forms; it never deletes data, so you can turn it back on later and find everything intact. Fund accounting is not here: it lives in the Non-profit & charity block described below.') }}
        </flux:text>

        <p><strong>{{ __('To turn a feature on or off:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Organizations and select Edit organization to open the edit page.') }}</li>
            <li>{{ __('Scroll to the Features section.') }}</li>
            <li>{{ __('Flip the switch for the module you want to show or hide.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/company-edit.png') }}"
            alt="{{ __('The Features section of the organization edit page, with a switch for each module such as Employees, Payroll, Inventory, Fixed assets, Estimates, Classes, Locations, and Budgets') }}"
            caption="{{ __('The Features section. Each switch shows or hides a module for everyone in the organization; turning one off never deletes its data.') }}"
        />

        {{-- ──────────────────────────── Period lock ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Period lock (closing the books)') }}</flux:heading>
        <flux:text>
            {{ __('Owners and Admins can set a closing date that freezes every transaction on or before it, so a finished period cannot be changed by accident. The control lives in the Close the books section of the edit page: select Close the books (or Change lock date once one is set), pick a Lock date — a click anywhere in the field opens the calendar, and leaving it blank removes the lock — enter Your account password, and select Confirm. Every change is recorded in the audit log. See') }}
            <a class="underline" href="{{ route('docs.accounting') }}" wire:navigate>{{ __('Accounting → Period locking') }}</a>
            {{ __('for exactly what it protects.') }}
        </flux:text>
        <flux:text>
            {{ __('A period lock is not the same as an edit lock: the period lock freezes every transaction dated on or before the closing date for everyone until an Owner or Admin moves the date, while an edit lock only stops two people changing the same record at the same moment and clears itself when the editor is done.') }}
        </flux:text>

        {{-- ──────────── Country, organization type and jurisdiction ──────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Country, organization type and jurisdiction') }}</flux:heading>
        <flux:text>
            {{ __('You choose a Country — Canada or the United States — when you create an organization. The choice shapes a lot: the starting chart of accounts, the seeded tax codes and tax agency, the default payment methods, the currency, and even the wording the app uses (Cheque vs. Check, GST/HST vs. Sales Tax, Province vs. State, Postal Code vs. ZIP Code). Because changing it would invalidate posted history, the country is permanent once the organization is created.') }}
        </flux:text>
        <flux:text>
            {{ __('Organization type — how your organization is legally structured — is set on the edit page and determines which CRA returns apply and how equity is presented. It too can be set only once: pick it from the Organization type list and save, and from then on the block shows the type with a note that it cannot be changed. Choosing a non-profit type reveals a Non-profit & charity block with a Legal structure (Unincorporated association, Non-profit corporation, or Registered charity), a CRA charity registration number when the structure requires one — your Business Number with RR program account, needed to issue official donation receipts and file the T3010 — and a Contribution accounting method. The Restricted fund method adds a Fund accounting switch, which tags transaction lines with a fund and seeds a default General Fund when turned on. Changing the contribution method only affects how new restricted contributions are recorded and how statements present — it never reclassifies existing entries. Demo Community Society is set up this way; see') }}
            <a class="underline" href="{{ route('docs.fundraising') }}" wire:navigate>{{ __('Fundraising') }}</a>{{ __('.') }}
        </flux:text>
        <flux:text>
            {{ __('Canadian organizations with Payroll turned on also see a Quebec payroll block — QHSF rate (%), CNESST rate (%), and the WSDRF levy switch — for employer levies remitted to Revenu Québec; leave the rates at 0 if you have no Quebec employees.') }}
        </flux:text>

        {{-- ──────────────────────────── Branding ──────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Branding') }}</flux:heading>
        <flux:text>
            {{ __('The edit page carries two branding controls. The Sidebar branding section sets the badge shown above the organization switcher: a Display name (leave it blank to use the Company name), Initials with your own Text color and Background color, or a Logo (PNG, JPG, or SVG up to 2 MB) that replaces the initials.') }}
        </flux:text>
        <flux:text>
            {{ __('The Document logo section is a separate Logo image for your printed and PDF documents — invoices, estimates, and the rest — where it prints in place of the organization name. Logo height on documents (px) controls how large it appears (48–80 px is recommended), and if you leave the image blank the sidebar logo is used instead.') }}
        </flux:text>

        {{-- ──────────────────────────── Inventory ─────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Inventory defaults') }}</flux:heading>
        <flux:text>
            {{ __('Settings → Inventory holds the organization-wide inventory defaults: the costing method (weighted average or FIFO) and the default inventory-asset and cost-of-goods-sold accounts that new tracked items inherit. The costing method locks as soon as any item records its first stock movement, so set it before you begin trading. See') }}
            <a class="underline" href="{{ route('docs.inventory') }}" wire:navigate>{{ __('Inventory') }}</a>
            {{ __('for per-item tracking.') }}
        </flux:text>

        {{-- ──────────────────── Invoice customization ─────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Invoice customization') }}</flux:heading>
        <flux:text>
            {{ __('Settings → Invoices controls how your printed and PDF documents look and how invoices are emailed. The Header options here apply to every printed document Demo Company Inc. sends — invoices, estimates, sales orders, receipts, purchase orders, and bills — while the rest of the page is invoice-specific: a default sales account, which optional line columns appear, a tax registration number and footer message, the email sender and message, and the payment instructions shown on the online payment page.') }}
        </flux:text>

        <p><strong>{{ __('To customize your invoices:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Invoices.') }}</li>
            <li>{{ __('Under Header, choose whether to show your logo, company name, legal name, address, phone, email, and website.') }}</li>
            <li>{{ __('Under Defaults, set a default sales account — lines with no account (including when the Account column is hidden) fall back to it automatically.') }}</li>
            <li>{{ __('Under Line columns, toggle the optional columns (Item, Quantity, Tax, Service date). Description, price, and amount always show. Turn on “Hide zero-quantity lines” to leave line items with a quantity of 0 and no amount off the printed invoice.') }}</li>
            <li>{{ __('Under Tax & footer, enter your tax registration number and a footer message if you want them on the PDF.') }}</li>
            <li>{{ __('Under Emailing invoices, set the sender name, reply-to address, and default message.') }}</li>
            <li>{{ __('Under Payment instructions, add other ways customers can pay — for example an Interac e-Transfer address — to show on the online payment page.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/invoices.png') }}"
            alt="{{ __('The Invoices settings page with the Header, Defaults, Line columns (including Hide zero-quantity lines), Tax & footer, Emailing invoices, and Payment instructions sections') }}"
            caption="{{ __('The Invoices settings page. Description, price, and amount columns always show; the toggles control the optional ones and whether zero-quantity lines print. Header options carry through to every printed document.') }}"
        />

        <flux:heading size="lg" class="mt-8">{{ __('Optional invoice fields and columns') }}</flux:heading>
        <flux:text>
            {{ __('Beyond the few line columns on Settings → Invoices, the invoice and credit-memo forms carry a longer list of optional header fields and line columns — Terms, Sales rep, Customer PO #, Ship date, Ship via, FOB, Tracking #, Memo, the customer message, plus line columns like Discount, Markup, Tax, and Account. You turn these on or off from the Fields menu at the top-right of the invoice or credit-memo form, not from Settings. Your choice is saved for the whole organization, so the next form anyone opens uses the same layout. See') }}
            <a class="underline" href="{{ route('docs.customers') }}" wire:navigate>{{ __('Customers') }}</a>
            {{ __('for what each field does on the invoice itself.') }}
        </flux:text>

        <p><strong>{{ __('To change which fields and columns appear on invoices and credit memos:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open a new or existing invoice (Sales → Invoices → New invoice).') }}</li>
            <li>{{ __('Select the Fields menu in the top-right of the form.') }}</li>
            <li>{{ __('Tick or untick the header fields and line columns you want — for example Customer PO #, Ship date, or the Discount column.') }}</li>
            <li>{{ __('The form updates immediately, and the layout is saved for every invoice and credit memo Demo Company Inc. creates afterward.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/invoice-fields.png') }}"
            alt="{{ __('The Fields menu open on the invoice form, listing header-field and line-column checkboxes such as Terms, Customer PO, Ship date, Discount, and Account') }}"
            caption="{{ __('The Fields menu on the invoice form. Ticking a field shows it on every new invoice and credit memo; unticking it hides the column without touching data on past documents.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('Hiding a column never deletes existing data. If a past invoice used Customer PO and you later switch the field off, the value stays on that invoice and reappears the moment you turn the field back on.') }}
        </x-docs.callout>

        {{-- ──────────────────── Inbox email (forward receipts) ─────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Inbox email (forward receipts and bills)') }}</flux:heading>
        <flux:text>
            {{ __('The Inbox email settings page lets your team forward receipts and bills straight into LineLedger by email. Anything sent to the organization’s forwarding address is staged as a document in the Inbox review queue, where you turn it into a draft bill or expense. It is a quick way to capture a supplier invoice the moment it lands in someone’s mailbox, without logging in to upload it. See') }}
            <a class="underline" href="{{ route('docs.inbox') }}" wire:navigate>{{ __('Inbox') }}</a>
            {{ __('for the review queue itself.') }}
        </flux:text>
        <flux:text>
            {{ __('The page is not listed in the Settings menu. Go to /settings/inbox-email directly, or select Open inbox settings in the Auto-fill is off notice on an Inbox review screen — that link appears only while Read receipts automatically is off for your organization. Only Owners and Admins can open the page, and it applies to the organization you are working in.') }}
        </flux:text>

        <p><strong>{{ __('To turn on inbound email:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Go to /settings/inbox-email while you are working in the organization.') }}</li>
            <li>{{ __('Switch on “Accept documents by email.”') }}</li>
            <li>{{ __('Select Save. Saving creates the organization’s forwarding address, in the form inbox+yourtoken@your-inbound-domain, and the Forwarding address field appears.') }}</li>
            <li>{{ __('Copy the Forwarding address and share it with your team — or add it as a contact so a supplier invoice can be forwarded in one tap.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/inbox-email.png') }}"
            alt="{{ __('The Inbox email settings page showing the Accept documents by email switch turned on, the copyable Forwarding address with Save and Generate new address buttons, and the Read receipts automatically switch') }}"
            caption="{{ __('The Inbox email settings page after inbound email has been switched on and saved. The Forwarding address appears only once you save; “Generate new address” rotates it if it ever leaks.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('Who can send in') }}">
            {{ __('Only emails from your organization’s active team members are accepted at the forwarding address — anything from an unknown sender is ignored, so the address is safe to share inside the team. If it is ever exposed, select “Generate new address” to rotate the token: the old address stops working immediately and a fresh one takes its place.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('Just below, “Read receipts automatically” turns on optional AI reading of each forwarded or uploaded document: LineLedger extracts the vendor, total, and date so the draft bill is pre-filled and you only have to review it. This is off by default and opt-in — until you switch it on, documents go straight to manual review and nothing is ever sent to an AI service. Switch it on and select Save to enable it.') }}
        </flux:text>

        <x-docs.callout type="warning">
            {{ __('The forwarding address only appears once your operator has configured an inbound mail domain on the server. Until then, switching on Accept documents by email shows a “not configured” notice instead of an address. Automatic reading also depends on the operator enabling the AI layer, so both you and the operator must opt in before any document is read. See') }}
            <a class="underline" href="{{ route('docs.self-hosting') }}" wire:navigate>{{ __('Self-hosting') }}</a>{{ __('.') }}
        </x-docs.callout>

        {{-- ──────────────────────────── Currencies ────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Currencies') }}</flux:heading>
        <flux:text>
            {{ __('Settings → Currencies is where you enable the foreign currencies you trade in, manage exchange rates, and run period-end revaluations. The Home currency block at the top shows the currency your books are kept in — the Base currency chosen when the organization was created — with the note that it is fixed and cannot be changed. Every amount already in your ledger is recorded in that currency. The organization edit page still shows it in an editable Currency field, but editing it there converts nothing: your posted amounts would simply carry the wrong currency label, so leave it as it is. See') }}
            <a class="underline" href="{{ route('docs.multi-currency') }}" wire:navigate>{{ __('Multi-currency') }}</a>
            {{ __('for how foreign transactions are recorded.') }}
        </flux:text>

        {{-- ──────────────────────── Backup & export ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Backup & export') }}</flux:heading>
        <flux:text>
            {{ __('Owners can create a full backup of an organization from Settings → Backup & Export. The backup is built as a queued job, so it is not instant — the page refreshes itself every few seconds while one is in progress. Each backup holds every record scoped to the organization: chart of accounts, transactions, attachments, settings, and API keys — so treat the ZIP as sensitive. The page lists the organization’s 10 most recent backups.') }}
        </flux:text>
        <flux:text>
            {{ __('A backup is kept for 7 days after it is built; the row shows when it expires. After that the Download button disappears, and a daily clean-up deletes the ZIP and marks the backup Expired — the row stays as a record that the backup was made. Download it well before then if you want to keep it. The Download link itself is created fresh each time the page loads and works for one hour; if you left the page open longer, reload it to get a new link.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/backup-export.png') }}"
            alt="{{ __('Settings → Backup & Export with a Create backup button and a list of backups showing their status badge, size, expiry, and Download and delete controls') }}"
            caption="{{ __('Settings → Backup & Export. Each backup shows its status, size, and when it expires — 7 days after it was built. While it is Ready, Download saves the ZIP and the trash icon removes it.') }}"
        />

        <p><strong>{{ __('To create a backup:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Backup & Export.') }}</li>
            <li>{{ __('Select Create backup. The job is queued and the backup shows as Pending, then Running.') }}</li>
            <li>{{ __('Wait a moment — the status changes to Ready when the ZIP is built. A backup that could not be built shows Failed with the reason.') }}</li>
            <li>{{ __('Select Download to save the ZIP to your computer. Use the trash icon to delete a backup you no longer need; the ZIP is removed and its link stops working at once.') }}</li>
        </ol>

        <flux:heading size="md" class="mt-6">{{ __('Restoring a backup') }}</flux:heading>
        <flux:text>
            {{ __('A restore never overwrites an existing organization. It always creates a brand-new organization from the ZIP, and whoever uploads it becomes that organization’s Owner; the organization the backup came from is left exactly as it is. Team members are matched by email address where they already have an account on the server, and anything recorded by someone who is not matched is attributed to you.') }}
        </flux:text>

        <p><strong>{{ __('To restore a backup into a new organization:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Select Restore from backup on the organization picker, or choose Restore from a backup in the new-organization wizard.') }}</li>
            <li>{{ __('Drop the backup ZIP onto the upload area, or click to choose it (up to 100 MB), then select Inspect bundle.') }}</li>
            <li>{{ __('Under Bundle ready to restore, check the Source company, the Records to restore, and the User attribution summary.') }}</li>
            <li>{{ __('Select Confirm and restore. The page updates as it works and takes you to the new organization’s dashboard when it is done.') }}</li>
        </ol>

        <flux:text>
            {{ __('See') }}
            <a class="underline" href="{{ route('docs.creating-a-company') }}" wire:navigate>{{ __('Creating a company') }}</a>
            {{ __('for the new-organization wizard.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('Organization backups are not server backups') }}">
            {{ __('A backup from this page covers one organization and can only be restored as a new organization. It does not protect the server itself — the database with every organization and user, the storage volume, and the .env configuration. If you run your own LineLedger server, set up instance backups as well; see') }}
            <a class="underline" href="{{ route('docs.self-hosting') }}" wire:navigate>{{ __('Self-hosting → Organization backups versus instance backups') }}</a>{{ __('.') }}
        </x-docs.callout>

        {{-- ──────────────────── Online payments (Stripe) ──────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Online payments (Stripe)') }}</flux:heading>
        <flux:text>
            {{ __('Owners can connect a Stripe account so customers pay invoices by card through the customer portal. The Online payments section of the edit page shows Not connected with a Connect Stripe button, or Stripe connected with the account id and a Disconnect button; once connected, the accounts and payment method Stripe needs are created for you. If Stripe reports a problem, the section says the connection needs attention, card payments pause, and a Reconnect Stripe button appears. See') }}
            <a class="underline" href="{{ route('docs.customer-portal') }}" wire:navigate>{{ __('Customer portal') }}</a>
            {{ __('for how payments flow into your books.') }}
        </flux:text>

        {{-- ──────────────────── Customizing your sidebar ──────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Customizing your sidebar') }}</flux:heading>
        <flux:text>
            {{ __('Under Settings → Sidebar you can tailor the sidebar to the work you actually do. Each group — Sales (called Revenues for a non-profit organization), Purchases, Banking, Accounting, Reports, and so on — and every individual link inside it has its own switch. Turning off a whole section hides it and all its links, even if individual links are still on. A Reset to defaults button brings everything back. Links that only some members can reach, such as the Owner-only Opening balances entry under Accounting, are listed only for those members.') }}
        </flux:text>
        <flux:text>
            {{ __('Your choices are saved per person and per organization: they only change what you see, never what your teammates see, and you can keep different layouts in different organizations. In the sidebar itself you can collapse or expand each group as you work, and the Reports group keeps a Favorites quick-list of the reports you star.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/navigation.png') }}"
            alt="{{ __('Settings → Sidebar showing per-group and per-link switches, including the Opening balances link under Accounting, with Save and Reset to defaults buttons') }}"
            caption="{{ __('Settings → Sidebar. Switch whole groups or individual links; Reset to defaults restores everything. Only affects you.') }}"
        />

        <p><strong>{{ __('To customize your sidebar:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Sidebar.') }}</li>
            <li>{{ __('Switch off any groups or links you do not want to see; switch on anything you want back.') }}</li>
            <li>{{ __('Select Save. To start over, select Reset to defaults.') }}</li>
        </ol>

        {{-- ──────────────────────────── Appearance ────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Appearance') }}</flux:heading>
        <flux:text>
            {{ __('Settings → Appearance holds three personal preferences. Calculator mode and Escape goes back are saved on your account, so they follow you into every organization and onto every device you sign in from. The theme is different: it is remembered by the browser you pick it in, so a new browser or another computer starts on Light until you choose again there.') }}
        </flux:text>

        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Theme — Light, Dark, or System (System follows your device’s light or dark setting). The app uses the Tidewater design system, a teal-accented palette tuned for both modes so amounts, totals, and warnings stay readable in either.') }}</li>
            <li>{{ __('Calculator mode — how the sidebar calculator behaves. Standard works like a normal calculator: enter a number, pick an operator, then = for the result, with every step printed to the tape. Adding machine works like an accountant’s 10-key: + and − add or subtract each entry to a running total, and Total gives the grand total. This setting is separate from the calculator built into every dollar cell, where you can type an expression such as 100*1.13 with + − × ÷ and the cell commits the result.') }}</li>
            <li>{{ __('Escape goes back — on by default. Pressing Escape on any page does what the browser’s Back button does. If something is open — a dialog, a dropdown, a date picker, a search box with text in it — Escape closes that first and the page stays put. If you have unsaved changes in a form, you are asked “Leave this page?” before it goes anywhere, and with no page behind the current one Escape does nothing. Switch it off if you prefer Escape to leave the page alone.') }}</li>
        </ul>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/appearance.png') }}"
            alt="{{ __('Settings → Appearance showing the Theme segmented control, the Calculator mode choice between Standard and Adding machine, and the Escape goes back switch') }}"
            caption="{{ __('Settings → Appearance. All three apply as soon as you change them — Calculator mode and Escape goes back to your account, the theme to this browser only.') }}"
        />

        {{-- ──────────────────────────────── Legal ─────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Legal') }}</flux:heading>
        <flux:text>
            {{ __('Settings → Legal lists the legal documents your operator has published — typically the Terms of Service, Privacy Policy, Data Processing Addendum, Security, and Sub-processors pages — each opening in a new tab. The ones you must agree to, such as the Terms of Service and Privacy Policy, show “You agreed on” with the date; the others are there for reference only.') }}
        </flux:text>
        <flux:text>
            {{ __('You never have to come here to accept anything. Whenever a document that needs your agreement is new to you, or has been updated since you agreed, the next page you open is replaced by a Review our terms screen — Our terms have been updated if you had agreed to an earlier version — listing each document with a Read link. Tick “I have read and agree to the documents above.” and select Accept and continue to go on into the app, or select Log out. Until you accept, no other page opens, including this one; afterwards, Settings → Legal shows the new date.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/legal.png') }}"
            alt="{{ __('Settings → Legal listing the Terms of Service and Privacy Policy, each with the date you agreed, followed by the Data Processing Addendum, Security, and Sub-processors links') }}"
            caption="{{ __('Settings → Legal. Each document opens in a new tab; the ones that need your agreement show the date you agreed.') }}"
        />

        {{-- ──────────────────────────── API keys ──────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('API keys') }}</flux:heading>
        <flux:text>
            {{ __('API keys grant programs and integrations access to your organization’s data through the /api/v1 REST API. Owners and Admins manage them in the API keys panel near the bottom of Settings → Security, for the organization you are working in. Select Create API key, give it a Label, choose when it Expires (Never, In 30 days, In 90 days, or In 1 year), and tick Scopes — leave all unchecked for full access, or grant read or write per domain and refine by resource for a narrower key. The key is shown once, at creation; copy it then, because you cannot retrieve it later. Each row shows whether the key is Active, Expires soon, Expired, or Revoked, plus when it was last used, with Edit (label and scopes only), Rotate (a new value, the old one stops at once), and Revoke.') }}
        </flux:text>
        <flux:text>
            {{ __('See the') }}
            <a class="underline" href="{{ route('docs.api') }}" wire:navigate>{{ __('API documentation') }}</a>
            {{ __('for the full step-by-step on creating a key and which endpoints accept it.') }}
        </flux:text>

        <flux:heading size="md" class="mt-6">{{ __('Authorized applications') }}</flux:heading>
        <flux:text>
            {{ __('Right below API keys, the Authorized applications panel lists every app you have connected to your account over OAuth — for example an AI assistant (MCP) connector. Each shows the scopes it holds (or a Full access badge), when it connected, and how many active sessions it has. Select Revoke to cut it off: its tokens stop working immediately and it must be reconnected to use again.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/authorized-apps.png') }}"
            alt="{{ __('The Authorized applications panel on Settings → Security listing a connected AI assistant with its scope badges, connection time, active session count, and a Revoke button') }}"
            caption="{{ __('Authorized applications. Revoke signs an app out on the spot; it has to go through the connection flow again to come back.') }}"
        />

        {{-- ──────────────────── AI assistant (Business Q&A) ────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('AI assistant (Business Q&A)') }}</flux:heading>
        <flux:text>
            {{ __('LineLedger ships a read-only Model Context Protocol (MCP) connector that lets an AI assistant answer plain-language questions about Demo Company Inc.’s books — “who owes me money?”, “how did last quarter look?”, “am I low on any stock?” It reads only from your posted general ledger and can never create, edit, or delete anything. Connect it with an API key or by signing in over OAuth; the') }}
            <a class="underline" href="{{ route('docs.api') }}" wire:navigate>{{ __('API documentation') }}</a>
            {{ __('covers the setup.') }}
        </flux:text>

        <flux:heading size="md" class="mt-6">{{ __('AI assistant writes (MCP)') }}</flux:heading>
        <flux:text>
            {{ __('A separate, write-enabled mode lets a connected assistant draft invoices, bills, expenses, and journal entries for an organization. It is deliberately gated twice. The site operator must first turn on writes on the server (MCP_WRITE_ENABLED); then an Owner or Admin opens the organization’s edit page (Settings → Organizations → Edit organization), finds the AI assistant writes (MCP) block, and turns on Allow AI assistant writes — it applies to this organization only and saves immediately. Until the operator has enabled writes, the switch is greyed out with a note that it cannot be turned on yet.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('Nothing is saved until you confirm') }}">
            {{ __('Even with both switches on, the assistant can only propose a document. Nothing is saved until the proposal is confirmed in a second, explicit step — it never writes directly to your ledger. With either switch off, which is the default, the assistant stays strictly read-only. See') }}
            <a class="underline" href="{{ route('docs.api') }}" wire:navigate>{{ __('API documentation → MCP') }}</a>
            {{ __('for the propose-and-confirm flow, and') }}
            <a class="underline" href="{{ route('docs.self-hosting') }}" wire:navigate>{{ __('Self-hosting') }}</a>
            {{ __('for the operator switch.') }}
        </x-docs.callout>
    </x-pages::docs.layout>
</section>
