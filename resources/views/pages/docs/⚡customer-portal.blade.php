<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — Customer portal')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('Customer portal')"
        :subheading="__('A self-serve page where your customers view their statement, open invoices, and pay you online.')"
    >
        <flux:text>
            {{ __('The customer portal is a public, customer-facing page for your company, separate from the staff app. Your customers use it to see their account statement, open their invoices, download PDFs, and — once you turn on online payments — pay you by card. It lives at a /pay address for your company and needs no staff account. The examples below use our sample business, Demo Company Inc.') }}
        </flux:text>

        {{-- ───────────────────────── Signing in ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('How a customer signs in') }}</flux:heading>
        <flux:text>
            {{ __('Sign-in is passwordless. There are no passwords for your customers to manage or for you to reset — they get a one-time link by email each time. Every invoice you email also carries its own one-time link that drops the customer straight onto that invoice, so most of the time they never even type their address.') }}
        </flux:text>

        <p><strong>{{ __('To sign in to the portal directly:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('The customer opens your portal link and lands on the “View & pay your invoices” screen.') }}</li>
            <li>{{ __('They enter their Email address and select Send sign-in link.') }}</li>
            <li>{{ __('If the email matches an active customer with an address on file, the app emails a secure one-time link.') }}</li>
            <li>{{ __('They open the link to sign in for a session.') }}</li>
        </ol>

        <x-docs.figure
            src="{{ asset('docs/screenshots/customer-portal/login.png') }}"
            alt="{{ __('The customer portal sign-in screen with an email field and a Send sign-in link button') }}"
            caption="{{ __('The portal sign-in screen. The customer enters their email and receives a secure magic link.') }}"
        />

        <x-docs.callout type="note">
            {{ __('Magic links expire 15 minutes after they are issued, and sign-in requests are rate-limited, so there are no passwords to leak. To keep customer accounts private, the “if an account exists we sent a link” message shows whether or not the email matched. The customer session is entirely separate from your staff logins.') }}
        </x-docs.callout>

        {{-- ───────────────────────── What they can do ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('What customers can do') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('See a dashboard with their total balance due and every open invoice.') }}</li>
            <li>{{ __('View their account statement over a date range and download it as a PDF.') }}</li>
            <li>{{ __('Open any of their invoices and download the invoice PDF.') }}</li>
            <li>{{ __('Pay their outstanding balance online by card, when online payments are turned on.') }}</li>
        </ul>

        <x-docs.figure
            src="{{ asset('docs/screenshots/customer-portal/dashboard.png') }}"
            alt="{{ __('The customer portal dashboard showing the total due, a Pay now button, and a table of open invoices') }}"
            caption="{{ __('The portal dashboard. The total due sums every open invoice; “Pay now” appears only when there is a balance.') }}"
        />

        {{-- ───────────────────────── Statement ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('The statement') }}</flux:heading>
        <flux:text>
            {{ __('The statement lists every invoice, credit, and payment on the customer’s account with a running balance, exactly like the contact statement your staff can see. It opens on the current calendar year by default; the customer can change the Start and End dates and download a PDF to keep or forward.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/customer-portal/statement.png') }}"
            alt="{{ __('The portal account statement with a date range, opening balance, transaction rows, and closing balance') }}"
            caption="{{ __('The account statement. Adjust the date range, then Download PDF for a copy.') }}"
        />

        {{-- ───────────────────────── Opening an invoice ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Opening an invoice') }}</flux:heading>
        <flux:text>
            {{ __('Each invoice opens to its line items, subtotal, tax, total, and the balance still due. The customer can download the invoice PDF, and — when online payments are on and the invoice still has a balance — start a payment from here. If you bill in stages, the invoice also shows its payment schedule, and any “How to pay” instructions you set appear underneath.') }}
        </flux:text>

        <x-docs.figure
            src="{{ asset('docs/screenshots/customer-portal/invoice.png') }}"
            alt="{{ __('A single invoice in the portal showing line items, totals, balance due, and PDF and Pay now buttons') }}"
            caption="{{ __('An invoice in the portal. “Pay now” starts a card payment; “PDF” downloads a copy.') }}"
        />

        {{-- ───────────────────────── Paying by card ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Paying by card') }}</flux:heading>
        <flux:text>
            {{ __('Once you connect Stripe, customers can pay by card straight from the portal. Whichever “Pay now” button they use, the pay screen settles their whole outstanding balance: the app totals every open invoice on the server (never trusting the browser) and hands that amount to Stripe’s secure card form. Stripe processes the card and notifies the app, which records the payment automatically and emails the customer a confirmation.') }}
        </flux:text>

        <x-docs.callout type="note" heading="{{ __('What a card payment does to your books') }}">
            {{ __('A successful card payment posts back in the staff app as a customer receipt — posted automatically, not left as a draft. It debits the “Stripe Clearing” account and credits Accounts Receivable, applied across the customer’s open invoices oldest-due first, and is tagged with the “Card (Stripe)” payment method. Stripe’s processing fee posts as a separate journal entry that debits “Merchant Processing Fees” and credits Stripe Clearing, so the clearing account nets to what Stripe actually pays out to your bank. Payments are matched on Stripe’s identifier, so a webhook retry never double-posts. You will find the receipt under Sales → Receipts like any other.') }}
        </x-docs.callout>

        <x-docs.callout type="tip">
            {{ __('Prefer e-transfer or a bank deposit? Put your payment details in the “How to pay” instructions under Settings → Invoices. They show on the portal and on each invoice even if you never connect Stripe, so customers always know how to reach you.') }}
        </x-docs.callout>

        {{-- ───────────────────────── Turning it on ───────────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Turning on online payments') }}</flux:heading>
        <flux:text>
            {{ __('A company owner connects a Stripe account from the Organization settings page, under') }}
            <a class="underline" href="{{ route('docs.settings') }}" wire:navigate>{{ __('Online payments') }}</a>{{ __('. Selecting Connect Stripe sends the owner to Stripe to authorize the link, then brings them back. Connecting sets up everything the receipts need automatically — a “Stripe Clearing” asset account that receives the gross payment, a “Merchant Processing Fees” expense account, and a “Card (Stripe)” payment method — so card receipts post cleanly with no extra setup. Use Disconnect in the same place to turn online payments back off.') }}
        </flux:text>

        <x-docs.callout type="warning">
            {{ __('If your Stripe link is ever revoked or disconnected, card payments pause: the portal shows an “Online payments unavailable” notice, the Online payments setting shows “Stripe connection needs attention”, and the owner is emailed to reconnect. Existing invoices and statements stay fully readable the whole time — only the card form is hidden until you reconnect.') }}
        </x-docs.callout>
    </x-pages::docs.layout>
</section>
