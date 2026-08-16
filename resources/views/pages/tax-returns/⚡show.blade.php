<?php

use App\Enums\TaxReturnPaymentStatus;
use App\Enums\TaxReturnStatus;
use App\Models\Bill;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\TaxReturn;
use App\Services\Tax\TaxReturnFiler;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Tax return')] class extends Component {
    public Company $company;

    public TaxReturn $taxReturn;

    public string $voidReason = '';

    public function mount(Company $company, TaxReturn $tax_return): void
    {
        $this->company = $company;
        $this->taxReturn = $tax_return->load('lines', 'taxAgency.payableAccount', 'filedBy', 'voidedBy', 'payments');
    }

    public function file(TaxReturnFiler $filer): void
    {
        try {
            $filed = $filer->file($this->taxReturn);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Tax return filed.'));
        $this->taxReturn = $filed;
    }

    public function void(TaxReturnFiler $filer): void
    {
        $reason = trim($this->voidReason);

        if ($reason === '') {
            Flux::toast(variant: 'danger', text: __('A void reason is required.'));

            return;
        }

        try {
            $filer->void($this->taxReturn, $reason);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Tax return voided. Period is unlocked.'));
        $this->voidReason = '';
        Flux::modal('void-tax-return')->close();
        $this->taxReturn = $this->taxReturn->fresh(['lines', 'taxAgency', 'voidedBy']);
    }

    public function drillUrl(\App\Models\TaxReturnLine $line): ?string
    {
        return match ($line->source_type) {
            Invoice::class => $line->source_id
                ? route('invoices.show', ['company' => $this->company->slug, 'invoice' => $line->source_id])
                : null,
            Bill::class => $line->source_id
                ? route('bills.show', ['company' => $this->company->slug, 'bill' => $line->source_id])
                : null,
            Cheque::class => $line->source_id
                ? route('cheques.show', ['company' => $this->company->slug, 'cheque' => $line->source_id])
                : null,
            default => $line->journal_entry_id
                ? route('journal.show', ['company' => $this->company->slug, 'entry' => $line->journal_entry_id])
                : null,
        };
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Tax return') }} {{ $taxReturn->tax_return_no }}</flux:heading>
            <flux:subheading>
                {{ $taxReturn->taxAgency->name }} &middot;
                {{ $taxReturn->period_start->toDateString() }} → {{ $taxReturn->period_end->toDateString() }}
            </flux:subheading>
            <div class="mt-2 hidden flex-wrap items-center gap-2 lg:flex">
                @switch($taxReturn->status->value)
                    @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                    @case('filed') <flux:badge color="blue">{{ __('Filed') }}</flux:badge> @break
                    @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                @endswitch

                @if ($taxReturn->filing_reference)
                    <flux:badge color="zinc">{{ __('Ref') }}: <span class="font-mono">{{ $taxReturn->filing_reference }}</span></flux:badge>
                @endif

                @if ($taxReturn->filed_at)
                    <span class="text-xs text-muted-foreground">{{ __('Filed') }} {{ $taxReturn->filed_at->toDateTimeString() }} {{ optional($taxReturn->filedBy)->name ? __('by').' '.$taxReturn->filedBy->name : '' }}</span>
                @endif
            </div>
        </div>

        @php($hasOpenPayment = $taxReturn->payments->where('status', TaxReturnPaymentStatus::Posted)->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2">
            @if ($taxReturn->status === TaxReturnStatus::Draft)
                <flux:button variant="primary" class="!hidden lg:!inline-flex" wire:click="file" wire:confirm="{{ __('File this return? The snapshot is permanent and the period will be locked for this agency.') }}" data-test="file-return-button">{{ __('File return') }}</flux:button>
            @elseif ($taxReturn->status === TaxReturnStatus::Filed && ! $hasOpenPayment)
                <flux:button variant="primary" icon="banknotes" class="!hidden lg:!inline-flex" :href="route('tax-returns.payments.create', ['company' => $company->slug, 'tax_return' => $taxReturn->id])" wire:navigate data-test="record-payment-link">
                    {{ $taxReturn->net_cents >= 0 ? __('Record payment') : __('Record refund') }}
                </flux:button>
            @endif

            <flux:dropdown align="end">
                <flux:button icon:trailing="chevron-down" data-test="tax-return-actions-menu">{{ __('Actions') }}</flux:button>
                <flux:menu>
                    @if ($taxReturn->status === TaxReturnStatus::Draft)
                        <flux:menu.item class="lg:hidden" icon="document-check" wire:click="file" wire:confirm="{{ __('File this return? The snapshot is permanent and the period will be locked for this agency.') }}" data-test="file-return-menu-item">{{ __('File return') }}</flux:menu.item>
                        <flux:menu.item icon="pencil" :href="route('tax-returns.edit', ['company' => $company->slug, 'tax_return' => $taxReturn->id])" wire:navigate data-test="edit-tax-return-button">{{ __('Edit') }}</flux:menu.item>
                    @endif
                    @if ($taxReturn->status === TaxReturnStatus::Filed)
                        @unless ($hasOpenPayment)
                            <flux:menu.item class="lg:hidden" icon="banknotes" :href="route('tax-returns.payments.create', ['company' => $company->slug, 'tax_return' => $taxReturn->id])" wire:navigate data-test="record-payment-menu-item">
                                {{ $taxReturn->net_cents >= 0 ? __('Record payment') : __('Record refund') }}
                            </flux:menu.item>
                        @endunless
                        <flux:menu.separator />
                        <flux:modal.trigger name="void-tax-return">
                            <flux:menu.item icon="x-circle" variant="danger" data-test="void-tax-return-button">{{ __('Void') }}</flux:menu.item>
                        </flux:modal.trigger>
                    @endif
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-lg border border-border p-4">
            <div class="text-xs uppercase text-muted-foreground">{{ __('Collected') }}</div>
            <div class="text-2xl font-mono font-semibold">{{ number_format($taxReturn->collected_cents / 100, 2) }}</div>
        </div>
        <div class="rounded-lg border border-border p-4">
            <div class="text-xs uppercase text-muted-foreground">{{ __('Paid (ITCs)') }}</div>
            <div class="text-2xl font-mono font-semibold">{{ number_format($taxReturn->paid_cents / 100, 2) }}</div>
        </div>
        <div class="rounded-lg border border-border p-4">
            <div class="text-xs uppercase text-muted-foreground">{{ __('Net owing') }}</div>
            <div class="text-2xl font-mono font-semibold">{{ number_format($taxReturn->net_cents / 100, 2) }}</div>
        </div>
    </div>

    @if ($taxReturn->status === TaxReturnStatus::Void)
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm dark:border-rose-900 dark:bg-rose-950">
            <div class="font-medium">{{ __('This return has been voided.') }}</div>
            <div class="mt-1 text-muted-foreground">
                {{ $taxReturn->voided_at?->toDateTimeString() }}
                {{ optional($taxReturn->voidedBy)->name ? __('by').' '.$taxReturn->voidedBy->name : '' }}
                — {{ $taxReturn->void_reason }}
            </div>
        </div>
    @endif

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Entry #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Document') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Bucket') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($taxReturn->lines as $line)
                    <tr>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $line->entry_date->toDateString() }}</td>
                        <td class="px-4 py-2 font-mono">
                            @php($url = $this->drillUrl($line))
                            @if ($url)
                                <a href="{{ $url }}" wire:navigate class="underline">{{ $line->entry_no }}</a>
                            @else
                                {{ $line->entry_no }}
                            @endif
                        </td>
                        <td class="px-4 py-2">{{ $line->doc_label }}</td>
                        <td class="px-4 py-2">
                            @if ($line->bucket === 'collected')
                                <flux:badge color="emerald">{{ __('Collected') }}</flux:badge>
                            @else
                                <flux:badge color="amber">{{ __('Paid') }}</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($line->amount_cents / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ __('No snapshot lines yet — file the return to capture them.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($taxReturn->taxAgency->registration_number)
        <div class="mt-4 text-sm text-muted-foreground">
            {{ $taxReturn->taxAgency->name }}: <span class="font-mono">{{ $taxReturn->taxAgency->registration_number }}</span>
        </div>
    @endif

    @if ($taxReturn->notes)
        <flux:text class="mt-4 text-muted-foreground">{{ $taxReturn->notes }}</flux:text>
    @endif

    @if ($taxReturn->payments->isNotEmpty())
        <div class="mt-8">
            <flux:heading size="lg">{{ __('Payments') }}</flux:heading>
            <div class="mt-3 overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted">
                        <tr>
                            <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('Payment #') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('Direction') }}</th>
                            <th class="px-4 py-2 text-right">{{ __('Net') }}</th>
                            <th class="px-4 py-2 text-right">{{ __('Add-ons') }}</th>
                            <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                            <th class="px-4 py-2">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($taxReturn->payments as $payment)
                            @php($addons = (int) $payment->penalty_cents + (int) $payment->interest_cents + (int) $payment->commission_cents)
                            <tr class="cursor-pointer hover:bg-muted" wire:navigate.hover>
                                <td class="px-4 py-2 whitespace-nowrap">{{ $payment->payment_date->toDateString() }}</td>
                                <td class="px-4 py-2 font-mono">
                                    <a href="{{ route('tax-returns.payments.show', ['company' => $company->slug, 'tax_return' => $taxReturn->id, 'payment' => $payment->id]) }}" wire:navigate class="underline">{{ $payment->payment_no }}</a>
                                </td>
                                <td class="px-4 py-2">{{ $payment->direction->label() }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ number_format($payment->net_amount_cents / 100, 2) }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ number_format($addons / 100, 2) }}</td>
                                <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($payment->total_cents / 100, 2) }}</td>
                                <td class="px-4 py-2">
                                    @switch($payment->status->value)
                                        @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                                        @case('posted') <flux:badge color="blue">{{ __('Posted') }}</flux:badge> @break
                                        @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                                    @endswitch
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <flux:modal name="void-tax-return" class="md:w-96">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Void tax return') }}</flux:heading>
            <flux:text>{{ __('The snapshot rows are kept for audit history. The period is unlocked for this agency.') }}</flux:text>
            <flux:textarea wire:model="voidReason" :label="__('Reason')" rows="3" data-test="void-reason-input" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="void" data-test="confirm-void-button">{{ __('Confirm void') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
