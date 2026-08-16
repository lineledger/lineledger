<?php

use App\Enums\PayRunStatus;
use App\Models\Company;
use App\Models\PayRun;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Pay runs')] class extends Component {
    use WithPagination;
    public Company $company;

    #[Url(as: 'status')]
    public string $statusFilter = '';

    public function mount(Company $company): void
    {
        $this->company = $company;

        abort_unless($company->usesPayroll(), 404);
    }

    #[Computed]
    public function payRuns()
    {
        return PayRun::query()
            ->with('schedule')
            ->withCount('lines')
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->orderByDesc('pay_date')
            ->orderByDesc('id')
            ->paginate(25);
    }

    public function money(int $cents): string
    {
        return Money::fromCents($cents)->format();
    }

    public function badgeColor(PayRunStatus $status): string
    {
        return match ($status) {
            PayRunStatus::Draft => 'zinc',
            PayRunStatus::Calculated => 'amber',
            PayRunStatus::Posted => 'blue',
            PayRunStatus::Paid => 'emerald',
            PayRunStatus::Void => 'red',
        };
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Pay runs') }}</flux:heading>
            <flux:subheading>{{ __('Run payroll, review the calculated deductions and write cheques.') }}</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" :href="route('pay-runs.create')" wire:navigate data-test="new-pay-run-button">
            {{ __('New pay run') }}
        </flux:button>
    </div>

    <div class="mb-4">
        <flux:select wire:model.live="statusFilter" class="sm:max-w-xs">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach (PayRunStatus::cases() as $status)
                <flux:select.option value="{{ $status->value }}">{{ $status->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Run #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Pay date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Period') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Employees') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Gross') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Net') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->payRuns as $run)
                    <tr class="cursor-pointer hover:bg-muted/50" wire:click="$js.navigate('{{ route('pay-runs.show', $run) }}')" data-test="pay-run-row">
                        <td class="px-4 py-2 font-mono font-medium">
                            <a href="{{ route('pay-runs.show', $run) }}" wire:navigate>{{ $run->run_no }}</a>
                        </td>
                        <td class="px-4 py-2">{{ $run->pay_date->toDateString() }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $run->period_start_date->toDateString() }} – {{ $run->period_end_date->toDateString() }}</td>
                        <td class="px-4 py-2 text-right">{{ $run->lines_count }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $this->money($run->gross_cents) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $this->money($run->net_cents) }}</td>
                        <td class="px-4 py-2"><flux:badge size="sm" :color="$this->badgeColor($run->status)">{{ $run->status->label() }}</flux:badge></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-muted-foreground">{{ __('No pay runs yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->payRuns->links() }}</div>
</section>
