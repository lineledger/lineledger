<?php

use App\Enums\DonationReceiptStatus;
use App\Models\Company;
use App\Models\DonationReceipt;
use App\Services\Charity\DonationReceiptIssuer;
use App\Support\Money;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Donation receipt')] class extends Component {
    public Company $company;

    public DonationReceipt $receipt;

    public string $voidReason = '';

    public function mount(Company $company, DonationReceipt $donationReceipt): void
    {
        $this->company = $company;

        abort_unless($company->isRegisteredCharity(), 403);
        abort_unless($donationReceipt->company_id === $company->id, 404);

        $this->receipt = $donationReceipt->load('contact', 'journalEntry', 'reissuedFrom');
    }

    public function issue(): void
    {
        try {
            app(DonationReceiptIssuer::class)->issue($this->receipt);
        } catch (\InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->receipt->refresh();
        Flux::toast(variant: 'success', text: __('Receipt issued.'));
    }

    public function void(): void
    {
        $this->validate(['voidReason' => ['required', 'string', 'max:255']]);

        app(DonationReceiptIssuer::class)->void($this->receipt, $this->voidReason);

        $this->receipt->refresh();
        $this->voidReason = '';
        Flux::modal('void-receipt')->close();
        Flux::toast(variant: 'success', text: __('Receipt voided.'));
    }

    public function reissue(): void
    {
        $draft = app(DonationReceiptIssuer::class)->reissue($this->receipt);

        Flux::toast(variant: 'success', text: __('A corrected draft was created.'));

        $this->redirectRoute('donation-receipts.show', ['company' => $this->company, 'donationReceipt' => $draft], navigate: true);
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

<section class="mx-auto w-full max-w-2xl">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <flux:heading size="xl" level="1" data-test="page-title">{{ $receipt->receipt_no }}</flux:heading>
                <flux:badge :color="$this->statusColor($receipt->status)" data-test="donation-receipt-status">{{ $receipt->status->label() }}</flux:badge>
            </div>
            <flux:subheading>{{ $receipt->donor_name }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($receipt->isDraft())
                <flux:button variant="ghost" icon="pencil" :href="route('donation-receipts.edit', ['company' => $company, 'donationReceipt' => $receipt])" wire:navigate data-test="donation-receipt-edit-button">{{ __('Edit') }}</flux:button>
                <flux:button variant="primary" icon="check" wire:click="issue" data-test="donation-receipt-issue-button">{{ __('Issue receipt') }}</flux:button>
            @elseif ($receipt->isIssued())
                <flux:button variant="ghost" icon="printer" :href="route('donation-receipts.print', ['company' => $company, 'donationReceipt' => $receipt])" target="_blank" data-test="donation-receipt-print-button">{{ __('Print') }}</flux:button>
                <flux:button variant="ghost" icon="arrow-path" wire:click="reissue" data-test="donation-receipt-reissue-button">{{ __('Reissue') }}</flux:button>
                <flux:modal.trigger name="void-receipt">
                    <flux:button variant="danger" icon="x-mark" data-test="donation-receipt-void-button">{{ __('Void') }}</flux:button>
                </flux:modal.trigger>
            @else
                <flux:button variant="ghost" icon="printer" :href="route('donation-receipts.print', ['company' => $company, 'donationReceipt' => $receipt])" target="_blank" data-test="donation-receipt-print-button">{{ __('Print') }}</flux:button>
            @endif
        </div>
    </div>

    <div class="space-y-4 rounded-lg border border-border p-6">
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <div class="text-muted-foreground">{{ __('Gift type') }}</div>
                <div>{{ $receipt->gift_type->label() }}</div>
            </div>
            <div>
                <div class="text-muted-foreground">{{ __('Gift date') }}</div>
                <div>{{ $receipt->gift_date?->format('M j, Y') }}</div>
            </div>
            <div>
                <div class="text-muted-foreground">{{ __('Fair market value') }}</div>
                <div class="font-mono">{{ Money::fromCents($receipt->amount_cents, $receipt->currency_code ?? $company->currency_code) }}</div>
            </div>
            <div>
                <div class="text-muted-foreground">{{ __('Advantage') }}</div>
                <div class="font-mono">{{ Money::fromCents($receipt->advantage_cents, $receipt->currency_code ?? $company->currency_code) }}</div>
            </div>
            <div>
                <div class="text-muted-foreground">{{ __('Eligible amount') }}</div>
                <div class="font-mono font-semibold" data-test="donation-receipt-eligible">{{ Money::fromCents($receipt->eligible_amount_cents, $receipt->currency_code ?? $company->currency_code) }}</div>
            </div>
            @if ($receipt->issued_date)
                <div>
                    <div class="text-muted-foreground">{{ __('Issued') }}</div>
                    <div>{{ $receipt->issued_date->format('M j, Y') }}</div>
                </div>
            @endif
        </div>

        @if ($receipt->isInKind())
            <flux:separator />
            <div class="text-sm">
                <div class="text-muted-foreground">{{ __('Property description') }}</div>
                <div>{{ $receipt->in_kind_description ?: '—' }}</div>
                @if ($receipt->appraised_by)
                    <div class="mt-2 text-muted-foreground">{{ __('Appraised by :who on :when', ['who' => $receipt->appraised_by, 'when' => $receipt->appraisal_date?->format('M j, Y')]) }}</div>
                @endif
            </div>
        @endif

        @if ($receipt->journalEntry)
            <flux:separator />
            <div class="text-sm">
                <a href="{{ route('journal.show', ['company' => $company, 'entry' => $receipt->journalEntry]) }}" wire:navigate class="text-primary hover:underline">{{ __('View journal entry') }} #{{ $receipt->journalEntry->id }}</a>
            </div>
        @endif

        @if ($receipt->status === DonationReceiptStatus::Void && $receipt->void_reason)
            <flux:separator />
            <div class="text-sm text-destructive">{{ __('Voided:') }} {{ $receipt->void_reason }}</div>
        @endif
    </div>

    <flux:modal name="void-receipt" class="max-w-lg">
        <form wire:submit="void" class="space-y-6">
            <flux:heading size="lg">{{ __('Void receipt') }}</flux:heading>
            <flux:text>{{ __('The serial number is retained on the record, as the CRA requires. Any in-kind GL entry is reversed.') }}</flux:text>
            <flux:input wire:model="voidReason" :label="__('Reason')" required data-test="void-reason-input" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" type="submit" data-test="void-confirm-button">{{ __('Void receipt') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
