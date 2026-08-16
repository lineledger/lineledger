<?php

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.portal')] #[Title('Your invoices')] class extends Component
{
    public Company $company;

    public Contact $customer;

    public function mount(Company $company): void
    {
        $this->company = $company;
        $this->customer = auth('customer')->user();
    }

    /**
     * Open invoices owed by this customer, oldest due first. Mirrors the staff
     * open-invoices report query, scoped to the signed-in customer.
     *
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function openInvoices(): Collection
    {
        return Invoice::query()
            ->where('contact_id', $this->customer->id)
            ->whereIn('status', [InvoiceStatus::Posted->value, InvoiceStatus::Partial->value])
            ->whereRaw('total_cents - amount_paid_cents - reconciled_cents > 0')
            ->orderBy('due_date')
            ->orderBy('invoice_date')
            ->get();
    }

    #[Computed]
    public function totalDue(): int
    {
        return $this->openInvoices->sum(fn (Invoice $invoice) => $invoice->balanceCents());
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Hello, :name', ['name' => $customer->display_name]) }}</flux:heading>
            <flux:subheading>{{ __('Review your open invoices and pay online.') }}</flux:subheading>
        </div>

        <flux:button
            size="sm"
            variant="ghost"
            icon="document-text"
            :href="route('portal.statement', ['company' => $company->slug])"
            wire:navigate
        >
            {{ __('View statement') }}
        </flux:button>
    </div>

    <flux:card class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:subheading>{{ __('Total due') }}</flux:subheading>
            <flux:heading size="2xl" class="font-mono" data-test="portal-total-due">
                {{ number_format($this->totalDue / 100, 2) }}
                <span class="text-base text-muted-foreground">{{ $company->currency_code }}</span>
            </flux:heading>
        </div>

        @if ($this->totalDue > 0)
            <flux:button
                variant="primary"
                icon="credit-card"
                :href="route('portal.pay', ['company' => $company->slug])"
                wire:navigate
                data-test="portal-pay-now"
            >
                {{ __('Pay now') }}
            </flux:button>
        @endif
    </flux:card>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Invoice') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Due') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Balance') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->openInvoices as $invoice)
                    <tr data-test="portal-invoice-row">
                        <td class="px-4 py-2 font-mono">{{ $invoice->invoice_no }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $invoice->invoice_date?->toDateString() }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $invoice->due_date?->toDateString() }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($invoice->total_cents / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($invoice->balanceCents() / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right">
                            <flux:button
                                size="xs"
                                variant="ghost"
                                icon="arrow-down-tray"
                                :href="route('portal.invoices.pdf', ['company' => $company->slug, 'invoice' => $invoice->id])"
                            >
                                {{ __('PDF') }}
                            </flux:button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-muted-foreground">{{ __('You have no open invoices. Thank you!') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
