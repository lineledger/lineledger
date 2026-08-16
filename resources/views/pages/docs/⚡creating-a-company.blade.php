<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Create an organization')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Create an organization')"
        :subheading="__('Set up a new organization with the setup wizard — for any entity type.')"
    >
        <flux:text>
            {{ __('A LineLedger account starts empty. The setup wizard appears automatically the first time you sign in, asks a few quick questions, and uses your answers to tailor the chart of accounts, sales-tax setup, and terminology to your organization. The whole thing takes a couple of minutes, and you can change almost everything later in settings.') }}
        </flux:text>

        <flux:text>
            {{ __('You will meet the wizard again every time you add another company — one login can keep the books for as many businesses, clubs, or charities as you like, each fully separate. Reach it later from the company switcher at the top of the sidebar by choosing New organization.') }}
        </flux:text>

        <p><strong>{{ __('To create an organization:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Enter your organization info — name, country, region, currency, timezone, and fiscal-year start.') }}</li>
            <li>{{ __('Tell the wizard how your organization is organized — sole proprietor, corporation, non-profit, and so on.') }}</li>
            <li>{{ __('Pick your industry and chart-of-accounts style.') }}</li>
            <li>{{ __('Choose what you want to track by turning feature modules on or off.') }}</li>
            <li>{{ __('Set up sales tax — whether you charge federal (and any provincial) sales tax and your registration numbers.') }}</li>
            <li>{{ __('Choose how to start — fresh, by importing, or by restoring a backup.') }}</li>
            <li>{{ __('Review the proposed chart of accounts and uncheck anything you do not need.') }}</li>
            <li>{{ __('Confirm the summary and create the organization.') }}</li>
        </ol>

        <flux:text>
            {{ __('A side panel tracks your progress through the eight steps. You can step back to any earlier answer with Back or by selecting a completed step in the panel; Continue moves you forward, validating the current step as it goes. The sections below walk through each step in turn.') }}
        </flux:text>

        {{-- ─────────────────── Step 1 — Organization info ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Step 1 — Organization info') }}</flux:heading>
        <flux:text>
            {{ __('Start with the basics about your organization. These settings establish the jurisdiction, money, and calendar your books run on.') }}
        </flux:text>

        <p><strong>{{ __('To enter your organization info:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Type the Organization name — the legal or trading name your customers will recognize. It appears on invoices, statements, and emails.') }}</li>
            <li>{{ __('Choose your Country — Canada or the United States. This sets jurisdiction-specific accounts and tax wording.') }}</li>
            <li>{{ __('Pick your Region — the province or state. Canada shows provinces; the United States shows states.') }}</li>
            <li>{{ __('Select your Base currency — Canadian or US dollars. It is pre-filled to match the country.') }}</li>
            <li>{{ __('Set your Timezone. The wizard guesses it from your browser; transaction dates default to today in this zone.') }}</li>
            <li>{{ __('Choose your Fiscal year start month. The wizard shows the matching year-end date as you pick.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/creating-a-company/wizard-organization.png') }}"
            alt="{{ __('Step 1 of the wizard with fields for organization name, country, region, base currency, timezone, and fiscal-year start month') }}"
            caption="{{ __('Step 1 — Organization info. The country drives the currency, tax wording, and jurisdiction-specific accounts for the rest of the wizard.') }}"
        />

        <x-docs.callout type="warning" heading="{{ __('Country is permanent') }}">
            {{ __('Choose your country carefully — it cannot be changed after the company is created. It determines the core chart of accounts, the sales-tax model (GST/HST in Canada, Sales Tax in the United States), and which features are offered. Everything else on this step can be adjusted later in settings, but the country is fixed for the life of the company.') }}
        </x-docs.callout>

        {{-- ─────────── Step 2 — How your organization is organized ─────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Step 2 — How your organization is organized') }}</flux:heading>
        <flux:text>
            {{ __('This step decides how your equity section is named and whether you get non-profit fund accounting. There are seven categories: Sole proprietorship, Partnership, Corporation, Club / Association, Non-profit, Charity, and Other / None. In the wizard the three not-for-profit categories are collapsed into a single "Non-profit or charity" choice, and a follow-up question pins down which one.') }}
        </flux:text>

        <p><strong>{{ __('To set how your organization is organized:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Choose the category that fits. For a for-profit business (sole proprietorship, partnership, corporation) or Other / None, your choice is the organization type — you are done with this step.') }}</li>
            <li>{{ __('If you choose Non-profit or charity, a second question appears asking for your legal structure tier: Unincorporated association, Non-profit corporation, or Registered charity.') }}</li>
            <li>{{ __('Pick a Registered charity and the wizard asks for your CRA charity registration number (a Business Number with an RR program account, like 123456789RR0001). You can add it later in settings to start issuing donation receipts.') }}</li>
            <li>{{ __('Pick an Unincorporated association and the books are set up for member dues using the Deferral contribution method — no grants or restricted funds. Other tiers let you choose how to account for restricted contributions.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/creating-a-company/wizard-structure.png') }}"
            alt="{{ __('Step 2 of the wizard showing the organization-type choices with the non-profit legal-structure tier revealed below') }}"
            caption="{{ __('Step 2 — How your organization is organized. Choosing “Non-profit or charity” reveals the legal-structure tier that pins down the exact type.') }}"
        />

        <x-docs.callout type="note">
            {{ __('The hover help (the question-mark icons) explains each option in plain language if you are unsure of the legal terms. In the United States the “Registered charity” tier and the CRA wording are hidden, since they are Canada Revenue Agency concepts.') }}
        </x-docs.callout>

        {{-- ─────────────────── Step 3 — Industry & accounts ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Step 3 — Industry & accounts') }}</flux:heading>
        <flux:text>
            {{ __('Now choose how to build your chart of accounts. A segmented control at the top of the step offers three ways to start, and the rest of the step changes to match your pick.') }}
        </flux:text>

        <p><strong>{{ __('To choose your chart of accounts:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Standardized accounts — start with a complete, industry-tailored chart, then select your Industry from the list below. This is the usual choice.') }}</li>
            <li>{{ __('Copy an existing organization — reuse the chart from another organization you already keep books for. Pick the source organization, and the wizard copies its account codes, names, descriptions, GIFI codes, and sub-account nesting so you can review and trim it on the next steps. If you do not belong to any other organization, this option simply tells you there is nothing to copy.') }}</li>
            <li>{{ __("Start minimal — I'll add my own — create only the required system accounts (bank, receivables, payables, tax, and equity) and build the rest by hand.") }}</li>
        </ol>

        <flux:text>
            {{ __('When you choose Standardized accounts, the wizard offers ten industries: General business, Contractor / Construction, Non-profit, Manufacturing, Retail, Professional services, Health & Wellness, Restaurant / Food & Beverage, Real estate / Property management, and Freelancer / Creative. Your industry choice also pre-selects sensible defaults on the next step — for example, a manufacturer starts with inventory turned on. You can override every one of those toggles.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/creating-a-company/wizard-chart-mode.png') }}"
            alt="{{ __('Step 3 of the wizard showing the Standardized accounts, Copy an existing organization, and Start minimal options above the industry list') }}"
            caption="{{ __('Step 3 — Industry & accounts. Start from a standardized industry chart, copy the chart from an organization you already keep, or begin with only the required accounts.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('Not sure which industry to pick? Choose the closest match, or use General business. The chart is just a starting point — you can add, rename, and deactivate accounts at any time, so nothing here locks you in.') }}
        </x-docs.callout>

        {{-- ─────────────────── Step 4 — What do you want to track ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Step 4 — What do you want to track') }}</flux:heading>
        <flux:text>
            {{ __('Turn on the modules your organization needs. Each switch adds a feature area to the app; leaving one off keeps the interface lean. Every toggle can be changed later in company settings.') }}
        </flux:text>

        <p><strong>{{ __('To choose what you track:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Review the suggested toggles — the wizard pre-sets them from the industry you picked.') }}</li>
            <li>{{ __('Switch each module on or off to match how you work.') }}</li>
        </ol>

        <flux:text>{{ __('The available modules are:') }}</flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Inventory — track stock on hand, costing, and cost of goods sold.') }}</li>
            <li>{{ __('Employees — track employees and reimbursements.') }}</li>
            <li>{{ __('Payroll — run Canadian payroll with CPP/EI/income tax and T4/ROE filings. Offered for Canadian companies only.') }}</li>
            <li>{{ __('Fixed assets — track capital assets and depreciation.') }}</li>
            <li>{{ __('Estimates — send customer estimates and convert them to invoices.') }}</li>
            <li>{{ __('Sales orders — track customer sales orders and fulfil them with invoices.') }}</li>
            <li>{{ __('Recurring invoices — schedule recurring customer invoices.') }}</li>
            <li>{{ __('Recurring bills — schedule recurring vendor bills.') }}</li>
            <li>{{ __('Classes — tag transactions to slice reports by segment, department, or program.') }}</li>
            <li>{{ __('Locations — tag transactions to slice reports by site, branch, or property.') }}</li>
            <li>{{ __('Budgets — plan account-level budgets and compare them against actuals.') }}</li>
            <li>{{ __('Membership — track members and levels, and bill recurring dues as invoices.') }}</li>
            <li>{{ __('Donations & grants — record donation and grant income, track restricted funding, and issue donation receipts.') }}</li>
        </ul>

        <x-docs.figure
            src="{{ asset('docs/screenshots/creating-a-company/wizard-features.png') }}"
            alt="{{ __('Step 4 of the wizard listing feature toggles for inventory, employees, payroll, fixed assets, estimates, and more') }}"
            caption="{{ __('Step 4 — What do you want to track. Payroll appears only for Canadian companies; the rest are available everywhere.') }}"
        />

        {{-- ─────────────────── Step 5 — Sales tax ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Step 5 — Sales tax') }}</flux:heading>
        <flux:text>
            {{ __('Tell the wizard whether you charge sales tax so it can set up the right tax codes and payable accounts. The wording follows your country: Canada asks about GST/HST, while the United States asks about Sales Tax.') }}
        </flux:text>

        <p><strong>{{ __('To set up sales tax:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Turn the I charge GST/HST switch on if you charge federal sales tax, or off if you do not.') }}</li>
            <li>{{ __('If your province also levies its own provincial sales tax — PST in British Columbia and Saskatchewan, RST in Manitoba, or QST in Quebec — a second switch appears. Turn it on to add a provincial tax-payable account and tax code for that province.') }}</li>
            <li>{{ __('When a tax is switched on, optionally enter its account (registration) number. The federal and provincial taxes have separate number fields. You can show them on invoices later from settings.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/creating-a-company/wizard-sales-tax.png') }}"
            alt="{{ __('Step 5 of the wizard for a British Columbia company showing the GST/HST switch, the PST switch, and the two registration-number fields') }}"
            caption="{{ __('Step 5 — Sales tax for a British Columbia company. Provinces that levy a separate provincial sales tax get a second switch and its own registration-number field.') }}"
        />

        <x-docs.callout type="note">
            {{ __('The provincial switch only appears for provinces that actually levy a separate tax. HST provinces (such as Ontario) and GST-only provinces (such as Alberta) collect everything through the single GST/HST line, so no second switch is shown. In the United States the step asks a single Sales Tax question.') }}
        </x-docs.callout>

        {{-- ─────────────────── Step 6 — How do you want to start ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Step 6 — How do you want to start') }}</flux:heading>
        <flux:text>
            {{ __('Choose how to bring your books to life: begin from scratch, import from QuickBooks, or restore a backup from another LineLedger instance.') }}
        </flux:text>

        <p><strong>{{ __('To choose how to start:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Start fresh — begin from a start date with the chart of accounts you just chose. Set the start date, usually the beginning of your fiscal year or today.') }}</li>
            <li>{{ __('Import from QuickBooks — bring in your existing data. Pick Opening balances / trial balance to load lists and balances as of a conversion date, or Full transaction history to replay every QuickBooks transaction into the ledger. The wizard seeds only the required accounts and the import supplies the rest.') }}</li>
            <li>{{ __('Restore from a backup — upload a backup ZIP exported from another LineLedger instance to rebuild a company here. You choose the file on the next screen.') }}</li>
        </ol>

        <x-docs.callout type="note">
            {{ __('The import and restore paths branch off here. Importing creates the company and then opens the QuickBooks import right inside the wizard, and you can choose “Finish later” to do it from the dashboard. Restoring hands you off to the backup-upload screen. The Fresh path continues to the chart review in step 7.') }}
        </x-docs.callout>

        {{-- ─────────────────── Step 7 — Review chart of accounts ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Step 7 — Review chart of accounts') }}</flux:heading>
        <flux:text>
            {{ __('The wizard shows the exact chart it will create, grouped by Asset, Liability, Equity, Income, and Expense. This is your chance to trim it before any accounts exist.') }}
        </flux:text>

        <p><strong>{{ __('To review the chart of accounts:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Scan each group of accounts.') }}</li>
            <li>{{ __('Uncheck any industry account you do not need. Required system accounts are locked on and marked System or Required — they cannot be removed.') }}</li>
        </ol>

        <flux:text>
            {{ __('You can always edit, add, or deactivate accounts later, so trimming here is purely to keep the starting chart tidy. On the minimal-chart path this step still appears but shows only the locked required accounts — there is nothing to uncheck. The QuickBooks import path skips ahead instead: the company is created at step 6 and the import opens right away, since QuickBooks supplies the operating accounts.') }}
        </flux:text>

        {{-- ─────────────────── Step 8 — Ready to create ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Step 8 — Ready to create') }}</flux:heading>
        <flux:text>
            {{ __('The final step summarizes your choices: company name, jurisdiction and currency, organization type (plus legal structure and contribution method for non-profits), and the chart of accounts with a count of selected accounts.') }}
        </flux:text>

        <p><strong>{{ __('To create the company:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Check the summary. Use Back or the step panel to fix anything that is off.') }}</li>
            <li>{{ __('Select Create organization. The wizard builds the chart of accounts, applies your settings, and drops you on the new organization’s dashboard.') }}</li>
        </ol>

        {{-- ─────────────────── Examples by organization type ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Examples by organization type') }}</flux:heading>
        <flux:text>
            {{ __('Your Step 2 answers reshape the equity section of the chart — and, for non-profits, add fund-accounting accounts. Here is what four common setups produce, using the standardized General chart for the for-profit examples and the Non-profit chart for the rest.') }}
        </flux:text>

        <flux:heading size="md" class="mt-6">{{ __('Sole proprietorship') }}</flux:heading>
        <flux:text>
            {{ __('Choose Sole proprietorship in Step 2. The equity section is named for a single owner:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('3100 Owner Contributions') }}</li>
            <li>{{ __('3200 Owner Draws') }}</li>
        </ul>
        <flux:text>
            {{ __('Alongside the locked core accounts (3000 Opening Balance Equity and the system 3900 Retained Earnings). A partnership is identical except the lines read Partner Contributions and Partner Draws.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/creating-a-company/chart-soleprop.png') }}"
            alt="{{ __('The equity section of a sole-proprietorship chart showing Owner Contributions and Owner Draws') }}"
            caption="{{ __('A sole proprietorship’s equity section: Owner Contributions and Owner Draws.') }}"
        />

        <flux:heading size="md" class="mt-6">{{ __('Corporation') }}</flux:heading>
        <flux:text>
            {{ __('Choose Corporation in Step 2. The equity section uses share-capital terminology that follows your country:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('3100 Common Shares (Canada) or Common Stock (United States)') }}</li>
            <li>{{ __('3200 Shareholder Distributions') }}</li>
        </ul>
        <flux:text>
            {{ __('Again sitting beside 3000 Opening Balance Equity and the system 3900 Retained Earnings.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/creating-a-company/chart-corporation.png') }}"
            alt="{{ __('The equity section of a corporation chart showing Common Shares and Shareholder Distributions') }}"
            caption="{{ __('A Canadian corporation’s equity section: Common Shares and Shareholder Distributions. In the US the line reads Common Stock.') }}"
        />

        <flux:heading size="md" class="mt-6">{{ __('Non-profit corporation') }}</flux:heading>
        <flux:text>
            {{ __('Choose Non-profit or charity in Step 2, then the Non-profit corporation tier. The chart switches to fund accounting: the system 3900 line is relabeled Net Assets, and the wizard adds net-asset classes and deferred-contribution liabilities:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('2500 Deferred / Restricted Grants') }}</li>
            <li>{{ __('2510 Deferred Membership / Program Revenue') }}</li>
            <li>{{ __('3100 Unrestricted Net Assets') }}</li>
            <li>{{ __('3200 Restricted Net Assets') }}</li>
        </ul>
        <flux:text>
            {{ __('A registered charity gets the same set plus 3300 Endowment Net Assets and a CRA registration number for issuing donation receipts.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/creating-a-company/chart-nonprofit.png') }}"
            alt="{{ __('The chart of a non-profit corporation showing Net Assets, deferred grant and program liabilities, and unrestricted and restricted net-asset classes') }}"
            caption="{{ __('A non-profit corporation’s chart: Net Assets, deferred-contribution liabilities, and unrestricted/restricted net-asset classes.') }}"
        />

        <flux:heading size="md" class="mt-6">{{ __('Unincorporated association') }}</flux:heading>
        <flux:text>
            {{ __('Choose Non-profit or charity in Step 2, then the Unincorporated association tier — the option for a club or association funded by member dues. This produces the lightest non-profit set: no grants, restricted funds, or endowment. The 3900 line is relabeled Net Assets, the contribution method is Deferral, and the wizard adds:') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('2510 Deferred Membership Dues') }}</li>
            <li>{{ __('3100 Unrestricted Net Assets') }}</li>
            <li>{{ __('4200 Membership Dues (income)') }}</li>
        </ul>
        <flux:text>
            {{ __('This is the same chart you get by picking Club / Association directly — membership-dues income, a deferred-dues liability for dues paid in advance, and a single unrestricted net-asset line.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/creating-a-company/chart-association.png') }}"
            alt="{{ __('The chart of an unincorporated association showing Net Assets, Deferred Membership Dues, Unrestricted Net Assets, and Membership Dues income') }}"
            caption="{{ __('An unincorporated association’s chart: the lightest non-profit set, built around membership dues.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('Registered non-profits and charities are automatically exempted from billing — running your organization’s books on LineLedger is free. The wizard recognizes the not-for-profit organization types and applies the exemption when the company is created.') }}
        </x-docs.callout>

        <x-docs.callout type="note">
            {{ __('You can change most of these choices later under Settings → Organizations — the organization type, legal structure, contribution method, industry, features, and tax details are all editable. The one thing you cannot change is the country, which is fixed when the company is created.') }}
        </x-docs.callout>

        {{-- ─────────────────── Where to go next ─────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Where to go next') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li><a class="underline" href="{{ route('docs.members') }}" wire:navigate>{{ __('Members') }}</a> — {{ __('track members and bill recurring dues if you turned on Membership.') }}</li>
            <li><a class="underline" href="{{ route('docs.fundraising') }}" wire:navigate>{{ __('Donations & grants') }}</a> — {{ __('record contributions and issue receipts if you turned on Donations & grants.') }}</li>
            <li><a class="underline" href="{{ route('docs.lists') }}" wire:navigate>{{ __('Funds') }}</a> — {{ __('track restricted funding against your net-asset classes.') }}</li>
            <li><a class="underline" href="{{ route('docs.settings') }}" wire:navigate>{{ __('Settings') }}</a> — {{ __('revisit any of these choices, except the country, after setup.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
