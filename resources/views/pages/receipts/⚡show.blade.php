<?php

use App\Actions\Sales\RecordCustomerRefund;
use App\Enums\ChequeStatus;
use App\Enums\ReceiptStatus;
use App\Exceptions\Posting\PeriodLockedException;
use App\Livewire\Attributes\GuardsEditLock;
use App\Livewire\Concerns\IssuesCustomerRefunds;
use App\Livewire\Concerns\ShowsEditLock;
use App\Models\Company;
use App\Models\CustomerReceipt;
use App\Services\Posting\ChequePoster;
use App\Services\Posting\ReceiptPoster;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Receipt')] class extends Component {
    use IssuesCustomerRefunds;
    use ShowsEditLock;

    public Company $company;

    public CustomerReceipt $receipt;

    protected function editLockRecord(): ?Model
    {
        return $this->receipt;
    }

    public function mount(Company $company, CustomerReceipt $receipt): void
    {
        $this->company = $company;
        $this->receipt = $receipt->load('contact', 'depositToAccount', 'paymentMethod', 'applications.invoice', 'journalEntry', 'creditMemo', 'refundedReceipt', 'fromInvoice');
    }

    #[GuardsEditLock]
    public function submitRefund(RecordCustomerRefund $action): void
    {
        $this->processRefund($action);
    }

    #[GuardsEditLock]
    public function deleteRefundCheque(int $chequeId, ChequePoster $poster): void
    {
        $this->removeRefundCheque($chequeId, $poster);
    }

    #[GuardsEditLock]
    public function deleteRefundReceipt(int $receiptId, ReceiptPoster $poster): void
    {
        $this->removeRefundReceipt($receiptId, $poster);
    }

    /**
     * A posted home-currency payment refunds its own unapplied credit.
     */
    protected function eligibleRefundSources(): Collection
    {
        return $this->receipt->isRefundableSource() && $this->receipt->remainingRefundableCents() > 0
            ? collect([$this->receipt])
            : collect();
    }

    protected function removableRefundCheques(): HasMany
    {
        return $this->receipt->refundCheques();
    }

    protected function removableRefundReceipts(): HasMany
    {
        return $this->receipt->refundReceipts();
    }

    protected function afterRefundChanged(): void
    {
        unset($this->refundCheques, $this->refundReceipts);
        $this->receipt->refresh();
    }

    #[Computed]
    public function refundCheques()
    {
        return $this->receipt->refundCheques()->where('status', '!=', ChequeStatus::Void->value)->orderBy('id')->get();
    }

    #[Computed]
    public function refundReceipts()
    {
        return $this->receipt->refundReceipts()->where('status', '!=', ReceiptStatus::Void->value)->orderBy('id')->get();
    }

    #[GuardsEditLock]
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
    <x-edit-lock.banner :lock="$this->editLockBanner" />

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
            @if ($this->canRefund)
                <flux:button variant="primary" class="!hidden lg:!inline-flex" icon="banknotes" wire:click="openRefund" data-test="refund-receipt-button">
                    {{ __('Refund to client') }}
                </flux:button>
            @endif

            <flux:dropdown align="end">
                <flux:button icon:trailing="chevron-down" data-test="receipt-actions-menu">{{ __('Actions') }}</flux:button>
                <flux:menu>
                    @if ($this->canRefund)
                        <flux:menu.item class="lg:hidden" icon="banknotes" wire:click="openRefund" data-test="refund-receipt-menu-item">
                            {{ __('Refund to client') }}
                        </flux:menu.item>
                    @endif
                    <flux:menu.item icon="printer" :href="route('receipts.print', ['company' => $company->slug, 'receipt' => $receipt->id])" target="_blank" data-test="print-receipt-button">
                        {{ __('Print') }}
                    </flux:menu.item>
                    {{-- A refund is removed and recorded again rather than edited. --}}
                    @if ($receipt->status !== ReceiptStatus::Void && ! $receipt->isRefund())
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
        @if (! $receipt->isRefund() && $receipt->status !== ReceiptStatus::Void)
            <div>
                <flux:text class="text-muted-foreground">{{ __('Unapplied credit') }}</flux:text>
                <div class="text-lg font-mono" data-test="receipt-unapplied-credit">{{ number_format(max(0, $receipt->unappliedCents()) / 100, 2) }}</div>
            </div>
        @endif
    </div>

    @if ($receipt->isRefund())
        <flux:callout icon="arrow-uturn-left" class="mb-4" data-test="receipt-refund-of">
            <flux:callout.text>
                @if ($receipt->creditMemo)
                    {{ __('Card refund of credit memo') }}
                    <a href="{{ route('credit-memos.show', ['company' => $company->slug, 'credit_memo' => $receipt->creditMemo->id]) }}" wire:navigate class="underline">{{ $receipt->creditMemo->credit_memo_no }}</a>.
                @elseif ($receipt->refundedReceipt)
                    {{ __('Card refund of the overpayment on') }}
                    <a href="{{ route('receipts.show', ['company' => $company->slug, 'receipt' => $receipt->refundedReceipt->id]) }}" wire:navigate class="underline">{{ $receipt->refundedReceipt->receipt_no }}</a>.
                @endif
                @if ($receipt->fromInvoice)
                    {{ __('Issued from invoice') }}
                    <a href="{{ route('invoices.show', ['company' => $company->slug, 'invoice' => $receipt->fromInvoice->id]) }}" wire:navigate class="underline">{{ $receipt->fromInvoice->invoice_no }}</a>.
                @endif
            </flux:callout.text>
        </flux:callout>
    @endif

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

    @if ($this->refundCheques->isNotEmpty() || $this->refundReceipts->isNotEmpty())
        <div class="mt-6 space-y-2 rounded-lg border border-border p-4" data-test="receipt-refunds">
            <div class="flex items-center justify-between">
                <flux:heading size="sm">{{ __('Refunds') }}</flux:heading>
                <flux:text class="text-sm text-muted-foreground">
                    {{ __('Refunded :refunded of :credit unapplied', [
                        'refunded' => number_format($receipt->refundedCents() / 100, 2),
                        'credit' => number_format($receipt->refundableBaseCents() / 100, 2),
                    ]) }}
                </flux:text>
            </div>

            <x-customer-refund.list :company="$company" :cheques="$this->refundCheques" :receipts="$this->refundReceipts" />
        </div>
    @endif

    <x-customer-refund.modal
        :contact-name="$receipt->contact->display_name"
        :method="$refundMethod"
        :bank-accounts="$this->refundBankAccounts"
        :deposit-accounts="$this->refundDepositAccounts"
        :payment-methods="$this->refundPaymentMethods"
        :stripe-source="$receipt->stripe_payment_intent_id !== null"
    />
</section>
