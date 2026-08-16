<?php

use App\Models\Company;
use App\Models\Donation;
use App\Services\Fundraising\DonationPoster;
use App\Support\Money;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Donation')] class extends Component {
    public Company $company;

    public Donation $donation;

    public function mount(Company $company, Donation $donation): void
    {
        $this->company = $company;

        abort_unless($company->tracksFundraising(), 403);
        abort_unless($donation->company_id === $company->id, 404);

        $this->donation = $donation->load('contact', 'fund', 'journalEntry', 'depositToAccount', 'donationReceipt');
    }

    public function post(): void
    {
        try {
            app(DonationPoster::class)->post($this->donation);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->donation->refresh()->load('journalEntry');
        Flux::toast(variant: 'success', text: __('Donation posted.'));
    }

    public function void(): void
    {
        try {
            app(DonationPoster::class)->void($this->donation);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->donation->refresh();
        Flux::toast(variant: 'success', text: __('Donation voided.'));
    }
}; ?>

<section class="mx-auto w-full max-w-2xl">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <flux:heading size="xl" level="1" data-test="page-title">{{ $donation->donation_no }}</flux:heading>
                <flux:badge :color="$donation->status->color()" data-test="donation-status">{{ $donation->status->label() }}</flux:badge>
                @if ($donation->is_restricted)
                    <flux:badge color="amber">{{ __('Restricted') }}</flux:badge>
                @endif
            </div>
            <flux:subheading>{{ $donation->contact?->display_name ?? __('Anonymous') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($donation->isDraft())
                <flux:button variant="ghost" icon="pencil" :href="route('donations.edit', ['company' => $company, 'donation' => $donation])" wire:navigate data-test="donation-edit-button">{{ __('Edit') }}</flux:button>
                <flux:button variant="primary" icon="check" wire:click="post" data-test="donation-post-button">{{ __('Post') }}</flux:button>
            @elseif ($donation->isPosted())
                <flux:button variant="danger" icon="x-mark" wire:click="void" wire:confirm="{{ __('Void this donation? Its journal entry will be reversed.') }}" data-test="donation-void-button">{{ __('Void') }}</flux:button>
            @endif
        </div>
    </div>

    <div class="space-y-4 rounded-lg border border-border p-6 text-sm">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <div class="text-muted-foreground">{{ __('Gift type') }}</div>
                <div>{{ $donation->gift_type->label() }}</div>
            </div>
            <div>
                <div class="text-muted-foreground">{{ __('Date') }}</div>
                <div>{{ $donation->donation_date?->format('M j, Y') }}</div>
            </div>
            <div>
                <div class="text-muted-foreground">{{ __('Amount') }}</div>
                <div class="font-mono font-semibold" data-test="donation-amount">{{ Money::fromCents($donation->amount_cents, $donation->currency_code ?? $company->currency_code) }}</div>
            </div>
            <div>
                <div class="text-muted-foreground">{{ __('Deposited to') }}</div>
                <div>{{ $donation->depositToAccount ? $donation->depositToAccount->code.' — '.$donation->depositToAccount->name : '—' }}</div>
            </div>
            @if ($donation->fund)
                <div>
                    <div class="text-muted-foreground">{{ __('Fund') }}</div>
                    <div>{{ $donation->fund->name }}</div>
                </div>
            @endif
        </div>

        @if ($donation->is_restricted && $donation->restriction_note)
            <flux:separator />
            <div>
                <div class="text-muted-foreground">{{ __('Restriction') }}</div>
                <div>{{ $donation->restriction_note }}</div>
            </div>
        @endif

        @if ($donation->journalEntry)
            <flux:separator />
            <a href="{{ route('journal.show', ['company' => $company, 'entry' => $donation->journalEntry]) }}" wire:navigate class="text-primary hover:underline">{{ __('View journal entry') }} #{{ $donation->journalEntry->id }}</a>
        @endif

        @if ($donation->donationReceipt)
            <flux:separator />
            <a href="{{ route('donation-receipts.show', ['company' => $company, 'donationReceipt' => $donation->donationReceipt]) }}" wire:navigate class="text-primary hover:underline">{{ __('View linked donation receipt') }} {{ $donation->donationReceipt->receipt_no }}</a>
        @endif
    </div>
</section>
