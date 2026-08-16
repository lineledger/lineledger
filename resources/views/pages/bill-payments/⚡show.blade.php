<?php

use App\Enums\BillPaymentStatus;
use App\Enums\BillType;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\BillPayment;
use App\Models\Company;
use App\Services\Posting\BillPaymentPoster;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Payment')] class extends Component {
    public Company $company;

    public BillPayment $payment;

    public function mount(Company $company, BillPayment $payment): void
    {
        $this->company = $company;
        $this->payment = $payment->load('contact', 'paidFromAccount', 'applications.bill', 'journalEntry');
    }

    public function void(BillPaymentPoster $poster): void
    {
        try {
            $poster->void($this->payment);
        } catch (PeriodLockedException|\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Payment voided.'));
        $this->redirectRoute('bill-payments.index', ['company' => $this->company->slug], navigate: true);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Payment') }} {{ $payment->payment_no }}</flux:heading>
            <flux:subheading>
                {{ $payment->contact->display_name }} &middot;
                {{ $payment->payment_date->toDateString() }} &middot;
                {{ __('From') }} {{ $payment->paidFromAccount->name }}
            </flux:subheading>
            <div class="mt-2 hidden items-center gap-2 lg:flex">
                @switch($payment->status->value)
                    @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                    @case('posted') <flux:badge color="green">{{ __('Posted') }}</flux:badge> @break
                    @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                @endswitch
                <flux:badge color="zinc">{{ $payment->payment_type->label() }}</flux:badge>

                @if ($payment->journal_entry_id)
                    <flux:badge color="zinc">
                        <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $payment->journal_entry_id]) }}" wire:navigate class="underline">
                            {{ __('GL entry') }} {{ optional($payment->journalEntry)->entry_no }}
                        </a>
                    </flux:badge>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:dropdown align="end">
                <flux:button icon:trailing="chevron-down" data-test="payment-actions-menu">{{ __('Actions') }}</flux:button>
                <flux:menu>
                    <flux:menu.item icon="printer" :href="route('bill-payments.print', ['company' => $company->slug, 'payment' => $payment->id])" target="_blank" data-test="print-payment-button">
                        {{ __('Print') }}
                    </flux:menu.item>
                    @if ($payment->status === BillPaymentStatus::Posted && $payment->paymentMethod?->is_cheque && filled($payment->reference))
                        <flux:menu.item icon="printer" :href="route('bill-payments.print-cheque', ['company' => $company->slug, 'payment' => $payment->id])" target="_blank" data-test="print-cheque-button">
                            {{ $company->jurisdiction->chequeLabel('print') }}
                        </flux:menu.item>
                    @endif
                    @if ($payment->status !== BillPaymentStatus::Void)
                        <flux:menu.item icon="pencil" :href="route('bill-payments.edit', ['company' => $company->slug, 'payment' => $payment->id])" wire:navigate data-test="edit-payment-button">
                            {{ __('Edit') }}
                        </flux:menu.item>
                    @endif
                    @if ($payment->status === BillPaymentStatus::Posted)
                        <flux:menu.separator />
                        <flux:menu.item icon="x-circle" variant="danger" wire:click="void" wire:confirm="{{ __('Void this payment? Applied bills will be reopened and a reversing GL entry will be posted.') }}" data-test="void-payment-button">
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
            <div class="text-lg font-mono">{{ number_format($payment->amount_cents / 100, 2) }}</div>
        </div>
        <div>
            <flux:text class="text-muted-foreground">{{ __('Method') }}</flux:text>
            <div>{{ $payment->paymentMethod?->name ?? '—' }}</div>
        </div>
        <div>
            <flux:text class="text-muted-foreground">{{ __('Reference') }}</flux:text>
            <div>{{ $payment->reference ?? '—' }}</div>
        </div>
    </div>

    @if ($payment->applications->isNotEmpty())
        <flux:heading class="mb-2">{{ __('Applied to') }}</flux:heading>
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ $payment->payment_type === BillType::Reimbursement ? __('Reimbursement') : __('Bill') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Amount applied') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($payment->applications as $app)
                        <tr data-test="payment-application-row">
                            <td class="px-4 py-2 font-mono">
                                @php $route = $payment->payment_type === BillType::Reimbursement ? 'reimbursements.show' : 'bills.show'; @endphp
                                <a href="{{ route($route, ['company' => $company->slug, 'bill' => $app->bill_id]) }}" wire:navigate class="underline">
                                    {{ optional($app->bill)->bill_no }}
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
