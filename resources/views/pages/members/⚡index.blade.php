<?php

use App\Models\Company;
use App\Models\Member;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Members')] class extends Component {
    use WithPagination;

    public Company $company;

    #[Url]
    public string $search = '';

    public bool $showInactive = false;

    public function mount(Company $company): void
    {
        $this->company = $company;

        abort_unless($company->tracksMembership(), 403);
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function members()
    {
        return Member::query()
            ->with(['company', 'contact', 'level', 'invoices:id,member_id,status,total_cents,amount_paid_cents,reconciled_cents'])
            ->when(! $this->showInactive, fn ($q) => $q->where('is_active', true))
            ->when($this->search !== '', function ($q) {
                $q->where(function ($w) {
                    $w->where('member_no', 'like', '%'.$this->search.'%')
                        ->orWhereHas('contact', fn ($c) => $c->where('display_name', 'like', '%'.$this->search.'%'));
                });
            })
            ->orderBy('member_no')
            ->paginate(25);
    }

    public function openDuesCents(Member $member): int
    {
        return $member->invoices
            ->filter(fn ($i) => $i->status->isOpen())
            ->sum(fn ($i) => max(0, $i->balanceCents()));
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" data-test="page-title">{{ __('Members') }}</flux:heading>
            <flux:subheading>{{ __('Track members, their tier, and dues.') }}</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" :href="route('members.create', ['company' => $company])" wire:navigate data-test="new-member-button">
            {{ __('New member') }}
        </flux:button>
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" placeholder="{{ __('Member # or name') }}" class="w-64" data-test="member-search" />
        <flux:checkbox wire:model.live="showInactive" :label="__('Show inactive')" data-test="member-show-inactive" />
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Member #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Level') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Expires') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Open dues') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->members as $member)
                    @php($status = $member->effectiveStatus())
                    <tr data-test="member-row" class="hover:bg-muted/50 @if (! $member->is_active) opacity-50 @endif">
                        <td class="px-4 py-2">
                            <a href="{{ route('members.show', ['company' => $company, 'member' => $member]) }}" wire:navigate class="font-medium text-primary hover:underline">{{ $member->member_no }}</a>
                        </td>
                        <td class="px-4 py-2">{{ $member->contact?->display_name }}</td>
                        <td class="px-4 py-2">{{ $member->level?->name ?? '—' }}</td>
                        <td class="px-4 py-2"><flux:badge size="sm" :color="$status->color()">{{ $status->label() }}</flux:badge></td>
                        <td class="px-4 py-2">{{ $member->expires_on?->format('M j, Y') ?? '—' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ Money::fromCents($this->openDuesCents($member), $company->currency_code) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-muted-foreground">{{ __('No members yet.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $this->members->links() }}
    </div>
</section>
