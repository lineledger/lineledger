<?php

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\Company;
use App\Services\Posting\BillPoster;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Reimbursement')] class extends Component {
    public Company $company;

    public Bill $bill;

    public function mount(Company $company, Bill $bill): void
    {
        $this->company = $company;
        $this->bill = $bill->load('lines.account', 'lines.taxCode', 'lines.secondaryTaxCode', 'contact', 'journalEntry');
    }

    public function void(BillPoster $poster): void
    {
        try {
            $poster->void($this->bill);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Reimbursement voided.'));
        $this->redirectRoute('reimbursements.index', ['company' => $this->company->slug], navigate: true);
    }

    public function deleteDraft(): void
    {
        if ($this->bill->journal_entry_id) {
            Flux::toast(variant: 'danger', text: __('A posted reimbursement cannot be deleted. Void it instead.'));

            return;
        }

        $this->bill->lines()->delete();
        $this->bill->delete();

        Flux::toast(variant: 'success', text: __('Draft deleted.'));
        $this->redirectRoute('reimbursements.index', ['company' => $this->company->slug], navigate: true);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Reimbursement') }} {{ $bill->bill_no }}</flux:heading>
            <flux:subheading>
                {{ $bill->contact->display_name }} &middot;
                {{ $bill->bill_date->toDateString() }}
            </flux:subheading>
            <div class="mt-2 hidden items-center gap-2 lg:flex">
                @switch($bill->status->value)
                    @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                    @case('posted') <flux:badge color="blue">{{ __('Posted') }}</flux:badge> @break
                    @case('partial') <flux:badge color="indigo">{{ __('Partial') }}</flux:badge> @break
                    @case('paid') <flux:badge color="green">{{ __('Paid') }}</flux:badge> @break
                    @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                @endswitch

                @if ($bill->journal_entry_id)
                    <flux:badge color="zinc">
                        <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $bill->journal_entry_id]) }}" wire:navigate class="underline">
                            {{ __('GL entry') }} {{ optional($bill->journalEntry)->entry_no }}
                        </a>
                    </flux:badge>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($bill->status !== BillStatus::Void && $bill->status !== BillStatus::Draft && $bill->balanceCents() > 0)
                <flux:button variant="primary" class="!hidden lg:!inline-flex" :href="route('bill-payments.create', ['company' => $company->slug, 'bill' => $bill->id])" wire:navigate data-test="pay-employee-button">
                    {{ __('Pay employee') }}
                </flux:button>
            @endif

            <flux:dropdown align="end">
                <flux:button icon:trailing="chevron-down" data-test="reimbursement-actions-menu">{{ __('Actions') }}</flux:button>
                <flux:menu>
                    @if ($bill->status !== BillStatus::Void && $bill->status !== BillStatus::Draft && $bill->balanceCents() > 0)
                        <flux:menu.item class="lg:hidden" icon="credit-card" :href="route('bill-payments.create', ['company' => $company->slug, 'bill' => $bill->id])" wire:navigate data-test="pay-employee-menu-item">
                            {{ __('Pay employee') }}
                        </flux:menu.item>
                    @endif
                    <flux:menu.item icon="printer" :href="route('reimbursements.print', ['company' => $company->slug, 'bill' => $bill->id])" target="_blank" data-test="print-reimbursement-button">
                        {{ __('Print') }}
                    </flux:menu.item>
                    @if ($bill->status !== BillStatus::Void)
                        <flux:menu.item icon="pencil" :href="route('reimbursements.edit', ['company' => $company->slug, 'bill' => $bill->id])" wire:navigate data-test="edit-reimbursement-button">
                            {{ __('Edit') }}
                        </flux:menu.item>
                    @endif
                    @if ($bill->status !== BillStatus::Void && $bill->status !== BillStatus::Draft)
                        <flux:menu.separator />
                        <flux:menu.item icon="x-circle" variant="danger" wire:click="void" wire:confirm="{{ __('Void this reimbursement?') }}" data-test="void-reimbursement-button">
                            {{ __('Void') }}
                        </flux:menu.item>
                    @elseif ($bill->status === BillStatus::Draft)
                        <flux:menu.separator />
                        <flux:menu.item icon="trash" variant="danger" wire:click="deleteDraft" wire:confirm="{{ __('Delete this draft reimbursement?') }}" data-test="delete-reimbursement-button">
                            {{ __('Delete draft') }}
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
                    <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Tax') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @foreach ($bill->lines as $line)
                    <tr>
                        <td class="px-4 py-2">{{ $line->description }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ optional($line->account)->code }} — {{ optional($line->account)->name }}</td>
                        <td class="px-4 py-2 text-right">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($line->unit_price_cents / 100, 2) }}</td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ optional($line->taxCode)->code }}
                            @if ($line->secondaryTaxCode)
                                <span class="block">{{ $line->secondaryTaxCode->code }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($line->line_total_cents / 100, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-muted">
                @foreach (\App\Support\Tax\LineTaxBreakdown::forLines($bill->lines) as $taxRow)
                    <tr data-test="reimbursement-tax-row">
                        <td colspan="5" class="px-4 py-2 text-right font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</td>
                    </tr>
                @endforeach
                <tr class="text-base">
                    <td colspan="5" class="px-4 py-2 text-right font-semibold">{{ __('Total') }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($bill->total_cents / 100, 2) }}</td>
                </tr>
                <tr>
                    <td colspan="5" class="px-4 py-2 text-right">{{ __('Paid') }}</td>
                    <td class="px-4 py-2 text-right font-mono">{{ number_format($bill->amount_paid_cents / 100, 2) }}</td>
                </tr>
                <tr class="text-base">
                    <td colspan="5" class="px-4 py-2 text-right font-semibold">{{ __('Owed') }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($bill->balanceCents() / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @if ($bill->memo) <flux:text class="mt-4 text-muted-foreground">{{ $bill->memo }}</flux:text> @endif
</section>
