{{--
    Refund rows (cheques and card refunds) with their remove buttons, shared by
    the credit memo, invoice and payment pages. The page's IssuesCustomerRefunds
    trait owns deleteRefundCheque / deleteRefundReceipt.
--}}
@props([
    'company',
    'cheques',
    'receipts',
])

@foreach ($cheques as $cheque)
    <div class="flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm" wire:key="rc-{{ $cheque->id }}">
        <a href="{{ route($cheque->journal_entry_id ? 'cheques.show' : 'cheques.edit', ['company' => $company->slug, 'cheque' => $cheque->id]) }}" wire:navigate class="flex items-center gap-2 hover:underline">
            <flux:icon name="banknotes" class="size-4 text-muted-foreground" />
            <span>{{ __('Cheque') }} {{ $cheque->cheque_no }}</span>
            <span class="text-muted-foreground">{{ $cheque->cheque_date?->toDateString() }}</span>
            <flux:badge size="sm" :color="$cheque->journal_entry_id ? 'blue' : 'amber'">
                {{ $cheque->journal_entry_id ? __('Posted') : __('Draft') }}
            </flux:badge>
        </a>
        <div class="flex items-center gap-2">
            <span class="font-mono">{{ number_format($cheque->amount_cents / 100, 2) }}</span>
            <flux:button variant="ghost" size="sm" icon="x-mark"
                wire:click="deleteRefundCheque({{ $cheque->id }})"
                wire:confirm="{{ $cheque->journal_entry_id
                    ? __('Void this refund cheque? A reversing GL entry will be posted.')
                    : __('Delete this draft refund cheque?') }}"
                data-test="delete-refund-cheque" />
        </div>
    </div>
@endforeach

@foreach ($receipts as $receipt)
    <div class="flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm" wire:key="rr-{{ $receipt->id }}">
        <a href="{{ route('receipts.show', ['company' => $company->slug, 'receipt' => $receipt->id]) }}" wire:navigate class="flex items-center gap-2 hover:underline">
            <flux:icon name="credit-card" class="size-4 text-muted-foreground" />
            <span>{{ __('Card refund') }} {{ $receipt->receipt_no }}</span>
            <span class="text-muted-foreground">{{ $receipt->receipt_date?->toDateString() }}</span>
        </a>
        <div class="flex items-center gap-2">
            <span class="font-mono">{{ number_format(abs($receipt->amount_cents) / 100, 2) }}</span>
            <flux:button variant="ghost" size="sm" icon="x-mark"
                wire:click="deleteRefundReceipt({{ $receipt->id }})"
                wire:confirm="{{ __('Void this card refund? A reversing GL entry will be posted.') }}"
                data-test="delete-refund-receipt" />
        </div>
    </div>
@endforeach
