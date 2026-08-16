<?php

use App\Enums\TaxReturnPaymentDirection;
use App\Enums\TaxReturnPaymentStatus;
use App\Models\Company;
use App\Models\TaxReturn;
use App\Models\TaxReturnPayment;
use App\Services\Posting\TaxReturnPaymentPoster;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Tax payment')] class extends Component {
    public Company $company;

    public TaxReturn $taxReturn;

    public TaxReturnPayment $payment;

    public function mount(Company $company, TaxReturn $tax_return, TaxReturnPayment $payment): void
    {
        abort_unless($payment->tax_return_id === $tax_return->id, 404);

        $this->company = $company;
        $this->taxReturn = $tax_return->load('taxAgency');
        $this->payment = $payment->load(
            'bankAccount', 'paymentMethod',
            'penaltyAccount', 'interestAccount', 'commissionAccount',
            'journalEntry', 'postedBy', 'voidedBy',
        );
    }

    public function void(TaxReturnPaymentPoster $poster): void
    {
        try {
            $poster->void($this->payment);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Tax payment voided.'));
        $this->payment = $this->payment->fresh(['journalEntry', 'voidedBy']);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">
                @if ($payment->direction === TaxReturnPaymentDirection::Outgoing)
                    {{ __('Tax payment') }} {{ $payment->payment_no }}
                @else
                    {{ __('Tax refund') }} {{ $payment->payment_no }}
                @endif
            </flux:heading>
            <flux:subheading>
                <a href="{{ route('tax-returns.show', ['company' => $company->slug, 'tax_return' => $taxReturn->id]) }}" wire:navigate class="underline">{{ $taxReturn->tax_return_no }}</a>
                &middot; {{ $taxReturn->taxAgency->name }}
                &middot; {{ $payment->payment_date->toDateString() }}
            </flux:subheading>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                @switch($payment->status->value)
                    @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                    @case('posted') <flux:badge color="blue">{{ __('Posted') }}</flux:badge> @break
                    @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                @endswitch

                @if ($payment->journal_entry_id)
                    <flux:badge color="zinc">
                        <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $payment->journal_entry_id]) }}" wire:navigate class="underline">
                            {{ __('GL entry') }} {{ optional($payment->journalEntry)->entry_no }}
                        </a>
                    </flux:badge>
                @endif

                @if ($payment->reference)
                    <flux:badge color="zinc">{{ __('Ref') }}: <span class="font-mono">{{ $payment->reference }}</span></flux:badge>
                @endif
            </div>
        </div>

        <div class="flex gap-2">
            @if ($payment->status === TaxReturnPaymentStatus::Posted)
                <flux:button variant="danger" wire:click="void" wire:confirm="{{ __('Void this payment? A reversing GL entry will be posted.') }}" data-test="void-payment-button">
                    {{ __('Void') }}
                </flux:button>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="rounded-lg border border-border p-4">
            <div class="text-xs uppercase text-muted-foreground">{{ __('Bank account') }}</div>
            <div class="mt-1">{{ optional($payment->bankAccount)->code }} — {{ optional($payment->bankAccount)->name }}</div>
            @if ($payment->paymentMethod)
                <div class="mt-1 text-sm text-muted-foreground">{{ __('Method') }}: {{ $payment->paymentMethod->name }}</div>
            @endif
        </div>

        <div class="rounded-lg border border-border p-4">
            <div class="text-xs uppercase text-muted-foreground">{{ __('Direction') }}</div>
            <div class="mt-1">{{ $payment->direction->label() }}</div>
        </div>
    </div>

    <div class="mt-6 overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <tbody class="divide-y divide-border">
                <tr>
                    <td class="px-4 py-2">{{ $payment->direction === TaxReturnPaymentDirection::Outgoing ? __('Net tax remitted') : __('Net tax refunded') }}</td>
                    <td class="px-4 py-2 text-right font-mono">{{ number_format($payment->net_amount_cents / 100, 2) }}</td>
                </tr>
                @if ($payment->penalty_cents > 0)
                    <tr>
                        <td class="px-4 py-2">{{ __('Penalty') }} <span class="text-muted-foreground text-xs">({{ optional($payment->penaltyAccount)->code }})</span></td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($payment->penalty_cents / 100, 2) }}</td>
                    </tr>
                @endif
                @if ($payment->interest_cents > 0)
                    <tr>
                        <td class="px-4 py-2">{{ $payment->direction === TaxReturnPaymentDirection::Outgoing ? __('Interest paid') : __('Interest received') }} <span class="text-muted-foreground text-xs">({{ optional($payment->interestAccount)->code }})</span></td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($payment->interest_cents / 100, 2) }}</td>
                    </tr>
                @endif
                @if ($payment->commission_cents > 0)
                    <tr>
                        <td class="px-4 py-2">{{ __('Commission') }} <span class="text-muted-foreground text-xs">({{ optional($payment->commissionAccount)->code }})</span></td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($payment->commission_cents / 100, 2) }}</td>
                    </tr>
                @endif
            </tbody>
            <tfoot class="bg-muted">
                <tr class="text-base">
                    <td class="px-4 py-2 font-semibold">{{ __('Total through bank') }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($payment->total_cents / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @if ($payment->notes)
        <flux:text class="mt-4 text-muted-foreground">{{ $payment->notes }}</flux:text>
    @endif

    @if ($payment->posted_at)
        <div class="mt-4 text-xs text-muted-foreground">
            {{ __('Posted') }} {{ $payment->posted_at->toDateTimeString() }}
            {{ optional($payment->postedBy)->name ? __('by').' '.$payment->postedBy->name : '' }}
        </div>
    @endif
    @if ($payment->voided_at)
        <div class="mt-1 text-xs text-rose-500">
            {{ __('Voided') }} {{ $payment->voided_at->toDateTimeString() }}
            {{ optional($payment->voidedBy)->name ? __('by').' '.$payment->voidedBy->name : '' }}
        </div>
    @endif
</section>
