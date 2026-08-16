<?php

use App\Actions\Sales\ConvertEstimateToInvoice;
use App\Actions\Sales\ConvertEstimateToSalesOrder;
use App\Enums\EstimateStatus;
use App\Models\Company;
use App\Models\Estimate;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Estimate')] class extends Component {
    public Company $company;

    public Estimate $estimate;

    public function mount(Company $company, Estimate $estimate): void
    {
        $this->company = $company;
        $this->estimate = $estimate->load('lines.account', 'lines.taxCode.agency', 'lines.secondaryTaxCode.agency', 'contact', 'salesRep', 'convertedInvoice', 'convertedSalesOrder');
    }

    /**
     * Whether a Convert action should be offered. Normally driven by status, but
     * also re-enabled for an orphaned estimate whose converted invoice was deleted.
     */
    #[Computed]
    public function canConvert(): bool
    {
        return $this->estimate->effectiveStatus()->canConvert()
            || $this->isOrphaned();
    }

    public function isOrphaned(): bool
    {
        return $this->estimate->status === EstimateStatus::Converted
            && $this->estimate->converted_invoice_id === null
            && $this->estimate->converted_sales_order_id === null;
    }

    public function accept(): void
    {
        if ($this->estimate->status !== EstimateStatus::Pending) {
            return;
        }

        $this->estimate->update(['status' => EstimateStatus::Accepted]);

        Flux::toast(variant: 'success', text: __('Estimate accepted.'));
    }

    public function reject(): void
    {
        if (! in_array($this->estimate->status, [EstimateStatus::Pending, EstimateStatus::Accepted], true)) {
            return;
        }

        $this->estimate->update(['status' => EstimateStatus::Rejected]);

        Flux::toast(variant: 'success', text: __('Estimate rejected.'));
    }

    public function convert(ConvertEstimateToInvoice $action): void
    {
        // Revive an orphaned estimate (its invoice was deleted) so it can convert again.
        if ($this->isOrphaned()) {
            $this->estimate->forceFill(['status' => EstimateStatus::Pending])->save();
        }

        try {
            $invoice = $action->handle($this->estimate);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Estimate converted to invoice.'));
        $this->redirectRoute('invoices.edit', ['company' => $this->company->slug, 'invoice' => $invoice->id], navigate: true);
    }

    public function convertToSalesOrder(ConvertEstimateToSalesOrder $action): void
    {
        // Revive an orphaned estimate (its document was deleted) so it can convert again.
        if ($this->isOrphaned()) {
            $this->estimate->forceFill(['status' => EstimateStatus::Pending])->save();
        }

        try {
            $salesOrder = $action->handle($this->estimate);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Estimate converted to sales order.'));
        $this->redirectRoute('sales-orders.show', ['company' => $this->company->slug, 'salesOrder' => $salesOrder->id], navigate: true);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Estimate') }} {{ $estimate->estimate_no }}</flux:heading>
            <flux:subheading>
                {{ $estimate->contact->display_name }} &middot;
                {{ $estimate->estimate_date->toDateString() }}
                @if ($estimate->expires_on)
                    &middot; {{ __('Expires') }} {{ $estimate->expires_on->toDateString() }}
                @endif
            </flux:subheading>
            <div class="mt-2 hidden items-center gap-2 lg:flex">
                @switch($estimate->effectiveStatus()->value)
                    @case('pending') <flux:badge color="amber">{{ __('Pending') }}</flux:badge> @break
                    @case('accepted') <flux:badge color="blue">{{ __('Accepted') }}</flux:badge> @break
                    @case('rejected') <flux:badge color="red">{{ __('Rejected') }}</flux:badge> @break
                    @case('converted') <flux:badge color="green">{{ __('Converted') }}</flux:badge> @break
                    @case('expired') <flux:badge color="zinc">{{ __('Expired') }}</flux:badge> @break
                @endswitch

                @if ($estimate->convertedInvoice)
                    <flux:badge color="zinc">
                        <a href="{{ route('invoices.show', ['company' => $company->slug, 'invoice' => $estimate->converted_invoice_id]) }}" wire:navigate class="underline">
                            {{ __('Invoice') }} {{ $estimate->convertedInvoice->invoice_no }}
                        </a>
                    </flux:badge>
                @elseif ($estimate->convertedSalesOrder)
                    <flux:badge color="zinc">
                        <a href="{{ route('sales-orders.show', ['company' => $company->slug, 'salesOrder' => $estimate->converted_sales_order_id]) }}" wire:navigate class="underline">
                            {{ __('Sales Order') }} {{ $estimate->convertedSalesOrder->order_no }}
                        </a>
                    </flux:badge>
                @elseif ($this->isOrphaned())
                    <flux:badge color="red">{{ __('Converted document was deleted') }}</flux:badge>
                @endif
            </div>

            @if ($estimate->salesRep || $estimate->customer_po)
                <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm text-muted-foreground" data-test="estimate-meta">
                    @if ($estimate->salesRep)<div>{{ __('Sales rep') }}: {{ $estimate->salesRep->display_name }}</div>@endif
                    @if ($estimate->customer_po)<div>{{ __('PO #') }}: {{ $estimate->customer_po }}</div>@endif
                </div>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($this->canConvert)
                <flux:button variant="primary" class="!hidden lg:!inline-flex" wire:click="convert" wire:confirm="{{ __('Create a draft invoice from this estimate?') }}" data-test="convert-estimate-button">
                    {{ __('Convert to invoice') }}
                </flux:button>
            @endif

            <flux:dropdown align="end">
                <flux:button icon:trailing="chevron-down" data-test="estimate-actions-menu">{{ __('Actions') }}</flux:button>
                <flux:menu>
                    @if ($this->canConvert)
                        <flux:menu.item class="lg:hidden" icon="document-text" wire:click="convert" wire:confirm="{{ __('Create a draft invoice from this estimate?') }}" data-test="convert-estimate-menu-item">
                            {{ __('Convert to invoice') }}
                        </flux:menu.item>
                        <flux:menu.item icon="clipboard-document-check" wire:click="convertToSalesOrder" wire:confirm="{{ __('Create a sales order from this estimate?') }}" data-test="convert-estimate-to-sales-order-button">
                            {{ __('Convert to sales order') }}
                        </flux:menu.item>
                    @endif
                    <flux:menu.item icon="printer" :href="route('estimates.print', ['company' => $company->slug, 'estimate' => $estimate->id])" target="_blank" data-test="print-estimate-button">
                        {{ __('Print') }}
                    </flux:menu.item>
                    @if ($estimate->status->isEditable())
                        <flux:menu.item icon="pencil" :href="route('estimates.edit', ['company' => $company->slug, 'estimate' => $estimate->id])" wire:navigate data-test="edit-estimate-button">
                            {{ __('Edit') }}
                        </flux:menu.item>
                    @endif
                    @if ($estimate->status === EstimateStatus::Pending)
                        <flux:menu.item icon="check" wire:click="accept" data-test="accept-estimate-button">
                            {{ __('Accept') }}
                        </flux:menu.item>
                    @endif
                    @if (in_array($estimate->status, [EstimateStatus::Pending, EstimateStatus::Accepted], true))
                        <flux:menu.item icon="x-mark" wire:click="reject" wire:confirm="{{ __('Reject this estimate?') }}" data-test="reject-estimate-button">
                            {{ __('Reject') }}
                        </flux:menu.item>
                    @endif
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Description') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Account') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Qty') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Unit') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Tax') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Subtotal') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Tax') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @foreach ($estimate->lines as $line)
                    <tr>
                        <td class="px-4 py-2">
                            {{ $line->description }}
                            @if ($line->service_date)
                                <div class="text-xs text-muted-foreground">{{ __('Service date') }}: {{ $line->service_date->toDateString() }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">{{ optional($line->account)->code }} — {{ optional($line->account)->name }}</td>
                        <td class="px-4 py-2 text-right">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                        <td class="px-4 py-2 text-right font-mono">
                            {{ number_format($line->unit_price_cents / 100, 2) }}
                            @if ($line->line_discount_cents)
                                <div class="text-xs text-muted-foreground">{{ __('less :amt disc', ['amt' => number_format($line->line_discount_cents / 100, 2)]) }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ optional($line->taxCode)->code }}
                            @if ($line->secondaryTaxCode)
                                <span class="block">{{ $line->secondaryTaxCode->code }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($line->line_subtotal_cents / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">
                            {{ number_format($line->line_tax_cents / 100, 2) }}
                            @if ($line->secondary_tax_cents)
                                <span class="block">{{ number_format($line->secondary_tax_cents / 100, 2) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($line->line_total_cents / 100, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-muted">
                <tr>
                    <td colspan="7" class="px-4 py-2 text-right font-medium">{{ __('Subtotal') }}</td>
                    <td class="px-4 py-2 text-right font-mono">{{ number_format($estimate->subtotal_cents / 100, 2) }}</td>
                </tr>
                @php
                    $taxRows = \App\Support\Tax\LineTaxBreakdown::forLines($estimate->lines);
                @endphp
                @forelse ($taxRows as $taxRow)
                    <tr data-test="estimate-tax-row">
                        <td colspan="7" class="px-4 py-2 text-right font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-2 text-right font-medium">{{ __('Tax') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($estimate->tax_cents / 100, 2) }}</td>
                    </tr>
                @endforelse
                <tr class="text-base">
                    <td colspan="7" class="px-4 py-2 text-right font-semibold">{{ __('Total') }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($estimate->total_cents / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @php
        $taxRegistrations = $estimate->lines
            ->flatMap(fn ($line) => [optional($line->taxCode)->agency, optional($line->secondaryTaxCode)->agency])
            ->filter(fn ($agency) => $agency && filled($agency->registration_number))
            ->unique('id')
            ->values();
    @endphp

    @if ($taxRegistrations->isNotEmpty())
        <div class="mt-4 space-y-1 text-sm text-muted-foreground" data-test="estimate-tax-registrations">
            @foreach ($taxRegistrations as $agency)
                <div>{{ $agency->name }}: <span class="font-mono">{{ $agency->registration_number }}</span></div>
            @endforeach
        </div>
    @endif

    @if ($estimate->customer_message)
        <flux:text class="mt-4 text-muted-foreground">{{ $estimate->customer_message }}</flux:text>
    @endif

    @if ($estimate->memo)
        <flux:text class="mt-2 text-muted-foreground">{{ $estimate->memo }}</flux:text>
    @endif
</section>
