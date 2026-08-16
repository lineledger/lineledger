<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Fixed assets')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Fixed assets')"
        :subheading="__('Keep a register of the equipment, vehicles, and property your business owns, and let LineLedger depreciate it for you.')"
    >
        <flux:text>
            {{ __('The Fixed assets register is where you record the long-lived things your business owns — equipment, vehicles, furniture, buildings. Each record keeps the cost, the in-service date, the depreciation details, and the accounts the asset uses, all in one place and separate from your day-to-day expenses. Once an asset is set up, LineLedger can draft its monthly depreciation for you. The examples below use our sample business, Demo Company Inc., and its Delivery Van.') }}
        </flux:text>

        <flux:text>
            {{ __('Open Fixed assets from the sidebar, under Accounting, to see the register. Each row shows the asset number, name, category, the date you acquired it, its cost, and its status.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/fixed-assets/list.png') }}"
            alt="{{ __('The Fixed assets register showing the Delivery Van, AST-025188, acquired 2025-11-27 at a cost of 35,000.00 and In service') }}"
            caption="{{ __('The Fixed assets register. Filter by status or category, search by number, name, or serial, and toggle “Show inactive” to include archived assets.') }}"
        />

        {{-- ──────────────────────── Record an asset ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Record an asset') }}</flux:heading>
        <flux:text>
            {{ __('Add an asset when you buy something that will serve the business for more than a year. You record it once; the register then holds its details and the accounts it depreciates against.') }}
        </flux:text>

        <p><strong>{{ __('To record an asset:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Fixed assets from the sidebar and select New asset.') }}</li>
            <li>{{ __('Under Identification, enter a Name (for example, Delivery Van). The Asset # fills in automatically — you can edit it. Optionally choose a Category and add a Description.') }}</li>
            <li>{{ __('Under Acquisition, set the Acquired date (it defaults to today), the In-service date, and the Cost.') }}</li>
            <li>{{ __('Under GL accounts, choose the Asset account — only fixed-asset accounts are listed — and, if you plan to depreciate, the Accumulated depreciation account and Depreciation expense account.') }}</li>
            <li>{{ __('Under Details, add a Serial number and Location if you want to track them.') }}</li>
            <li>{{ __('Under Depreciation, enter the Useful life (months) and the Salvage value, then optionally turn on automatic depreciation (see below).') }}</li>
            <li>{{ __('Under Status, leave the asset In service for now; you set this to Disposed, Sold, or Lost later when you retire it.') }}</li>
            <li>{{ __('Select Save asset.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/fixed-assets/create.png') }}"
            alt="{{ __('The New asset form with Identification, Acquisition, GL accounts, Details, Depreciation, and Status sections, including the auto-generate depreciation switch') }}"
            caption="{{ __('The New asset form. Turn on “Auto-generate monthly depreciation” to have LineLedger draft the entries for you; set the asset’s status in the same form when you eventually retire it.') }}"
        />

        <x-docs.callout type="tip">
            {{ __('Choose a Category and LineLedger fills in the three accounts and a default useful life for you, so every vehicle or every computer is booked consistently. You can still override any field on the individual asset. Categories are set up under Settings → Lists → Asset categories.') }}
        </x-docs.callout>

        {{-- ──────────────── Create an asset from a purchase ──────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Create an asset from a purchase') }}</flux:heading>
        <flux:text>
            {{ __('When you buy an asset, you usually record the purchase first — as a bill, a cheque, or a journal entry — and code the line to a fixed-asset account. LineLedger can turn that line straight into an asset record so you do not retype the details.') }}
        </flux:text>

        <p><strong>{{ __('To create an asset from a purchase:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the bill, cheque, or journal entry whose line hits a fixed-asset account.') }}</li>
            <li>{{ __('On that line, select the cube “Create asset record” button at the end of the row.') }}</li>
            <li>{{ __('The New asset form opens with the name, asset account, acquired date, and cost already filled in from the line. Add the depreciation details and Save asset.') }}</li>
        </ol>

        <x-docs.callout type="note">
            {{ __('An asset created this way keeps a Source link back to the bill, cheque, or journal entry it came from, so you can always trace the asset to the transaction that paid for it. The link shows on both the asset’s detail page and its edit form.') }}
        </x-docs.callout>

        {{-- ──────────────────────── Review an asset ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Review an asset') }}</flux:heading>
        <flux:text>
            {{ __('Open any asset to see everything on one page: its identification, acquisition cost and dates, the three GL accounts, its serial and location details, the depreciation schedule, and any files you have attached. Attach the purchase invoice or warranty documents here to keep the paper trail with the record. Select Edit to change any of it.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/fixed-assets/show.png') }}"
            alt="{{ __('The Delivery Van detail page showing a cost of 35,000.00, salvage value 5,000.00, useful life 60 months, and the asset account 1500 — Office Equipment') }}"
            caption="{{ __('The Delivery Van: cost 35,000.00, salvage value 5,000.00, and a 60-month useful life, posting to the Office Equipment asset account.') }}"
        />

        {{-- ──────────────────────── Automatic depreciation ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Automatic depreciation') }}</flux:heading>
        <flux:text>
            {{ __('LineLedger can keep an asset depreciating on its own using the straight-line method: it spreads the depreciable base — the cost minus the salvage value — evenly across the useful life you entered. Each month, once that month has fully ended, LineLedger drafts the depreciation entry for you so you no longer have to remember it.') }}
        </flux:text>

        <p><strong>{{ __('To turn on automatic depreciation:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open the asset and select Edit.') }}</li>
            <li>{{ __('Make sure the In-service date, the Useful life (months), the Accumulated depreciation account, and the Depreciation expense account are all set — these are required before the switch can be enabled.') }}</li>
            <li>{{ __('Under Depreciation, turn on Auto-generate monthly depreciation.') }}</li>
            <li>{{ __('Select Save asset.') }}</li>
        </ol>

        <flux:text>
            {{ __('The asset’s detail page then shows a Depreciation card with an “Auto-depreciation on” badge, the accumulated depreciation booked so far, the current net book value, and a month-by-month schedule. Each row in the schedule carries a status:') }}
        </flux:text>

        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Pending — a future month, or one whose draft has not been generated yet.') }}</li>
            <li>{{ __('Draft — LineLedger has generated the journal entry; review and post it to record the depreciation.') }}</li>
            <li>{{ __('Posted — the entry is posted; this month now counts toward accumulated depreciation and net book value.') }}</li>
            <li>{{ __('Voided — you voided the entry; that month is left as recorded and is not regenerated.') }}</li>
            <li>{{ __('Locked — the month ends inside a closed period, so LineLedger will not touch it. Record it by hand instead.') }}</li>
        </ul>

        <x-docs.figure
            src="{{ asset('docs/screenshots/fixed-assets/depreciation-schedule.png') }}"
            alt="{{ __('The Depreciation card on the Delivery Van showing the Auto-depreciation badge, accumulated total, net book value, and a month-by-month schedule with per-row status badges and journal-entry links') }}"
            caption="{{ __('The Depreciation card. Each month lists its straight-line amount, a status, and a link to the journal entry once one exists. The final month absorbs any rounding so the schedule totals the depreciable base exactly.') }}"
        />

        <x-docs.callout type="note" heading="{{ __('What automatic depreciation does to your books') }}">
            {{ __('LineLedger never posts depreciation behind your back. It creates one draft journal entry per month that debits the depreciation expense account and credits the accumulated depreciation account for the month’s amount — bundling every due asset onto the same entry. Nothing reaches your reports until you open that draft and post it. Until then, the asset’s accumulated total and net book value reflect only the months you have already posted.') }}
        </x-docs.callout>

        <x-docs.callout type="tip">
            {{ __('Find the drafts under Accounting → Journal — each is dated the last day of the month and memoed “Monthly depreciation.” Open one, check the amounts, and post it. Disposing of an asset stops its depreciation from the disposal month onward automatically.') }}
        </x-docs.callout>

        {{-- ──────────────────────── Manual depreciation ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Record depreciation manually') }}</flux:heading>
        <flux:text>
            {{ __('If you would rather not use automatic depreciation — or you need to cover a locked month, or you follow a method other than straight-line — record depreciation yourself with a journal entry on whatever schedule your accountant follows.') }}
        </flux:text>

        <p><strong>{{ __('To record depreciation by hand:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Accounting → Journal and select New entry.') }}</li>
            <li>{{ __('Debit the depreciation-expense account for the period’s depreciation.') }}</li>
            <li>{{ __('Credit the accumulated-depreciation account for the same amount.') }}</li>
            <li>{{ __('Post the entry.') }}</li>
        </ol>

        <x-docs.callout type="tip">
            {{ __('Because manual depreciation usually repeats every month for the same amount, set it up as a recurring journal entry so it posts on schedule without you rewriting it. Keeping the three accounts on the asset record makes the entry quick to fill in. Demo Company Inc. ships with a “Monthly depreciation” recurring template you can copy.') }}
        </x-docs.callout>

        {{-- ──────────────────────── Disposal ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Dispose of an asset') }}</flux:heading>
        <flux:text>
            {{ __('When an asset is sold, scrapped, or lost, mark it so on the record. Open the asset, select Edit, and in the Status section choose Disposed, Sold, or Lost. A Disposal date appears — it is required — along with a Disposal notes field. Save the asset and its detail page shows a Disposal section. The record stays in the register for history.') }}
        </flux:text>

        <x-docs.callout type="note">
            {{ __('Changing the status does not move money, and it stops automatic depreciation from the disposal month onward. Book any gain or loss and remove the asset and its accumulated depreciation from the balance sheet with a journal entry dated at the disposal — credit the asset account, debit accumulated depreciation, record the proceeds, and post the difference to a gain or loss account.') }}
        </x-docs.callout>

        {{-- ──────────────────────── Archive and delete ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Archive or delete an asset') }}</flux:heading>
        <flux:text>
            {{ __('Use the Actions menu on an asset’s detail page to Archive it — an archived asset drops out of the register unless you turn on “Show inactive,” but its history stays intact, which is the right choice for an asset you no longer track. Restore brings it back. Delete removes the record entirely and cannot be undone through the app, so reach for it only when you created an asset by mistake.') }}
        </flux:text>

        <x-docs.callout type="note">
            {{ __('When you migrate from QuickBooks, accumulated depreciation to date comes in as an opening balance, so the asset starts at its correct book value rather than its original cost.') }}
        </x-docs.callout>

        {{-- ──────────────────────── Related areas ──────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Related areas') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Accounting → Journal — review and post the drafted depreciation entries, and record disposal entries.') }}</li>
            <li>{{ __('Settings → Lists → Asset categories — set default accounts, a default useful life, and (for Canadian companies) a CCA class so new assets fill in consistently.') }}</li>
            <li>{{ __('Purchases → Bills and Banking → Cheques — record the purchase, then use the “Create asset record” button to turn the fixed-asset line into an asset.') }}</li>
        </ul>
    </x-pages::docs.layout>
</section>
