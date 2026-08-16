<?php

use App\Enums\DepositStatus;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Company;
use App\Models\Deposit;
use App\Services\Posting\DepositPoster;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Deposit')] class extends Component {
    public Company $company;

    public Deposit $deposit;

    public function mount(Company $company, Deposit $deposit): void
    {
        $this->company = $company;
        $this->deposit = $deposit->load('lines.customerReceipt.contact', 'lines.account', 'lines.contact', 'bankAccount', 'journalEntry');
    }

    public function void(DepositPoster $poster): void
    {
        try {
            $poster->void($this->deposit);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Deposit voided.'));
        $this->redirectRoute('deposits.index', ['company' => $this->company->slug], navigate: true);
    }

    public function post(DepositPoster $poster): void
    {
        try {
            $poster->post($this->deposit);
        } catch (PeriodLockedException|\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Deposit posted.'));
        $this->redirectRoute('deposits.show', ['company' => $this->company->slug, 'deposit' => $this->deposit->id], navigate: true);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Deposit') }} {{ $deposit->deposit_no }}</flux:heading>
            <flux:subheading>
                {{ $deposit->deposit_date->toDateString() }} &middot;
                {{ $deposit->bankAccount->name }}
            </flux:subheading>
            <div class="mt-2 hidden items-center gap-2 lg:flex">
                @switch($deposit->status->value)
                    @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                    @case('posted') <flux:badge color="green">{{ __('Posted') }}</flux:badge> @break
                    @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                @endswitch

                @if ($deposit->journal_entry_id)
                    <flux:badge color="zinc">
                        <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $deposit->journal_entry_id]) }}" wire:navigate class="underline">
                            {{ __('GL entry') }} {{ optional($deposit->journalEntry)->entry_no }}
                        </a>
                    </flux:badge>
                @endif
            </div>
        </div>

        <div class="flex gap-2">
            @if ($deposit->status === DepositStatus::Draft)
                <flux:button
                    variant="filled"
                    :href="route('deposits.edit', ['company' => $company->slug, 'deposit' => $deposit->id])"
                    wire:navigate
                    data-test="edit-deposit-button"
                >
                    {{ __('Edit') }}
                </flux:button>
                <flux:button variant="primary" wire:click="post" data-test="post-deposit-button">{{ __('Post') }}</flux:button>
            @endif

            @if ($deposit->status === DepositStatus::Posted)
                <flux:button
                    variant="primary"
                    :href="route('deposits.edit', ['company' => $company->slug, 'deposit' => $deposit->id])"
                    wire:navigate
                    data-test="edit-deposit-button"
                >
                    {{ __('Edit') }}
                </flux:button>
            @endif

            <flux:button
                variant="filled"
                icon="document-duplicate"
                :href="route('deposits.create', ['company' => $company->slug, 'from' => $deposit->id])"
                wire:navigate
                data-test="duplicate-deposit-button"
            >
                {{ __('Duplicate') }}
            </flux:button>

            @if ($deposit->status === DepositStatus::Posted)
                <flux:button variant="danger" wire:click="void" wire:confirm="{{ __('Void this deposit? A reversing GL entry will be posted and undeposited receipts will be released.') }}" data-test="void-deposit-button">
                    {{ __('Void') }}
                </flux:button>
            @endif
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Source') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Description') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @foreach ($deposit->lines as $line)
                    <tr data-test="deposit-show-line">
                        <td class="px-4 py-2">
                            @if ($line->customer_receipt_id)
                                {{ __('Receipt') }}
                                <a href="{{ route('receipts.show', ['company' => $company->slug, 'receipt' => $line->customer_receipt_id]) }}" wire:navigate class="font-mono underline">
                                    {{ optional($line->customerReceipt)->receipt_no }}
                                </a>
                            @else
                                {{ optional($line->account)->code }} — {{ optional($line->account)->name }}
                            @endif
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $line->description }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($line->amount_cents / 100, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-muted">
                <tr class="text-base">
                    <td colspan="2" class="px-4 py-2 text-right font-semibold">{{ __('Deposit total') }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($deposit->amount_cents / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @if ($deposit->memo) <flux:text class="mt-4 text-muted-foreground">{{ $deposit->memo }}</flux:text> @endif
</section>
