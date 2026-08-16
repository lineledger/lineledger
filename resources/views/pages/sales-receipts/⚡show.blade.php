<?php

use App\Enums\SalesReceiptStatus;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Company;
use App\Models\SalesReceipt;
use App\Services\Posting\SalesReceiptPoster;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sales receipt')] class extends Component {
    public Company $company;

    public SalesReceipt $receipt;

    public function mount(Company $company, SalesReceipt $receipt): void
    {
        $this->company = $company;
        $this->receipt = $receipt->load('contact', 'depositToAccount', 'paymentMethod', 'lines.account', 'lines.taxCode', 'lines.secondaryTaxCode', 'journalEntry');
    }

    public function void(SalesReceiptPoster $poster): void
    {
        try {
            $poster->void($this->receipt);
        } catch (PeriodLockedException|\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Sales receipt voided.'));
        $this->redirectRoute('sales-receipts.index', ['company' => $this->company->slug], navigate: true);
    }

    public function deleteDraft(): void
    {
        if ($this->receipt->journal_entry_id) {
            Flux::toast(variant: 'danger', text: __('A posted sales receipt cannot be deleted. Void it instead.'));

            return;
        }

        $this->receipt->delete();

        Flux::toast(variant: 'success', text: __('Draft deleted.'));
        $this->redirectRoute('sales-receipts.index', ['company' => $this->company->slug], navigate: true);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Sales receipt') }} {{ $receipt->sales_receipt_no }}</flux:heading>
            <flux:subheading>
                {{ $receipt->contact?->display_name ?? __('Cash sale') }} &middot;
                {{ $receipt->receipt_date->toDateString() }} &middot;
                {{ __('Deposited to') }} {{ $receipt->depositToAccount->name }}
            </flux:subheading>
            <div class="mt-2 flex items-center gap-2">
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
                <flux:button icon:trailing="chevron-down" data-test="sales-receipt-actions-menu">{{ __('Actions') }}</flux:button>
                <flux:menu>
                    <flux:menu.item icon="printer" :href="route('sales-receipts.print', ['company' => $company->slug, 'receipt' => $receipt->id])" target="_blank" data-test="print-sales-receipt-button">
                        {{ __('Print') }}
                    </flux:menu.item>
                    @if ($receipt->status !== SalesReceiptStatus::Void)
                        <flux:menu.item icon="pencil" :href="route('sales-receipts.edit', ['company' => $company->slug, 'receipt' => $receipt->id])" wire:navigate data-test="edit-sales-receipt-button">
                            {{ __('Edit') }}
                        </flux:menu.item>
                    @endif
                    @if ($receipt->status === SalesReceiptStatus::Posted)
                        <flux:menu.separator />
                        <flux:menu.item icon="x-circle" variant="danger" wire:click="void" wire:confirm="{{ __('Void this sales receipt? A reversing GL entry will be posted.') }}" data-test="void-sales-receipt-button">
                            {{ __('Void') }}
                        </flux:menu.item>
                    @elseif ($receipt->status === SalesReceiptStatus::Draft)
                        <flux:menu.separator />
                        <flux:menu.item icon="trash" variant="danger" wire:click="deleteDraft" wire:confirm="{{ __('Delete this draft sales receipt?') }}" data-test="delete-sales-receipt-button">
                            {{ __('Delete draft') }}
                        </flux:menu.item>
                    @endif
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <div class="mb-4 grid grid-cols-2 gap-4 md:grid-cols-4">
        <div>
            <flux:text class="text-muted-foreground">{{ __('Total') }}</flux:text>
            <div class="text-lg font-mono">{{ number_format($receipt->total_cents / 100, 2) }}</div>
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

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Description') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Account') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Qty') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Unit price') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Tax') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @foreach ($receipt->lines as $line)
                    <tr data-test="sales-receipt-show-line">
                        <td class="px-4 py-2">{{ $line->description }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $line->account?->code }} — {{ $line->account?->name }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($line->unit_price_cents / 100, 2) }}</td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ $line->taxCode?->code ?? '—' }}
                            @if ($line->secondaryTaxCode)
                                <span class="block">{{ $line->secondaryTaxCode->code }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($line->line_total_cents / 100, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-muted">
                <tr>
                    <td colspan="5" class="px-4 py-2 text-right font-medium">{{ __('Subtotal') }}</td>
                    <td class="px-4 py-2 text-right font-mono">{{ number_format($receipt->subtotal_cents / 100, 2) }}</td>
                </tr>
                @php
                    $taxRows = \App\Support\Tax\LineTaxBreakdown::forLines($receipt->lines);
                @endphp
                @forelse ($taxRows as $taxRow)
                    <tr data-test="sales-receipt-tax-row">
                        <td colspan="5" class="px-4 py-2 text-right font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-2 text-right font-medium">{{ __('Tax') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($receipt->tax_cents / 100, 2) }}</td>
                    </tr>
                @endforelse
                <tr class="text-base">
                    <td colspan="5" class="px-4 py-2 text-right font-semibold">{{ __('Total') }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($receipt->total_cents / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @if ($receipt->memo)
        <flux:text class="mt-4 text-muted-foreground">{{ $receipt->memo }}</flux:text>
    @endif
</section>
