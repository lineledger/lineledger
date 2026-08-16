<?php

use App\Enums\DonationReceiptStatus;
use App\Models\Company;
use App\Models\DonationReceipt;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Donation receipts')] class extends Component {
    use WithPagination;

    public Company $company;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public function mount(Company $company): void
    {
        $this->company = $company;

        abort_unless($company->isRegisteredCharity(), 403);
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function receipts()
    {
        return DonationReceipt::query()
            ->with('contact')
            ->when($this->search !== '', function ($q) {
                $q->where(function ($w) {
                    $w->where('receipt_no', 'like', '%'.$this->search.'%')
                        ->orWhere('donor_name', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->orderByDesc('gift_date')
            ->orderByDesc('id')
            ->paginate(25);
    }

    public function statusColor(DonationReceiptStatus $status): string
    {
        return match ($status) {
            DonationReceiptStatus::Draft => 'zinc',
            DonationReceiptStatus::Issued => 'green',
            DonationReceiptStatus::Void => 'red',
        };
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" data-test="page-title">{{ __('Donation receipts') }}</flux:heading>
            <flux:subheading>{{ __('Issue and manage official CRA donation receipts.') }}</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" :href="route('donation-receipts.create', ['company' => $company])" wire:navigate data-test="new-donation-receipt-button">
            {{ __('New receipt') }}
        </flux:button>
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" placeholder="{{ __('Receipt # or donor') }}" class="w-64" data-test="donation-receipt-search" />
        <flux:select wire:model.live="status" :label="__('Status')" class="w-44" data-test="donation-receipt-status-filter">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach (DonationReceiptStatus::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Receipt #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Donor') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Gift date') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Eligible amount') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->receipts as $receipt)
                    <tr data-test="donation-receipt-row" class="hover:bg-muted/50">
                        <td class="px-4 py-2">
                            <a href="{{ route('donation-receipts.show', ['company' => $company, 'donationReceipt' => $receipt]) }}" wire:navigate class="font-medium text-primary hover:underline">{{ $receipt->receipt_no }}</a>
                        </td>
                        <td class="px-4 py-2">{{ $receipt->donor_name }}</td>
                        <td class="px-4 py-2">{{ $receipt->gift_date?->format('M j, Y') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ Money::fromCents($receipt->eligible_amount_cents, $receipt->currency_code ?? $company->currency_code) }}</td>
                        <td class="px-4 py-2">
                            <flux:badge size="sm" :color="$this->statusColor($receipt->status)">{{ $receipt->status->label() }}</flux:badge>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-muted-foreground">{{ __('No donation receipts yet.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $this->receipts->links() }}
    </div>
</section>
