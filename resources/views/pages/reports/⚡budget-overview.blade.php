<?php

use App\Models\Budget;
use App\Models\Company;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Budget Overview')] class extends Component {
    public Company $company;

    #[Url]
    public ?int $budgetId = null;

    public function mount(Company $company): void
    {
        $this->company = $company;
        $this->budgetId ??= $this->budgets()->keys()->first();
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    #[Computed]
    public function budgets(): \Illuminate\Support\Collection
    {
        return Budget::query()
            ->where('company_id', $this->company->id)
            ->orderByDesc('fiscal_year')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Budget $b): array => [$b->id => $b->name.' ('.$b->fiscal_year.')']);
    }

    #[Computed]
    public function budget(): ?Budget
    {
        return $this->budgetId !== null
            ? Budget::with(['lines.account'])->where('company_id', $this->company->id)->find($this->budgetId)
            : null;
    }

    /**
     * Short labels (e.g. "Jul") for the twelve fiscal months of the budget.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function monthLabels(): array
    {
        $budget = $this->budget();

        if ($budget === null) {
            return [];
        }

        $labels = [];

        for ($index = 1; $index <= 12; $index++) {
            $labels[$index] = $budget->monthStart($index)->format('M');
        }

        return $labels;
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, column_totals: array<int, int>, grand_total: int}
     */
    #[Computed]
    public function table(): array
    {
        $budget = $this->budget();

        if ($budget === null) {
            return ['rows' => [], 'column_totals' => array_fill(1, 12, 0), 'grand_total' => 0];
        }

        $rows = [];
        $columnTotals = array_fill(1, 12, 0);
        $grandTotal = 0;

        foreach ($budget->lines as $line) {
            $months = [];
            $rowTotal = 0;

            for ($index = 1; $index <= 12; $index++) {
                $cents = (int) $line->{"month_{$index}_cents"};
                $months[$index] = $cents;
                $columnTotals[$index] += $cents;
                $rowTotal += $cents;
            }

            $grandTotal += $rowTotal;

            $rows[] = [
                'code' => $line->account?->code ?? '',
                'name' => $line->account?->name ?? '',
                'months' => $months,
                'total' => $rowTotal,
            ];
        }

        return ['rows' => $rows, 'column_totals' => $columnTotals, 'grand_total' => $grandTotal];
    }
}; ?>

<div class="space-y-6">
    <x-reports.control-bar
        :title="__('Budget Overview')"
        :subtitle="$company->name"
        mode="none"
        :exports="false"
    >
        <flux:select wire:model.live="budgetId" :label="__('Budget')" class="w-56">
            @foreach ($this->budgets as $id => $label)
                <flux:select.option :value="$id">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
    </x-reports.control-bar>

    @if ($this->budget === null)
        <flux:callout icon="calculator">
            <flux:callout.heading>{{ __('No budget selected') }}</flux:callout.heading>
            <flux:callout.text>
                <flux:link :href="route('budgets.create', $company)" wire:navigate>{{ __('Create a budget') }}</flux:link>
                {{ __('to see it here.') }}
            </flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium">{{ __('Account') }}</th>
                        @foreach ($this->monthLabels as $label)
                            <th class="px-3 py-2 text-right font-medium">{{ $label }}</th>
                        @endforeach
                        <th class="px-3 py-2 text-right font-medium">{{ __('Total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->table['rows'] as $row)
                        <tr wire:key="bo-{{ $row['code'] }}">
                            <td class="px-3 py-2 whitespace-nowrap"><span class="font-mono text-muted-foreground">{{ $row['code'] }}</span> {{ $row['name'] }}</td>
                            @for ($index = 1; $index <= 12; $index++)
                                <td class="px-3 py-2 text-right font-mono">{{ number_format($row['months'][$index] / 100, 2) }}</td>
                            @endfor
                            <td class="px-3 py-2 text-right font-mono font-semibold">{{ number_format($row['total'] / 100, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="14" class="px-3 py-8 text-center text-muted-foreground">{{ __('This budget has no lines.') }}</td></tr>
                    @endforelse
                </tbody>
                @if (count($this->table['rows']) > 0)
                    <tfoot class="border-t-2 border-border font-bold">
                        <tr>
                            <td class="px-3 py-2">{{ __('Total') }}</td>
                            @for ($index = 1; $index <= 12; $index++)
                                <td class="px-3 py-2 text-right font-mono">{{ number_format($this->table['column_totals'][$index] / 100, 2) }}</td>
                            @endfor
                            <td class="px-3 py-2 text-right font-mono">{{ number_format($this->table['grand_total'] / 100, 2) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    @endif
</div>
