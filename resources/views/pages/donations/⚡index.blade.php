<?php

use App\Enums\DonationStatus;
use App\Models\Company;
use App\Models\Donation;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Donations')] class extends Component {
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
    public function donations()
    {
        return Donation::query()
            ->with('contact')
            ->when($this->search !== '', function ($q) {
                $q->where(function ($w) {
                    $w->where('donation_no', 'like', '%'.$this->search.'%')
                        ->orWhereHas('contact', fn ($c) => $c->where('display_name', 'like', '%'.$this->search.'%'));
                });
            })
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->orderByDesc('donation_date')
            ->orderByDesc('id')
            ->paginate(25);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" data-test="page-title">{{ __('Donations') }}</flux:heading>
            <flux:subheading>{{ __('Record donation income and track restricted gifts.') }}</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" :href="route('donations.create', ['company' => $company])" wire:navigate data-test="new-donation-button">
            {{ __('Record donation') }}
        </flux:button>
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" placeholder="{{ __('Donation # or donor') }}" class="w-64" data-test="donation-search" />
        <flux:select wire:model.live="status" :label="__('Status')" class="w-44" data-test="donation-status-filter">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach (DonationStatus::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Donation #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Donor') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Restriction') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->donations as $donation)
                    <tr data-test="donation-row" class="hover:bg-muted/50">
                        <td class="px-4 py-2">
                            <a href="{{ route('donations.show', ['company' => $company, 'donation' => $donation]) }}" wire:navigate class="font-medium text-primary hover:underline">{{ $donation->donation_no }}</a>
                        </td>
                        <td class="px-4 py-2">{{ $donation->contact?->display_name ?? __('Anonymous') }}</td>
                        <td class="px-4 py-2">{{ $donation->donation_date?->format('M j, Y') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ Money::fromCents($donation->amount_cents, $donation->currency_code ?? $company->currency_code) }}</td>
                        <td class="px-4 py-2">
                            @if ($donation->is_restricted)
                                <flux:badge size="sm" color="amber">{{ __('Restricted') }}</flux:badge>
                            @else
                                <span class="text-muted-foreground">{{ __('Unrestricted') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2"><flux:badge size="sm" :color="$donation->status->color()">{{ $donation->status->label() }}</flux:badge></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-muted-foreground">{{ __('No donations yet.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $this->donations->links() }}
    </div>
</section>
