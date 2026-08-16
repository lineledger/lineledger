<?php

use App\Enums\GrantStatus;
use App\Models\Company;
use App\Models\Grant;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Grants')] class extends Component {
    use WithPagination;

    public Company $company;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public function mount(Company $company): void
    {
        $this->company = $company;

        abort_unless($company->tracksFundraising(), 403);
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function grants()
    {
        return Grant::query()
            ->with('funder')
            ->when($this->search !== '', function ($q) {
                $q->where(function ($w) {
                    $w->where('grant_no', 'like', '%'.$this->search.'%')
                        ->orWhere('name', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->orderByDesc('id')
            ->paginate(25);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" data-test="page-title">{{ __('Grants') }}</flux:heading>
            <flux:subheading>{{ __('Track grants from funders, restrictions, and revenue recognition.') }}</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" :href="route('grants.create', ['company' => $company])" wire:navigate data-test="new-grant-button">
            {{ __('New grant') }}
        </flux:button>
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" placeholder="{{ __('Grant # or name') }}" class="w-64" data-test="grant-search" />
        <flux:select wire:model.live="status" :label="__('Status')" class="w-44" data-test="grant-status-filter">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach (GrantStatus::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Grant #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Funder') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Award') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Deferred balance') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->grants as $grant)
                    <tr data-test="grant-row" class="hover:bg-muted/50">
                        <td class="px-4 py-2">
                            <a href="{{ route('grants.show', ['company' => $company, 'grant' => $grant]) }}" wire:navigate class="font-medium text-primary hover:underline">{{ $grant->grant_no }}</a>
                        </td>
                        <td class="px-4 py-2">{{ $grant->name }}</td>
                        <td class="px-4 py-2">{{ $grant->funder?->display_name ?? '—' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ Money::fromCents($grant->award_amount_cents, $grant->currency_code ?? $company->currency_code) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ Money::fromCents($grant->deferredBalanceCents(), $grant->currency_code ?? $company->currency_code) }}</td>
                        <td class="px-4 py-2"><flux:badge size="sm" :color="$grant->status->color()">{{ $grant->status->label() }}</flux:badge></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-muted-foreground">{{ __('No grants yet.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $this->grants->links() }}
    </div>
</section>
