<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — United Kingdom & VAT')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('United Kingdom & VAT')"
        :subheading="__('Keep a UK or Isle of Man organization’s books in pounds, charge VAT, and work out the nine boxes of your VAT return.')"
    >
        <flux:text>
            {{ __('An organization created with the United Kingdom as its country is set up the way a British business expects: pounds sterling, an April year end, a British chart of accounts, HM Revenue & Customs as its VAT agency, and day-first dates on everything it prints. This page covers what is different from a Canadian or US organization. Everything else — invoicing, bills, banking, reports — works exactly as the rest of this documentation describes.') }}
        </flux:text>

        {{-- ───────────────────────── Set up ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Set up a UK organization') }}</flux:heading>
        <flux:text>
            {{ __('Choose United Kingdom as the Country on the first step of the setup wizard, then pick your Nation: England, Scotland, Wales, Northern Ireland, or the Isle of Man. The base currency fills in as pounds sterling and the fiscal year starts in April; change the month to your company’s accounting reference date if it differs. The Timezone comes from your browser rather than the country, so check that it reads London, Dublin, Lisbon. The country cannot be changed once the organization exists, so pick it carefully.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/united-kingdom/wizard-uk.png') }}"
            alt="{{ __('Step 1 of the setup wizard with United Kingdom chosen as the Country, England as the Nation, pounds sterling as the Base currency, London, Dublin, Lisbon as the Timezone, and April as the Fiscal year start month') }}"
            caption="{{ __('Step 1 of the wizard for a UK organization. Pounds sterling and an April year start are filled in for you.') }}"
        />

        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('The organization types use UK names: Sole trader, Partnership / LLP, Limited company (Ltd), Non-profit, and Other / None. Non-profit then asks for an Unincorporated association or a Non-profit corporation. Registered charity is a Canadian (CRA) structure and is not offered, so a UK charity can keep its books as a non-profit but cannot issue Gift Aid or other donation receipts from LineLedger.') }}</li>
            <li>{{ __('A limited company or an LLP is asked for its Company number — the Companies House number, or the Isle of Man Companies Registry number. It is optional, and you can add or change it later on the organization’s edit page. A number of up to seven digits is padded with leading zeros to the usual eight (12345 becomes 00012345); a number with a prefix, such as SC123456, is kept as typed, in capitals and without spaces.') }}</li>
            <li>{{ __('The sales tax step asks Are you VAT-registered? Switch on I’m VAT-registered and charge VAT to add a VAT Payable account, HM Revenue & Customs as your VAT agency, and four tax codes: VAT (Standard VAT, 20%), VAT-R (Reduced VAT, 5%), ZR (Zero-rated, 0%), and EX (Exempt). Leave it off if you are not registered.') }}</li>
            <li>{{ __('Equity follows the organization type — a limited company gets 3100 Ordinary Shares — and the bank account is named Current Account. The starting payment methods are Cash, Cheque, BACS, Faster Payments, Direct Debit, Wire, and Credit card.') }}</li>
        </ul>

        <x-docs.callout type="note" heading="{{ __('The Isle of Man') }}">
            {{ __('Isle of Man businesses are inside the UK VAT area, but their VAT is run by the Isle of Man Customs and Excise Division. Pick Isle of Man as the Nation and the VAT agency is named Isle of Man Customs and Excise instead of HM Revenue & Customs. Moving an existing organization onto or off the island in its settings renames the agency to match, unless you have renamed it yourself.') }}
        </x-docs.callout>

        {{-- ───────────────────────── VAT numbers ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Your VAT number') }}</flux:heading>
        <flux:text>
            {{ __('The VAT registration number is entered on the wizard’s VAT step or later under Settings → Invoices, and is checked for the shape HMRC issues: GB followed by 9 digits, or 12 for a branch or group member, or GBGD or GBHA followed by 3 digits for a government department or health authority. Northern Ireland traders can enter an XI number. Spaces, dots, and dashes are dropped and a bare 9- or 12-digit number gets its GB prefix, so 123 4567 89 is stored as GB123456789. A number that does not fit is refused when you save.') }}
        </flux:text>

        {{-- ───────────────────────── Documents ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Invoices and other forms') }}</flux:heading>
        <flux:text>
            {{ __('A UK organization’s printed forms — invoices, estimates, credit memos, sales orders, receipts, statements, bills, purchase orders, vendor credits, and bill payments — write dates day first, so 04/03/2026 is 4 March, and show amounts with the document’s own currency symbol: £ on a sterling invoice, € on one in euros. The footer prints the VAT number as VAT No. (when Show tax number on invoices is on in Settings → Invoices) and, beneath it, the Company number whenever one is on file.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/united-kingdom/invoice-uk.png') }}"
            alt="{{ __('A UK invoice PDF with amounts in pounds, a 20% VAT line, day-first invoice and due dates, and the VAT No. and Company number in the footer') }}"
            caption="{{ __('An invoice from Thames Valley Trading Ltd. Dates are day first, amounts are in pounds, and the VAT and company numbers print in the footer.') }}"
        />

        {{-- ───────────────────────── VAT Return report ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('The VAT Return report') }}</flux:heading>
        <flux:text>
            {{ __('Reports → All Reports → VAT Return (under Sales Tax) works out the nine boxes of an HMRC VAT return from your books. It opens on the last complete calendar quarter; the Quarter menu offers Last quarter and This quarter, and the Start and End dates take any other period — type them in if your VAT quarters do not follow the calendar. With more than one VAT agency, an Agency list picks which one. CSV downloads the boxes.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/united-kingdom/vat-return.png') }}"
            alt="{{ __('The VAT Return report showing Boxes 1 to 9 with their descriptions and amounts, Boxes 3 and 5 in bold, the Start and End dates, the Quarter menu, and the CSV button') }}"
            caption="{{ __('The VAT Return report for a quarter. Boxes 1 to 5 carry pence; Boxes 6 to 9 are whole pounds.') }}"
        />

        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Box 1, VAT due on sales, and Box 4, VAT reclaimed on purchases, are the same figures a tax return for the period shows as Collected and Paid (Input VAT). They come from the VAT Payable account in the ledger, so VAT keyed in a journal entry counts too.') }}</li>
            <li>{{ __('Box 3 is Box 1 plus Box 2, and Box 5 is Box 3 less Box 4. When Box 4 is larger, Box 5 reads Net VAT to be reclaimed from HMRC and the line beneath the table says the agency owes you.') }}</li>
            <li>{{ __('Box 6 is the value before VAT of your sales — invoices and sales receipts less credit memos — and Box 7 of your purchases — bills, expenses, and cheques less vendor credits. Only lines coded to one of the agency’s tax codes count, including zero-rated and exempt ones; a line with no tax code is treated as outside the scope of VAT and left out, as are opening-balance documents. Amounts are in pounds, converted at each document’s own rate, with the pence dropped as HMRC asks.') }}</li>
            <li>{{ __('Boxes 2, 8, and 9 cover Northern Ireland’s trade in goods with the EU, which the books do not separate, so they always show zero. Fill them in when you file if they apply to you.') }}</li>
            <li>{{ __('A voided document counts where the ledger puts it: in its own period, and again — taken off — in the period of its reversing entry.') }}</li>
        </ul>

        <x-docs.callout type="warning" heading="{{ __('LineLedger does not file with HMRC') }}">
            {{ __('Nothing is submitted to HMRC. Copy the boxes into Making Tax Digital–compatible software to file, and check them there. Special schemes — the Flat Rate Scheme, cash accounting, and the reverse charge — are not worked out.') }}
        </x-docs.callout>

        {{-- ───────────────────────── UK tax returns ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Filing a UK VAT return') }}</flux:heading>
        <flux:text>
            {{ __('To review the transactions behind the boxes, leave some out, or add adjustments, file a tax return for the quarter as described in') }}
            <a class="underline" href="{{ route('docs.tax-returns') }}" wire:navigate>{{ __('Tax returns') }}</a>{{ __('. A UK return’s page adds a VAT return (nine boxes) panel under the tiles. On a draft it is worked out live and follows every line you leave out — a document left out comes off Boxes 6 and 7 as well as Box 1 or Box 4. An Other adjustment has no box of its own, so it goes where HMRC puts small corrections: an increase in what you owe in Box 1, a decrease in Box 4. Box 5 therefore always equals the return’s Net owing.') }}
        </flux:text>
        <flux:text>
            {{ __('Filing freezes the nine boxes with the rest of the snapshot, and the panel then reads As filed. They no longer change if a document in the period is edited later, so the return keeps showing what you sent HMRC.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/united-kingdom/tax-return-filed.png') }}"
            alt="{{ __('A filed UK tax return showing the Collected, Paid (Input VAT), Adjustments, and Net owing tiles above the VAT return (nine boxes) panel marked As filed') }}"
            caption="{{ __('A filed UK return. The nine boxes are frozen at filing, beside the usual tiles and snapshot.') }}"
        />

        {{-- ───────────────────────── Statutory accounts ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Statutory accounts') }}</flux:heading>
        <flux:text>
            {{ __('Reports → Accountant & Taxes → Statutory Accounts produces the year-end accounts a small company files, as one PDF laid out the way an accountant presents them: a cover, company information and contents, then numbered pages with the directors’ report, the primary statements and the notes, each with the prior year alongside. Download it as a PDF to file, or as a Word document to edit the wording first — the Word version has the same layout, and its page numbers and contents page are live fields that Word updates when the file opens. Choose the framework at the top: FRS 102 Section 1A (small entities) gives the Statement of Comprehensive Income, Statement of Financial Position, Statement of Changes in Equity and full notes; FRS 105 (micro-entities) gives the shorter balance sheet, an optional profit and loss account and the three notes a micro-entity discloses.') }}
        </flux:text>
        <flux:text>
            {{ __('The period is a pair of dates rather than the fiscal-year setting, so a year ending on 5 April works as well as one ending on 31 March: set the year-end day and month once on the Company details tab, and Last year end picks the most recent completed year. The summary on the Accounts tab runs from the year you pick to the latest completed one, a column per year, so you can see the trend at a glance; the documents themselves always show the year you picked with its comparative. Every figure is worked to the penny and then each line is rounded to the pound on its own, with totals adding up the rounded lines; retained earnings is the balancing figure, so the balance sheet always balances and the change in equity reconciles to it.') }}
        </flux:text>
        <flux:text>
            {{ __('The Account mapping tab shows the line every account presents on. Each account starts on a sensible default worked out from its type and name — a dividend account is shown as a distribution in the change in equity rather than as an expense, a depreciation account as the year’s charge, a director’s loan as a creditor — and you can move any account to another line. The caption groups accounts within a line: accounts sharing a caption are added together on the income statement, in the debtors and creditors notes, and as one asset class in the tangible fixed assets note.') }}
        </flux:text>
        <flux:text>
            {{ __('The Company details tab holds what the accounts say beyond the numbers: the legislation they are prepared under (UK Companies Act 2006 with the section 477 audit-exemption statements, or the Isle of Man Companies Acts), the company number and registered office, directors and their shareholdings, the secretary, principal activity, depreciation rates, the text of the taxation, related-party and other notes, the approval date and who signs.') }}
        </flux:text>
        <x-docs.callout type="note" heading="{{ __('Fixed asset notes') }}">
            {{ __('The tangible fixed assets note reads cost and accumulated depreciation from separate accounts for each asset class. An asset class kept net in a single account still shows the right net book value, additions and charge, but its cost and depreciation brought forward restart each year — split it into cost and depreciation accounts for a complete note.') }}
        </x-docs.callout>
        <x-docs.callout type="warning" heading="{{ __('LineLedger does not file accounts') }}">
            {{ __('Download the PDF and file it with Companies House or the Isle of Man Companies Registry yourself, or hand it to your accountant. LineLedger does not produce iXBRL or a CT600 corporation tax return.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Not available ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('What is not available for UK organizations') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Payroll. The payroll engine is built on Canadian rules, so the Payroll switch is not offered; run UK payroll in other software and post its totals with a journal entry.') }}</li>
            <li>{{ __('Gift Aid and charity donation receipts — see Set up a UK organization above.') }}</li>
            <li>{{ __('Making Tax Digital submission, and the VAT special schemes.') }}</li>
            <li>{{ __('Online filing of statutory accounts (iXBRL) and the CT600 corporation tax return.') }}</li>
            <li>{{ __('Settings → Tax & filing and the CRA income-tax reports, which are for Canadian organizations.') }}</li>
        </ul>

        <flux:text>
            {{ __('Running your own LineLedger server for UK customers? APP_REGION and the .uk host settings are covered on') }}
            <a class="underline" href="{{ route('docs.self-hosting') }}" wire:navigate>{{ __('Self-hosting & upgrades') }}</a>{{ __('.') }}
        </flux:text>
    </x-pages::docs.layout>
</section>
