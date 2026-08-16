<?php

use App\Models\Company;
use App\Models\RecurringDocument;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Recurring')] class extends Component {
    use WithPagination;
    public Company $company;

    #[Url(as: 'type')]
    public string $typeFilter = 'all';

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    #[Computed]
    public function schedules()
    {
        return RecurringDocument::query()
            ->with('contact')
            ->when(in_array($this->typeFilter, ['invoice', 'bill'], true), fn ($q) => $q->where('document_type', $this->typeFilter))
            ->orderBy('is_active', 'desc')
            ->orderBy('next_run_date')
            ->paginate(25);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Recurring') }}</flux:heading>
            <flux:subheading>{{ __('Schedules that generate draft invoices and bills automatically.') }}</flux:subheading>
        </div>

        <div class="flex gap-2">
            <flux:button variant="filled" icon="plus" :href="route('recurring.create', ['company' => $company->slug, 'type' => 'invoice'])" wire:navigate data-test="new-recurring-invoice-button">
                {{ __('New invoice schedule') }}
            </flux:button>
            <flux:button variant="primary" icon="plus" :href="route('recurring.create', ['company' => $company->slug, 'type' => 'bill'])" wire:navigate data-test="new-recurring-bill-button">
                {{ __('New bill schedule') }}
            </flux:button>
        </div>
    </div>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
        <flux:select wire:model.live="typeFilter" class="max-w-[200px]">
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
            <flux:select.option value="invoice">{{ __('Invoices') }}</flux:select.option>
            <flux:select.option value="bill">{{ __('Bills') }}</flux:select.option>
        </flux:select>
    </div>

    {{-- Mobile: stacked cards --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($this->schedules as $schedule)
            <a href="{{ route('recurring.show', ['company' => $company->slug, 'recurring' => $schedule->id]) }}" wire:navigate class="block rounded-lg border border-border p-4" data-test="recurring-card">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-medium">{{ $schedule->name ?: __('Untitled schedule') }}</span>
                    @if ($schedule->paused_reason)
                        <flux:badge size="sm" color="red">{{ __('Needs attention') }}</flux:badge>
                    @elseif (! $schedule->is_active)
                        <flux:badge size="sm" color="zinc">{{ __('Ended') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                    @endif
                </div>
                <div class="mt-2 flex items-center gap-2">
                    @if ($schedule->document_type->value === 'invoice')
                        <flux:badge size="sm" color="sky">{{ __('Invoice') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="purple">{{ __('Bill') }}</flux:badge>
                    @endif
                    <span class="text-sm text-muted-foreground">{{ optional($schedule->contact)->display_name }}</span>
                </div>
                <div class="mt-3 flex items-end justify-between gap-2">
                    <div class="text-xs text-muted-foreground">{{ $schedule->frequency->label() }}</div>
                    <div class="text-right text-xs text-muted-foreground">{{ __('Next run') }}: {{ $schedule->next_run_date?->toDateString() ?? '—' }}</div>
                </div>
            </a>
        @empty
            <flux:text class="block py-8 text-center text-muted-foreground">{{ __('No recurring schedules yet.') }}</flux:text>
        @endforelse
    </div>

    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Type') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Contact') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Frequency') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Next run') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Generated') }}</th>
                    <th class="px-4 py-2">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->schedules as $schedule)
                    <tr data-test="recurring-row" class="cursor-pointer hover:bg-muted">
                        <td class="px-4 py-2">
                            <a href="{{ route('recurring.show', ['company' => $company->slug, 'recurring' => $schedule->id]) }}" wire:navigate class="underline">
                                {{ $schedule->name ?: __('Untitled schedule') }}
                            </a>
                        </td>
                        <td class="px-4 py-2">
                            @if ($schedule->document_type->value === 'invoice')
                                <flux:badge color="sky">{{ __('Invoice') }}</flux:badge>
                            @else
                                <flux:badge color="purple">{{ __('Bill') }}</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-2">{{ optional($schedule->contact)->display_name }}</td>
                        <td class="px-4 py-2">{{ $schedule->frequency->label() }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $schedule->next_run_date?->toDateString() ?? '—' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $schedule->occurrences_generated }}</td>
                        <td class="px-4 py-2">
                            @if ($schedule->paused_reason)
                                <flux:badge color="red">{{ __('Needs attention') }}</flux:badge>
                            @elseif (! $schedule->is_active)
                                <flux:badge color="zinc">{{ __('Ended') }}</flux:badge>
                            @else
                                <flux:badge color="green">{{ __('Active') }}</flux:badge>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-muted-foreground">{{ __('No recurring schedules yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->schedules->links() }}</div>
</section>
