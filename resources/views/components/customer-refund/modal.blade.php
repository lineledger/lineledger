{{--
    The "Refund to client" dialog, shared by the credit memo, invoice and payment
    pages. The page's IssuesCustomerRefunds trait owns the wire:model properties
    and the submitRefund action; this only lays them out.
--}}
@props([
    'contactName',
    'method',
    'bankAccounts',
    'depositAccounts',
    'paymentMethods',
    // [id => label] — a "From payment" picker shows when there is more than one.
    'sourceOptions' => [],
    // The selected source was paid through Stripe: the refund is only recorded here.
    'stripeSource' => false,
])

<flux:modal wire:model.self="showRefundModal" class="md:w-[32rem]">
    <form wire:submit="submitRefund" class="space-y-5">
        <div>
            <flux:heading size="lg">{{ __('Refund to client') }}</flux:heading>
            <flux:subheading>{{ $contactName }}</flux:subheading>
        </div>

        @if (count($sourceOptions) > 1)
            <flux:select wire:model.live="refundSourceId" :label="__('From payment')" data-test="refund-source-select">
                @foreach ($sourceOptions as $id => $label)
                    <flux:select.option :value="$id">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <flux:radio.group wire:model.live="refundMethod" :label="__('Method')" variant="segmented">
            <flux:radio value="cheque" :label="__('By cheque')" />
            <flux:radio value="card" :label="__('By credit card')" />
        </flux:radio.group>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <flux:input wire:model="refundAmount" :label="__('Amount')" class="text-right" data-test="refund-amount-input" />
            <flux:input type="date" wire:model="refundDate" :label="__('Date')" />
        </div>

        @if ($method === 'cheque')
            <flux:select wire:model="refundBankAccountId" :label="__('Bank account')" data-test="refund-bank-select">
                @foreach ($bankAccounts as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:text class="text-sm text-muted-foreground">{{ __('A draft cheque is created (debits Accounts Receivable, credits the bank). Review and post it to print.') }}</flux:text>
        @else
            <flux:select wire:model="refundDepositToAccountId" :label="__('Refund from')" data-test="refund-deposit-select">
                @foreach ($depositAccounts as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="refundPaymentMethodId" :label="__('Payment method')">
                <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                @foreach ($paymentMethods as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="refundReference" :label="__('Reference (optional)')" />
            <flux:text class="text-sm text-muted-foreground">{{ __('Records a negative receipt to Undeposited Funds. Deposit it with your other card receipts, or on its own deposit if there are none.') }}</flux:text>
        @endif

        @if ($stripeSource)
            <flux:callout variant="warning" icon="exclamation-triangle" data-test="refund-stripe-note">
                <flux:callout.text>{{ __('This payment came in through Stripe. This only records the refund — issue it in your Stripe dashboard too.') }}</flux:callout.text>
            </flux:callout>
        @endif

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" type="button" wire:click="$set('showRefundModal', false)">{{ __('Cancel') }}</flux:button>
            <flux:button variant="primary" type="submit" data-test="submit-refund-button">
                {{ $method === 'cheque' ? __('Create draft cheque') : __('Record refund') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
