<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Settings')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Settings')"
        :subheading="__('Your profile, security, companies, team, features, branding, and more.')"
    >
        <flux:text>
            {{ __('Settings cover two kinds of configuration: account-level settings that follow you across every company you belong to — your profile, security, and appearance — and per-company settings like branding, features, and invoice customization. Open Settings from the account menu at the bottom of the sidebar. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        {{-- ───────────────────────────── Profile ──────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Profile') }}</flux:heading>
        <flux:text>
            {{ __('Your profile holds the name and email used to sign in and to deliver account notifications. This is account-level — it is the same no matter which company you are working in.') }}
        </flux:text>

        <p><strong>{{ __('To update your profile:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Profile.') }}</li>
            <li>{{ __('Edit your Name or Email.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/profile.png') }}"
            alt="{{ __('The Profile settings page with name and email fields') }}"
            caption="{{ __('The Profile page. The Delete account button at the bottom permanently removes your account and everything it owns.') }}"
        />

        {{-- ──────────────────────────── Security ──────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Security') }}</flux:heading>
        <flux:text>
            {{ __('The Security page is where you change your password, turn on two-factor authentication, and register passkeys. Two-factor authentication is TOTP-based — you pair it with an authenticator app and enter a rotating code at sign-in. Passkeys let you sign in without a password using your device’s fingerprint, face, or PIN.') }}
        </flux:text>

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
            {{ __('Once 2FA is on, the Security page lets you view, hide, or regenerate your recovery codes at any time, and Disable 2FA if you no longer want it. Passkeys and your password live on the same page: register a passkey to sign in with your device’s fingerprint, face, or PIN, and use Update password to change your password.') }}
        </flux:text>

        <x-docs.callout type="warning">
            {{ __('When the app needs to confirm it is really you — before changing security settings or setting a period lock — it asks you to confirm with your password or a passkey first. Keep your recovery codes somewhere outside the app; without them and without your authenticator you can be locked out.') }}
        </x-docs.callout>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/security.png') }}"
            alt="{{ __('The password confirmation screen offering a passkey or password') }}"
            caption="{{ __('Sensitive actions prompt you to confirm with a passkey or your password before continuing.') }}"
        />

        <flux:heading size="md" class="mt-6">{{ __('Require two-factor for a company') }}</flux:heading>
        <flux:text>
            {{ __('Owners and admins can make two-factor authentication mandatory for a company. On the company edit page, turn on “Require two-factor authentication for owners & admins.” Once it is on, every owner and admin is prompted to set up 2FA before they can use that company; lower-privilege roles such as Accountant and Custom members are unaffected.') }}
        </flux:text>

        {{-- ──────────────────────────── Companies ─────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Companies') }}</flux:heading>
        <flux:text>
            {{ __('Settings → Organizations lists every company you belong to and lets you edit company-level details — name, legal name, address, province or state, currency, fiscal year start, and time zone. Each company has its own books, contacts, and reports; companies never share data. Use the selector at the top of the sidebar to switch between them.') }}
        </flux:text>

        {{-- ──────────────────── Team and permissions ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Team and permissions') }}</flux:heading>
        <flux:text>
            {{ __('Invite teammates to a company and control what each can reach. Beyond the company role, every member has section access: an Owner or Admin sees everything, an Accountant sees the books, and a Custom member is granted only the specific areas you choose — Customers, Vendors, Banking, Reports, and so on. People only see the sidebar sections they have access to, and the app blocks the rest even by direct link.') }}
        </flux:text>

        <p><strong>{{ __('The roles:') }}</strong></p>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Owner — full access, including company settings and ownership.') }}</li>
            <li>{{ __('Admin — full access to every section, including settings.') }}</li>
            <li>{{ __('Accountant — access to every section except settings.') }}</li>
            <li>{{ __('Custom — access only to the sections you select.') }}</li>
        </ul>

        <flux:text>
            {{ __('The sections you can grant a Custom member are: Customers, Vendors, Employees, Payroll, Inventory, Accounting, Banking, Inbox, Fundraising, Reports, Lists, Documents, and Settings.') }}
        </flux:text>

        <p><strong>{{ __('To invite a teammate:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the company edit page (Settings → Organizations → your company).') }}</li>
            <li>{{ __('In the Company members section, select Invite member.') }}</li>
            <li>{{ __('Enter their email and choose a role — Admin, Accountant, or Custom.') }}</li>
            <li>{{ __('For Custom, tick the sections they should be able to reach.') }}</li>
            <li>{{ __('Send the invitation.') }}</li>
        </ol>

        <p><strong>{{ __('To change a member’s role:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('In the Company members section, select Edit next to the member.') }}</li>
            <li>{{ __('Pick a new role — and, for Custom, adjust the sections they can reach.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <p><strong>{{ __('To remove a member:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('In the Company members section, select the remove (×) button next to the member.') }}</li>
            <li>{{ __('Confirm the removal.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/team-members.png') }}"
            alt="{{ __('The Company members section listing each member with their role and Edit and remove controls') }}"
            caption="{{ __('The Company members section. Each member shows their role; use Edit to change it or the × to remove them.') }}"
        />

        <x-docs.callout type="note">
            {{ __('Lowering someone’s role or removing them takes effect immediately — their active sessions and API keys for the company are revoked on the spot, so access never outlives the change. Pending invitations expire after 3 days; you can cancel one any time before it is accepted.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Feature toggles ─────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Feature toggles') }}</flux:heading>
        <flux:text>
            {{ __('Not every company needs every module. On the company edit page you can switch features on or off — employees and payroll, inventory, fixed assets, estimates, sales orders, purchase orders, recurring invoices and bills, classes and locations (the two reporting dimensions), budgets, membership, donations & grants, and fund accounting. Turning a feature off simply hides its sidebar entries and forms; it never deletes data, so you can turn it back on later and find everything intact.') }}
        </flux:text>

        <p><strong>{{ __('To turn a feature on or off:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Organizations and select your company to open its edit page.') }}</li>
            <li>{{ __('Scroll to the Features section.') }}</li>
            <li>{{ __('Flip the toggle for the module you want to show or hide.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/company-edit.png') }}"
            alt="{{ __('The company edit page showing the Features toggles and Sidebar branding') }}"
            caption="{{ __('The company edit page. The Features list toggles modules on or off; Sidebar branding controls the badge above the company switcher.') }}"
        />

        {{-- ──────────────────────────── Period lock ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Period lock (closing the books)') }}</flux:heading>
        <flux:text>
            {{ __('Owners and admins can set a closing date that freezes every transaction on or before it, so a finished period cannot be changed by accident. Setting or clearing the lock requires your password and is recorded in the audit log. The control lives in the Close the books section of the company edit page — see Accounting → Period locking for exactly what it protects.') }}
        </flux:text>

        {{-- ──────────────────── Country and jurisdiction ──────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Country and jurisdiction') }}</flux:heading>
        <flux:text>
            {{ __('You choose a country — Canada or the United States — when you create a company. The choice shapes a lot: the starting chart of accounts, the seeded tax codes and tax agency, the default payment methods, the currency, and even the wording the app uses (Cheque vs. Check, GST/HST vs. Sales Tax, Province vs. State, Postal Code vs. ZIP Code). Because changing it would invalidate posted history, the country is permanent once the company is created.') }}
        </flux:text>

        {{-- ──────────────────────────── Branding ──────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Branding') }}</flux:heading>
        <flux:text>
            {{ __('The company edit page carries two branding controls. The Sidebar branding section sets the badge shown above the company switcher: a display name (leave it blank to fall back to the company name), custom initials with your own text and background colors, or a logo that replaces the initials.') }}
        </flux:text>
        <flux:text>
            {{ __('The Document logo section is a separate upload for your printed and PDF documents — invoices, estimates, and the rest — where the logo prints in place of the company name. A height setting controls how large it appears on the page, and if you leave it blank the sidebar logo is used instead.') }}
        </flux:text>

        {{-- ──────────────────────────── Inventory ─────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Inventory defaults') }}</flux:heading>
        <flux:text>
            {{ __('Settings → Inventory holds the company-wide inventory defaults: the costing method (weighted average or FIFO) and the default inventory-asset and cost-of-goods-sold accounts that new tracked items inherit. The costing method locks as soon as any item records its first stock movement, so set it before you begin trading. See the Lists page for per-item tracking.') }}
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
            alt="{{ __('The Invoices settings page with header, defaults, line columns, tax and footer, emailing, and payment instructions sections') }}"
            caption="{{ __('The Invoices settings page. Description, price, and amount columns always show; the toggles control the optional ones. Header options carry through to every printed document.') }}"
        />

        <flux:heading size="lg" class="mt-8">{{ __('Optional invoice fields and columns') }}</flux:heading>
        <flux:text>
            {{ __('Beyond the few line columns on Settings → Invoices, the invoice and credit-memo forms carry a longer list of optional header fields and line columns — Terms, Sales rep, Customer PO #, Ship date, Ship via, FOB, Tracking #, Memo, the customer message, plus line columns like Discount, Markup, Tax, and Account. You turn these on or off from the Fields menu at the top-right of the invoice or credit-memo form, not from Settings. Your choice is saved for the whole company, so the next form anyone opens uses the same layout. See the Customers page for what each field does on the invoice itself.') }}
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
            {{ __('Settings → Inbox email lets your team forward receipts and bills straight into LineLedger by email. Anything sent to the company’s forwarding address is staged as a document in the Inbox review queue, where you turn it into a draft bill or expense. It is a quick way to capture a supplier invoice the moment it lands in someone’s mailbox, without logging in to upload it.') }}
        </flux:text>

        <p><strong>{{ __('To turn on inbound email:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Inbox email.') }}</li>
            <li>{{ __('Switch on “Accept documents by email.” A forwarding address in the form inbox+yourtoken@your-inbound-domain is generated for the company.') }}</li>
            <li>{{ __('Copy the Forwarding address and share it with your team — or add it as a contact so a supplier invoice can be forwarded in one tap.') }}</li>
            <li>{{ __('Select Save.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/inbox-email.png') }}"
            alt="{{ __('Settings → Inbox email showing the Accept documents by email switch, the copyable forwarding address, and the Read receipts automatically toggle') }}"
            caption="{{ __('Settings → Inbox email. Enabling inbound email reveals the forwarding address; “Generate new address” rotates it if it ever leaks.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('Who can send in') }}">
            {{ __('Only emails from your company’s active team members are accepted at the forwarding address — anything from an unknown sender is ignored, so the address is safe to share inside the team. If it is ever exposed, select “Generate new address” to rotate the token: the old address stops working immediately and a fresh one takes its place.') }}
        </x-docs.callout>

        <flux:text>
            {{ __('Just below, “Read receipts automatically” turns on optional AI reading of each forwarded or uploaded document: LineLedger extracts the vendor, total, and date so the draft bill is pre-filled and you only have to review it. This is off by default and opt-in — until you switch it on, documents go straight to manual review and nothing is ever sent to an AI service. Switch it on and select Save to enable it.') }}
        </flux:text>

        <x-docs.callout type="warning">
            {{ __('The forwarding address only appears once your operator has configured an inbound mail domain on the server. Until then, Settings → Inbox email shows a “not configured” notice instead of an address. Automatic reading also depends on the operator enabling the AI layer, so both you and the operator must opt in before any document is read.') }}
        </x-docs.callout>

        {{-- ──────────────────────────── Currencies ────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Currencies') }}</flux:heading>
        <flux:text>
            {{ __('Settings → Currencies is where you enable the foreign currencies you trade in, manage exchange rates, and run period-end revaluations. Your home currency is fixed when the company is created. See the Multi-currency page for how foreign transactions are recorded.') }}
        </flux:text>

        {{-- ──────────────────────── Backup & export ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Backup & export') }}</flux:heading>
        <flux:text>
            {{ __('Owners can create a full backup of a company from Settings → Backup & Export. The backup is built as a queued job, so it is not instant — when it is ready, you download a single ZIP through a time-limited link. The archive holds every record scoped to the company: the chart of accounts, transactions, attachments, and settings — ideal for keeping an off-site archive or moving your data elsewhere.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/backup-export.png') }}"
            alt="{{ __('Settings → Backup & Export with a Create backup button and a list of recent backups and their status') }}"
            caption="{{ __('Settings → Backup & Export. Each backup shows its status; once it is Ready, the Download link works for a limited time.') }}"
        />

        <p><strong>{{ __('To create a backup:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Backup & Export.') }}</li>
            <li>{{ __('Select Create backup. The job is queued and the backup shows as Pending, then Running.') }}</li>
            <li>{{ __('Wait a moment and refresh — the status changes to Ready when the ZIP is built.') }}</li>
            <li>{{ __('Select Download to save the ZIP. The link is valid for a limited time, so download it promptly.') }}</li>
        </ol>

        {{-- ──────────────────── Online payments (Stripe) ──────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Online payments (Stripe)') }}</flux:heading>
        <flux:text>
            {{ __('Company owners can connect a Stripe account so customers pay invoices by card through the customer portal. Connect or disconnect Stripe from the Online payments section of the company edit page; once connected, the accounts and payment method Stripe needs are created for you. See the Customer portal page for how payments flow into your books.') }}
        </flux:text>

        {{-- ──────────────────── Customizing your sidebar ──────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Customizing your sidebar') }}</flux:heading>
        <flux:text>
            {{ __('Under Settings → Sidebar you can tailor the sidebar to the work you actually do. Each group — Sales, Purchases, Banking, Reports, and so on — and every individual link inside it has its own toggle. Turn off a whole group to hide it along with all of its links, even if some of those links are still switched on individually. A Reset to defaults button brings everything back.') }}
        </flux:text>
        <flux:text>
            {{ __('Your choices are saved per person and per company: they only change what you see, never what your teammates see, and you can keep different layouts in different companies. In the sidebar itself you can collapse or expand each group as you work, and the Reports group keeps a Favorites quick-list of the reports you star.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/settings/navigation.png') }}"
            alt="{{ __('Settings → Sidebar showing per-group and per-link toggles for the sidebar') }}"
            caption="{{ __('Settings → Sidebar. Toggle whole groups or individual links; Reset to defaults restores everything. Only affects you.') }}"
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
            {{ __('Settings → Appearance switches between light, dark, and system themes. The app uses the Tidewater design system — a teal-accented OKLCH palette tuned for both light and dark modes so amounts, totals, and warnings stay readable in either theme. The choice is stored per user and applies everywhere in the app.') }}
        </flux:text>

        {{-- ──────────────────────────── API keys ──────────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('API keys') }}</flux:heading>
        <flux:text>
            {{ __('API keys grant programs and integrations access to your company’s data. You will find them in the API keys section at the bottom of Settings → Security. Each key is shown once at creation time — copy it then; you cannot retrieve it later. Revoke a key the moment it is no longer needed or you suspect it was leaked.') }}
        </flux:text>
        <flux:text>
            {{ __('See the') }}
            <a class="underline" href="{{ route('docs.api') }}" wire:navigate>{{ __('API documentation') }}</a>
            {{ __('for the full step-by-step on creating a key and which endpoints accept it.') }}
        </flux:text>

        {{-- ──────────────────── AI assistant (Business Q&A) ────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('AI assistant (Business Q&A)') }}</flux:heading>
        <flux:text>
            {{ __('LineLedger ships a read-only Model Context Protocol (MCP) connector that lets an AI assistant answer plain-language questions about Demo Company Inc.’s books — “who owes me money?”, “how did last quarter look?”, “am I low on any stock?” It reads only from your posted general ledger and can never create, edit, or delete anything. Connect it with a company API key; the API documentation covers the setup.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('Agentic actions are off by default') }}">
            {{ __('A separate, write-enabled mode lets the assistant propose changes — a draft invoice or journal entry, say — instead of only answering questions. It is deliberately gated twice: the server operator must enable it and the company must opt in, and even then nothing touches your ledger until you explicitly confirm each proposed change. With either switch off — the default — the assistant stays strictly read-only.') }}
        </x-docs.callout>
    </x-pages::docs.layout>
</section>
