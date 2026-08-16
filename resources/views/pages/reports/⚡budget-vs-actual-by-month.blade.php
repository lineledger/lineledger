<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Company;
use App\Services\Reporting\ReportCalculator;
use App\Support\Reporting\IncomeStatementBucket;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Budget vs. Actual by Month')] class extends Component {
    public Company $company;

    #[Url]
    public ?int $budgetId = null;

    /** @var 'actual'|'budget'|'variance' */
    public string $metric = 'variance';

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
            ? Budget::with('lines')->where('company_id', $this->company->id)->find($this->budgetId)
            : null;
    }

    /**
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
     * Rows grouped by income-statement bucket, each carrying the selected metric
     * (actual, budget, or variance) for all twelve fiscal months plus a total.
     *
     * @return array<string, array{label: string, rows: array<int, array<string, mixed>>}>
     */
    #[Computed]
    public function report(): array
    {
        $budget = $this->budget();

        $groups = [
            'income' => ['label' => __('Income'), 'rows' => []],
            'cogs' => ['label' => __('Cost of Goods Sold'), 'rows' => []],
            'expense' => ['label' => __('Expenses'), 'rows' => []],
        ];

        if ($budget === null) {
            return $groups;
        }

        $calc = app(ReportCalculator::class);
        $classId = $budget->class_id;
        $locationId = $budget->location_id;

        $budgetLines = $budget->lines->keyBy('account_id');

        $accounts = Account::query()
            ->where('company_id', $this->company->id)
            ->whereIn('type', [AccountType::Income->value, AccountType::Expense->value])
            ->orderBy('code')
            ->get();

        foreach ($accounts as $account) {
            $bucket = IncomeStatementBucket::for($account);

            if ($bucket === null) {
                continue;
            }

            $line = $budgetLines->get($account->id);
            $cells = [];
            $rowTotal = 0;
            $hasValue = false;

            for ($index = 1; $index <= 12; $index++) {
                $monthStart = $budget->monthStart($index);
                $actual = $calc->periodChange($account, $monthStart, $monthStart->endOfMonth(), $classId, $locationId);
                $budgetCents = $line !== null ? (int) $line->{"month_{$index}_cents"} : 0;

                $value = match ($this->metric) {
                    'actual' => $actual,
                    'budget' => $budgetCents,
                    default => $actual - $budgetCents,
                };

                $cells[$index] = $value;
                $rowTotal += $value;

                if ($actual !== 0 || $budgetCents !== 0) {
                    $hasValue = true;
                }
            }

            if (! $hasValue) {
                continue;
            }

            $groups[$bucket]['rows'][] = [
                'code' => $account->code,
                'name' => $account->name,
                'cells' => $cells,
                'total' => $rowTotal,
            ];
        }

        return $groups;
    }
}; ?>

<div class="space-y-6">
    <x-reports.control-bar
        :title="__('Budget vs. Actual by Month')"
        :subtitle="$company->name"
        mode="none"
        :exports="false"
    >
        <flux:select wire:model.live="budgetId" :label="__('Budget')" class="w-56">
            @foreach ($this->budgets as $id => $label)
                <flux:select.option :value="$id">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="metric" :label="__('Show')" class="w-40">
            <flux:select.option value="variance">{{ __('Variance') }}</flux:select.option>
            <flux:select.option value="actual">{{ __('Actual') }}</flux:select.option>
            <flux:select.option value="budget">{{ __('Budget') }}</flux:select.option>
        </flux:select>
    </x-reports.control-bar>

    @if ($this->budget === null)
        <flux:callout icon="calculator">
            <flux:callout.heading>{{ __('No budget selected') }}</flux:callout.heading>
            <flux:callout.text>
                <flux:link :href="route('budgets.create', $company)" wire:navigate>{{ __('Create a budget') }}</flux:link>
                {{ __('to compare actuals month by month.') }}
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
                    @foreach ($this->report as $key => $group)
                        @continue (count($group['rows']) === 0)
                        <tr class="bg-muted">
                            <td colspan="14" class="px-3 py-2 font-semibold">{{ $group['label'] }}</td>
                        </tr>
                        @foreach ($group['rows'] as $row)
                            <tr wire:key="bvam-{{ $key }}-{{ $row['code'] }}">
                                <td class="px-3 py-2 whitespace-nowrap"><span class="font-mono text-muted-foreground">{{ $row['code'] }}</span> {{ $row['name'] }}</td>
                                @for ($index = 1; $index <= 12; $index++)
                                    <td class="px-3 py-2 text-right font-mono">{{ number_format($row['cells'][$index] / 100, 2) }}</td>
                                @endfor
                                <td class="px-3 py-2 text-right font-mono font-semibold">{{ number_format($row['total'] / 100, 2) }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
