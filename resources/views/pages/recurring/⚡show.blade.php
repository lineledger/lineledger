<?php

use App\Models\Bill;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\RecurringDocument;
use App\Services\Recurring\RecurringDocumentGenerator;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Recurring schedule')] class extends Component {
    public Company $company;

    public RecurringDocument $recurring;

    public function mount(Company $company, RecurringDocument $recurring): void
    {
        $this->company = $company;
        $this->recurring = $recurring->load(['lines.account', 'lines.taxCode', 'contact', 'terms']);
    }

    public function pauseSchedule(): void
    {
        $this->recurring->update(['is_active' => false]);
        $this->recurring->refresh();
        Flux::toast(variant: 'success', text: __('Schedule paused.'));
    }

    public function resumeSchedule(): void
    {
        $this->recurring->update(['is_active' => true, 'paused_reason' => null]);
        $this->recurring->refresh();
        Flux::toast(variant: 'success', text: __('Schedule resumed.'));
    }

    public function generateNow(RecurringDocumentGenerator $generator): void
    {
        try {
            $document = $generator->generateOne($this->recurring);
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->recurring->refresh();

        if ($document instanceof Invoice) {
            $this->redirectRoute('invoices.edit', ['company' => $this->company->slug, 'invoice' => $document->id], navigate: true);

            return;
        }

        $this->redirectRoute('bills.edit', ['company' => $this->company->slug, 'bill' => $document->id], navigate: true);
    }

    public function deleteSchedule(): void
    {
        $this->recurring->delete();
        Flux::toast(variant: 'success', text: __('Schedule deleted.'));
        $this->redirectRoute('recurring.index', ['company' => $this->company->slug], navigate: true);
    }

    #[Computed]
    public function generated()
    {
        $query = $this->recurring->isInvoice()
            ? Invoice::query()->where('recurring_document_id', $this->recurring->id)
            : Bill::query()->where('recurring_document_id', $this->recurring->id);

        return $query->with('contact')->orderByDesc('id')->limit(50)->get();
    }

    #[Computed]
    public function resumable(): bool
    {
        return ! $this->recurring->is_active && $this->recurring->next_run_date !== null;
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $recurring->name ?: __('Untitled schedule') }}</flux:heading>
            <flux:subheading>
                @if ($recurring->isInvoice())
                    {{ __('Recurring invoice') }}
                @else
                    {{ __('Recurring bill') }}
                @endif
                — {{ optional($recurring->contact)->display_name }}
            </flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($recurring->is_active)
                <flux:button variant="primary" class="!hidden lg:!inline-flex" icon="bolt" wire:click="generateNow" data-test="generate-now-button">{{ __('Generate now') }}</flux:button>
            @endif

            <flux:dropdown align="end">
                <flux:button icon:trailing="chevron-down" data-test="recurring-actions-menu">{{ __('Actions') }}</flux:button>
                <flux:menu>
                    @if ($recurring->is_active)
                        <flux:menu.item class="lg:hidden" icon="bolt" wire:click="generateNow" data-test="generate-now-menu-item">{{ __('Generate now') }}</flux:menu.item>
                    @endif
                    <flux:menu.item icon="pencil-square" :href="route('recurring.edit', ['company' => $company->slug, 'recurring' => $recurring->id])" wire:navigate data-test="edit-recurring-button">
                        {{ __('Edit') }}
                    </flux:menu.item>
                    @if ($recurring->is_active)
                        <flux:menu.item icon="pause" wire:click="pauseSchedule" data-test="pause-button">{{ __('Pause') }}</flux:menu.item>
                    @elseif ($this->resumable)
                        <flux:menu.item icon="play" wire:click="resumeSchedule" data-test="resume-button">{{ __('Resume') }}</flux:menu.item>
                    @endif
                    <flux:menu.separator />
                    <flux:menu.item icon="trash" variant="danger" wire:click="deleteSchedule" wire:confirm="{{ __('Delete this recurring schedule?') }}" data-test="delete-button">{{ __('Delete') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    @if ($recurring->paused_reason)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6" :heading="__('Paused — needs attention')">
            {{ $recurring->paused_reason }}
        </flux:callout>
    @endif

    <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
        <div>
            <flux:text size="sm" class="text-muted-foreground">{{ __('Frequency') }}</flux:text>
            <flux:heading>{{ $recurring->frequency->label() }}</flux:heading>
        </div>
        <div>
            <flux:text size="sm" class="text-muted-foreground">{{ __('Next run') }}</flux:text>
            <flux:heading>{{ $recurring->next_run_date?->toDateString() ?? '—' }}</flux:heading>
        </div>
        <div>
            <flux:text size="sm" class="text-muted-foreground">{{ __('Generated') }}</flux:text>
            <flux:heading>{{ $recurring->occurrences_generated }}</flux:heading>
        </div>
        <div>
            <flux:text size="sm" class="text-muted-foreground">{{ __('Status') }}</flux:text>
            <flux:heading>
                @if ($recurring->paused_reason)
                    {{ __('Needs attention') }}
                @elseif ($recurring->hasEnded())
                    {{ __('Ended') }}
                @elseif (! $recurring->is_active)
                    {{ __('Paused') }}
                @else
                    {{ __('Active') }}
                @endif
            </flux:heading>
        </div>
    </div>

    <flux:separator :text="__('Template line items')" class="mb-4" />

    <div class="mb-8 overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Description') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Account') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Qty') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Unit price') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Tax') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @foreach ($recurring->lines as $line)
                    <tr>
                        <td class="px-4 py-2">{{ $line->description }}</td>
                        <td class="px-4 py-2">{{ optional($line->account)->code }} — {{ optional($line->account)->name }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') ?: '0' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($line->unit_price_cents / 100, 2) }}</td>
                        <td class="px-4 py-2">{{ optional($line->taxCode)->code ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <flux:separator :text="__('Generated documents')" class="mb-4" />

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Number') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                    <th class="px-4 py-2">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->generated as $doc)
                    <tr class="hover:bg-muted">
                        <td class="px-4 py-2 whitespace-nowrap">{{ ($recurring->isInvoice() ? $doc->invoice_date : $doc->bill_date)->toDateString() }}</td>
                        <td class="px-4 py-2 font-mono">
                            <a href="{{ $recurring->isInvoice()
                                ? route('invoices.show', ['company' => $company->slug, 'invoice' => $doc->id])
                                : route('bills.show', ['company' => $company->slug, 'bill' => $doc->id]) }}" wire:navigate class="underline">
                                {{ $recurring->isInvoice() ? $doc->invoice_no : $doc->bill_no }}
                            </a>
                        </td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($doc->total_cents / 100, 2) }}</td>
                        <td class="px-4 py-2"><flux:badge size="sm">{{ $doc->status->label() }}</flux:badge></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-muted-foreground">{{ __('Nothing generated yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
