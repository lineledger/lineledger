<?php

use App\Models\Company;
use App\Models\Invoice;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.portal')] #[Title('Invoice')] class extends Component
{
    public Company $company;

    public Invoice $invoice;

    public function mount(Company $company, Invoice $invoice): void
    {
        $customer = auth('customer')->user();

        abort_unless($invoice->company_id === $company->id && $invoice->contact_id === $customer->id, 404);

        $this->company = $company;
        $this->invoice = $invoice->load('lines.taxCode');
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Invoice :no', ['no' => $invoice->invoice_no]) }}</flux:heading>
            <flux:subheading>
                {{ __('Issued :date', ['date' => $invoice->invoice_date?->toDateString()]) }}
                @if ($invoice->due_date)
                    · {{ __('Due :date', ['date' => $invoice->due_date->toDateString()]) }}
                @endif
            </flux:subheading>
        </div>

        <div class="flex items-end gap-2">
            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('portal.dashboard', ['company' => $company->slug])" wire:navigate>
                {{ __('Back') }}
            </flux:button>
            <flux:button size="sm" variant="filled" icon="arrow-down-tray" :href="route('portal.invoices.pdf', ['company' => $company->slug, 'invoice' => $invoice->id])">
                {{ __('PDF') }}
            </flux:button>
            @if ($invoice->status->isOpen() && $invoice->balanceCents() > 0 && $company->canAcceptCardPayments())
                <flux:button size="sm" variant="primary" icon="credit-card" :href="route('portal.pay', ['company' => $company->slug])" wire:navigate data-test="portal-invoice-pay">
                    {{ __('Pay now') }}
                </flux:button>
            @endif
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Description') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Qty') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Unit price') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @foreach ($invoice->lines as $line)
                    <tr>
                        <td class="px-4 py-2">{!! \App\Support\Text\LineDescription::toHtml($line->description) !!}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ rtrim(rtrim(number_format((float) $line->quantity, 2), '0'), '.') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($line->unit_price_cents / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($line->line_total_cents / 100, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-muted">
                <tr>
                    <td colspan="3" class="px-4 py-2 text-right text-muted-foreground">{{ __('Subtotal') }}</td>
                    <td class="px-4 py-2 text-right font-mono">{{ number_format($invoice->subtotal_cents / 100, 2) }}</td>
                </tr>
                <tr>
                    <td colspan="3" class="px-4 py-2 text-right text-muted-foreground">{{ __('Tax') }}</td>
                    <td class="px-4 py-2 text-right font-mono">{{ number_format($invoice->tax_cents / 100, 2) }}</td>
                </tr>
                <tr>
                    <td colspan="3" class="px-4 py-2 text-right font-semibold">{{ __('Total') }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($invoice->total_cents / 100, 2) }} {{ $company->currency_code }}</td>
                </tr>
                <tr>
                    <td colspan="3" class="px-4 py-2 text-right font-semibold">{{ __('Balance due') }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold" data-test="portal-invoice-balance">{{ number_format($invoice->balanceCents() / 100, 2) }} {{ $company->currency_code }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @php($schedule = $invoice->paymentRequests->isNotEmpty() ? app(\App\Services\Sales\PaymentRequestScheduleStatus::class)->for($invoice) : collect())
    @if ($company->invoiceSettingsOrNew()->show_payment_schedule && $schedule->isNotEmpty())
        <div class="overflow-hidden rounded-lg border border-border" data-test="portal-payment-schedule">
            <div class="border-b border-border bg-muted px-4 py-2 font-medium">{{ __('Payment schedule') }}</div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-border">
                    @foreach ($schedule as $row)
                        <tr>
                            <td class="px-4 py-2">{{ $row['request']->label }}</td>
                            <td class="px-4 py-2 text-muted-foreground">{{ $row['request']->due_date?->toDateString() }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($row['request']->amount_cents / 100, 2) }}</td>
                            <td class="px-4 py-2 text-right text-muted-foreground">{{ $row['status']->label() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @php($paymentInstructions = $company->invoiceSettingsOrNew()->payment_instructions)
    @if ($invoice->balanceCents() > 0 && filled($paymentInstructions))
        <div class="rounded-lg border border-border p-4">
            <flux:heading size="sm">{{ __('How to pay') }}</flux:heading>
            <div class="mt-2 whitespace-pre-line text-sm text-muted-foreground" data-test="portal-invoice-payment-instructions">{{ $paymentInstructions }}</div>
        </div>
    @endif
</div>
