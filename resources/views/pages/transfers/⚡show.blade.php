<?php

use App\Enums\TransferStatus;
use App\Models\Company;
use App\Models\Transfer;
use App\Services\Posting\TransferPoster;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Transfer')] class extends Component {
    public Company $company;

    public Transfer $transfer;

    public function mount(Company $company, Transfer $transfer): void
    {
        $this->company = $company;
        $this->transfer = $transfer->load('fromAccount', 'toAccount', 'journalEntry');
    }

    public function void(TransferPoster $poster): void
    {
        try {
            $poster->void($this->transfer);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Transfer voided.'));
        $this->redirectRoute('transfers.index', ['company' => $this->company->slug], navigate: true);
    }

    public function isCrossCurrency(): bool
    {
        return $this->transfer->from_currency_code !== $this->transfer->to_currency_code;
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Transfer') }} {{ $transfer->transfer_no }}</flux:heading>
            <flux:subheading>
                {{ $transfer->transfer_date->toDateString() }} &middot;
                {{ optional($transfer->fromAccount)->name }} → {{ optional($transfer->toAccount)->name }}
            </flux:subheading>
            <div class="mt-2 flex items-center gap-2">
                @switch($transfer->status->value)
                    @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                    @case('posted') <flux:badge color="green">{{ __('Posted') }}</flux:badge> @break
                    @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                @endswitch

                @if ($transfer->journal_entry_id)
                    <flux:badge color="zinc">
                        <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $transfer->journal_entry_id]) }}" wire:navigate class="underline">
                            {{ __('GL entry') }} {{ optional($transfer->journalEntry)->entry_no }}
                        </a>
                    </flux:badge>
                @endif
            </div>
        </div>

        <div class="flex gap-2">
            @if ($transfer->status === TransferStatus::Draft)
                <flux:button variant="filled" :href="route('transfers.edit', ['company' => $company->slug, 'transfer' => $transfer->id])" wire:navigate>
                    {{ __('Edit') }}
                </flux:button>
            @endif
            @if ($transfer->status === TransferStatus::Posted)
                <flux:button variant="danger" wire:click="void" wire:confirm="{{ __('Void this transfer? A reversing GL entry will be posted.') }}" data-test="void-transfer-button">
                    {{ __('Void') }}
                </flux:button>
            @endif
        </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-border">
        <table class="w-full text-sm">
            <tbody class="divide-y divide-border">
                <tr>
                    <td class="px-4 py-3 text-muted-foreground">{{ __('From') }}</td>
                    <td class="px-4 py-3 text-right">{{ optional($transfer->fromAccount)->code }} — {{ optional($transfer->fromAccount)->name }}</td>
                    <td class="px-4 py-3 text-right font-mono">{{ number_format($transfer->from_amount_cents / 100, 2) }} {{ $transfer->from_currency_code ?? $company->currency_code }}</td>
                </tr>
                <tr>
                    <td class="px-4 py-3 text-muted-foreground">{{ __('To') }}</td>
                    <td class="px-4 py-3 text-right">{{ optional($transfer->toAccount)->code }} — {{ optional($transfer->toAccount)->name }}</td>
                    <td class="px-4 py-3 text-right font-mono">{{ number_format($transfer->to_amount_cents / 100, 2) }} {{ $transfer->to_currency_code ?? $company->currency_code }}</td>
                </tr>
                @if ($this->isCrossCurrency() && $transfer->home_amount_cents !== null)
                    <tr>
                        <td class="px-4 py-3 text-muted-foreground">{{ __('Home value') }}</td>
                        <td class="px-4 py-3"></td>
                        <td class="px-4 py-3 text-right font-mono">{{ number_format($transfer->home_amount_cents / 100, 2) }} {{ $company->currency_code }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    @if ($transfer->memo) <flux:text class="mt-4 text-muted-foreground">{{ $transfer->memo }}</flux:text> @endif
</section>
