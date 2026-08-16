<?php

use App\Enums\ReceiptStatus;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Company;
use App\Models\CustomerReceipt;
use App\Services\Posting\ReceiptPoster;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Receipt')] class extends Component {
    public Company $company;

    public CustomerReceipt $receipt;

    public function mount(Company $company, CustomerReceipt $receipt): void
    {
        $this->company = $company;
        $this->receipt = $receipt->load('contact', 'depositToAccount', 'applications.invoice', 'journalEntry');
    }

    public function void(ReceiptPoster $poster): void
    {
        try {
            $poster->void($this->receipt);
        } catch (PeriodLockedException|\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Receipt voided.'));
        $this->redirectRoute('receipts.index', ['company' => $this->company->slug], navigate: true);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Receipt') }} {{ $receipt->receipt_no }}</flux:heading>
            <flux:subheading>
                {{ $receipt->contact->display_name }} &middot;
                {{ $receipt->receipt_date->toDateString() }} &middot;
                {{ __('Deposited to') }} {{ $receipt->depositToAccount->name }}
            </flux:subheading>
            <div class="mt-2 hidden items-center gap-2 lg:flex">
                @switch($receipt->status->value)
                    @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                    @case('posted') <flux:badge color="green">{{ __('Posted') }}</flux:badge> @break
                    @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                @endswitch

                @if ($receipt->journal_entry_id)
                    <flux:badge color="zinc">
                        <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $receipt->journal_entry_id]) }}" wire:navigate class="underline">
                            {{ __('GL entry') }} {{ optional($receipt->journalEntry)->entry_no }}
                        </a>
                    </flux:badge>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:dropdown align="end">
                <flux:button icon:trailing="chevron-down" data-test="receipt-actions-menu">{{ __('Actions') }}</flux:button>
                <flux:menu>
                    <flux:menu.item icon="printer" :href="route('receipts.print', ['company' => $company->slug, 'receipt' => $receipt->id])" target="_blank" data-test="print-receipt-button">
                        {{ __('Print') }}
                    </flux:menu.item>
                    @if ($receipt->status !== ReceiptStatus::Void)
                        <flux:menu.item icon="pencil" :href="route('receipts.edit', ['company' => $company->slug, 'receipt' => $receipt->id])" wire:navigate data-test="edit-receipt-button">
                            {{ __('Edit') }}
                        </flux:menu.item>
                    @endif
                    @if ($receipt->status === ReceiptStatus::Posted)
                        <flux:menu.separator />
                        <flux:menu.item icon="x-circle" variant="danger" wire:click="void" wire:confirm="{{ __('Void this receipt? It will be unapplied from invoices and a reversing GL entry posted.') }}" data-test="void-receipt-button">
                            {{ __('Void') }}
                        </flux:menu.item>
                    @endif
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <div class="mb-4 grid grid-cols-2 gap-4 md:grid-cols-4">
        <div>
            <flux:text class="text-muted-foreground">{{ __('Amount') }}</flux:text>
            <div class="text-lg font-mono">{{ number_format($receipt->amount_cents / 100, 2) }}</div>
        </div>
        <div>
            <flux:text class="text-muted-foreground">{{ __('Method') }}</flux:text>
            <div>{{ $receipt->paymentMethod?->name ?? '—' }}</div>
        </div>
        <div>
            <flux:text class="text-muted-foreground">{{ __('Reference') }}</flux:text>
            <div>{{ $receipt->reference ?? '—' }}</div>
        </div>
    </div>

    @if ($receipt->applications->isNotEmpty())
        <flux:heading class="mb-2">{{ __('Applied to') }}</flux:heading>
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Invoice') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Amount applied') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($receipt->applications as $app)
                        <tr data-test="receipt-application-row">
                            <td class="px-4 py-2 font-mono">
                                <a href="{{ route('invoices.show', ['company' => $company->slug, 'invoice' => $app->invoice_id]) }}" wire:navigate class="underline">
                                    {{ optional($app->invoice)->invoice_no }}
                                </a>
                            </td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($app->amount_cents / 100, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
