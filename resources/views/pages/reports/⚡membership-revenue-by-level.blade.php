<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Dues Revenue by Level')] class extends Component {
    public Company $company;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(Company $company): void
    {
        $this->company = $company;

        abort_unless($company->tracksMembership(), 403);

        $now = $company->currentDateTime();
        $this->from = $this->from ?: $now->startOfYear()->toDateString();
        $this->to = $this->to ?: $now->endOfYear()->toDateString();
    }

    /**
     * @return array<int, array{level: string, count: int, total_cents: int}>
     */
    #[Computed]
    public function rows(): array
    {
        return Invoice::query()
            ->whereNotNull('member_id')
            ->whereIn('status', ['posted', 'partial', 'paid'])
            ->whereBetween('invoice_date', [
                CarbonImmutable::parse($this->from)->toDateString(),
                CarbonImmutable::parse($this->to)->toDateString(),
            ])
            ->with('member.level:id,name')
            ->get(['id', 'member_id', 'total_cents'])
            ->groupBy(fn (Invoice $i) => $i->member?->level?->name ?? __('No level'))
            ->map(fn ($group, string $level) => [
                'level' => $level,
                'count' => $group->count(),
                'total_cents' => (int) $group->sum('total_cents'),
            ])
            ->sortByDesc('total_cents')
            ->values()
            ->all();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" data-test="report-title">{{ __('Dues Revenue by Level') }}</flux:heading>
            <flux:subheading>{{ $company->name }}</flux:subheading>
        </div>
        <div class="flex items-end gap-3">
            <flux:input type="date" wire:model.live="from" :label="__('From')" data-test="revenue-from" />
            <flux:input type="date" wire:model.live="to" :label="__('To')" data-test="revenue-to" />
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Level') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Invoices') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Revenue') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->rows as $row)
                    <tr data-test="revenue-row">
                        <td class="px-4 py-2">{{ $row['level'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $row['count'] }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ Money::fromCents($row['total_cents'], $company->currency_code) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-6 text-center text-muted-foreground">{{ __('No dues revenue in this period.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
