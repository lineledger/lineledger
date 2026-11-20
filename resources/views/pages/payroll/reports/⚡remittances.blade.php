<?php

use App\Enums\PermissionAction;
use App\Enums\RemittanceStatus;
use App\Livewire\Attributes\Requires;
use App\Livewire\Concerns\DeletesTransaction;
use App\Models\Company;
use App\Models\PayrollRemittance;
use App\Services\Deletion\TransactionDeleter;
use App\Services\Posting\PayrollRemittancePoster;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Remittance history')] class extends Component {
    use DeletesTransaction;

    public Company $company;

    /** The remittance the Delete dialog is about. */
    #[Locked]
    public ?int $deletingRemittanceId = null;

    public function mount(Company $company): void
    {
        abort_unless($company->usesPayroll(), 404);

        $this->company = $company;
    }

    #[Computed]
    public function remittances()
    {
        return PayrollRemittance::query()
            ->with('journalEntry')
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->get();
    }

    #[Requires(null, PermissionAction::Delete)]
    public function voidRemittance(int $id, PayrollRemittancePoster $poster): void
    {
        $remittance = PayrollRemittance::findOrFail($id);

        if ($remittance->status === RemittanceStatus::Void) {
            return;
        }

        $poster->void($remittance);
        unset($this->remittances);

        Flux::toast(variant: 'success', text: __('Remittance voided.'));
    }

    /**
     * Open the Delete dialog for one remittance.
     */
    #[Requires(null, PermissionAction::Delete)]
    public function prepareDeleteRemittance(int $id, TransactionDeleter $deleter): void
    {
        $this->deletingRemittanceId = (int) PayrollRemittance::query()->findOrFail($id)->id;

        $this->prepareDelete($deleter);
    }

    protected function deletableDocument(): Model
    {
        return PayrollRemittance::query()->findOrFail($this->deletingRemittanceId);
    }

    /**
     * The remittance the Delete dialog is open for, if any.
     */
    #[Computed]
    public function deletingRemittance(): ?PayrollRemittance
    {
        return $this->deletingRemittanceId !== null ? PayrollRemittance::query()->find($this->deletingRemittanceId) : null;
    }

    public function money(int $cents): string
    {
        return Money::fromCents($cents)->format();
    }
}; ?>

<section class="w-full">
    <div class="mb-6">
        <flux:heading size="xl" level="1">{{ __('Remittance history') }}</flux:heading>
        <flux:subheading>{{ __('Source-deduction remittances you have recorded to the CRA and Revenu Québec.') }}</flux:subheading>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Agency') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Period') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Due') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Paid') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->remittances as $remittance)
                    <tr class="@if ($remittance->status === \App\Enums\RemittanceStatus::Void) opacity-50 @endif">
                        <td class="px-4 py-2 font-medium">{{ $remittance->agency->label() }}</td>
                        <td class="px-4 py-2">{{ $remittance->period_start->format('M j') }} – {{ $remittance->period_end->format('M j, Y') }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $remittance->due_date->format('M j, Y') }}</td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ $remittance->payment_date->format('M j, Y') }}
                            @if ($remittance->reference)<div class="text-xs">{{ $remittance->reference }}</div>@endif
                        </td>
                        <td class="px-4 py-2 text-right font-mono">{{ $this->money($remittance->total_cents) }}</td>
                        <td class="px-4 py-2">
                            <flux:badge :color="$remittance->status === \App\Enums\RemittanceStatus::Paid ? 'green' : 'zinc'" size="sm">
                                {{ $remittance->status->label() }}
                            </flux:badge>
                        </td>
                        <td class="px-4 py-2 text-right">
                            @if ($remittance->status === \App\Enums\RemittanceStatus::Paid)
                                @callable($this, 'voidRemittance')
                                <flux:button
                                    variant="ghost" size="sm" icon="x-circle"
                                    wire:click="voidRemittance({{ $remittance->id }})"
                                    wire:confirm="{{ __('Void this remittance? The journal entry will be reversed.') }}"
                                >{{ __('Void') }}</flux:button>
                                @endcallable
                            @endif
                            @if ($company->allowsTransactionDeletion())
                                @callable($this, 'prepareDeleteRemittance')
                                <flux:button variant="ghost" size="sm" icon="trash" wire:click="prepareDeleteRemittance({{ $remittance->id }})" :aria-label="__('Delete')" data-test="delete-remittance-button" />
                                @endcallable
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-muted-foreground">{{ __('No remittances recorded yet. Record one from the PD7A or Revenu Québec page.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($this->deletingRemittance)
        <x-transaction-delete-dialog :preview="$deletionPreview" :document="$this->deletingRemittance" />
    @endif
    <x-period-lock-override :company="$company" :message="$periodLockMessage" />
</section>
