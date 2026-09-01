<?php

use App\Actions\Budgeting\BuildBudgetFromActuals;
use App\Actions\Budgeting\SaveBudget;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Location;
use App\Rules\MoneyString;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Budget')] class extends Component {
    public Company $company;

    public ?Budget $budget = null;

    public string $name = '';

    public int $fiscal_year;

    public ?int $class_id = null;

    public ?int $location_id = null;

    public ?string $notes = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $rows = [];

    /** @var 'blank'|'actuals'|'copy' */
    public string $seedMode = 'blank';

    public ?int $copyFromBudgetId = null;

    public function mount(Company $company, ?Budget $budget = null): void
    {
        $this->company = $company;
        $this->budget = $budget;

        if ($budget !== null) {
            // Livewire resolves the {budget} binding without the tenant scope, so
            // guard against cross-tenant access explicitly (see other forms).
            abort_unless($budget->company_id === $company->id, 404);

            $budget->loadMissing('lines');
            $this->name = $budget->name;
            $this->fiscal_year = $budget->fiscal_year;
            $this->class_id = $budget->class_id;
            $this->location_id = $budget->location_id;
            $this->notes = $budget->notes;
            $this->rows = $budget->lines->map(fn ($line): array => $this->hydrateRow(
                $line->account_id,
                collect(range(1, 12))->mapWithKeys(fn (int $m): array => [$m => (int) $line->{"month_{$m}_cents"}])->all(),
            ))->all();
        } else {
            $this->fiscal_year = $this->currentFiscalYear();
            $this->rows = [$this->emptyRow()];
        }
    }

    protected function currentFiscalYear(): int
    {
        $now = CarbonImmutable::parse($this->company->currentDateTime());
        $startMonth = (int) ($this->company->fiscal_year_start_month ?? 1);

        return $now->month >= $startMonth ? $now->year : $now->year - 1;
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function accountOptions(): array
    {
        // Active income/expense accounts, plus any already on a budget row so
        // editing never silently drops a since-deactivated account.
        $usedIds = array_filter(array_column($this->rows, 'account_id'));

        return Account::query()
            ->where('company_id', $this->company->id)
            ->whereIn('type', [AccountType::Income->value, AccountType::Expense->value])
            ->where(function ($q) use ($usedIds) {
                $q->where('is_active', true);

                if ($usedIds !== []) {
                    $q->orWhereIn('id', $usedIds);
                }
            })
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Account $a): array => [$a->id => $a->code.' — '.$a->name])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function classOptions(): array
    {
        return Classification::query()->where('company_id', $this->company->id)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function locationOptions(): array
    {
        return Location::query()->where('company_id', $this->company->id)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function otherBudgets(): array
    {
        return Budget::query()
            ->where('company_id', $this->company->id)
            ->when($this->budget !== null, fn ($q) => $q->whereKeyNot($this->budget->id))
            ->orderByDesc('fiscal_year')
            ->get()
            ->mapWithKeys(fn (Budget $b): array => [$b->id => $b->name.' ('.$b->fiscal_year.')'])
            ->all();
    }

    /**
     * Short month labels for the grid header, anchored to the fiscal year.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function monthLabels(): array
    {
        $startMonth = (int) ($this->company->fiscal_year_start_month ?? 1);
        $first = CarbonImmutable::create($this->fiscal_year, $startMonth, 1);

        return collect(range(1, 12))->mapWithKeys(fn (int $m): array => [$m => $first->addMonths($m - 1)->format('M')])->all();
    }

    public function addRow(): void
    {
        $this->rows[] = $this->emptyRow();
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);

        if ($this->rows === []) {
            $this->rows = [$this->emptyRow()];
        }
    }

    public function applySeed(): void
    {
        if ($this->seedMode === 'actuals') {
            $actuals = app(BuildBudgetFromActuals::class)->handle(
                $this->company,
                $this->fiscal_year,
                $this->class_id,
                $this->location_id,
            );

            $rows = [];

            foreach ($actuals as $accountId => $months) {
                if (array_sum($months) === 0) {
                    continue;
                }

                $rows[] = $this->hydrateRow($accountId, $months);
            }

            $this->rows = $rows !== [] ? $rows : [$this->emptyRow()];
        } elseif ($this->seedMode === 'copy' && $this->copyFromBudgetId !== null) {
            $source = Budget::with('lines')->where('company_id', $this->company->id)->find($this->copyFromBudgetId);

            $this->rows = $source !== null && $source->lines->isNotEmpty()
                ? $source->lines->map(fn ($line): array => $this->hydrateRow(
                    $line->account_id,
                    collect(range(1, 12))->mapWithKeys(fn (int $m): array => [$m => (int) $line->{"month_{$m}_cents"}])->all(),
                ))->all()
                : [$this->emptyRow()];
        } else {
            $this->rows = [$this->emptyRow()];
        }
    }

    public function save(): void
    {
        // Drop rows with no account chosen so trailing blanks don't block save.
        $this->rows = array_values(array_filter(
            $this->rows,
            fn (array $row): bool => ($row['account_id'] ?? null) !== null && $row['account_id'] !== '',
        ));

        if ($this->rows === []) {
            $this->addError('rows', __('Add at least one account.'));

            return;
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'class_id' => ['nullable', 'integer', Rule::exists('classifications', 'id')->where('company_id', $this->company->id)],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('company_id', $this->company->id)],
            'rows.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $this->company->id)],
        ];

        for ($month = 1; $month <= 12; $month++) {
            $rules["rows.*.m{$month}"] = ['nullable', 'string', new MoneyString];
        }

        $this->validate($rules);

        $accountIds = array_column($this->rows, 'account_id');

        if (count($accountIds) !== count(array_unique($accountIds))) {
            $this->addError('rows', __('Each account can only appear once.'));

            return;
        }

        $lines = array_map(function (array $row): array {
            $line = ['account_id' => (int) $row['account_id']];

            for ($month = 1; $month <= 12; $month++) {
                $value = (string) ($row["m{$month}"] ?? '');
                $line["month_{$month}_cents"] = $value === '' ? 0 : Money::fromString($value)->cents;
            }

            return $line;
        }, $this->rows);

        $this->budget = app(SaveBudget::class)->handle([
            'name' => $this->name,
            'fiscal_year' => $this->fiscal_year,
            'class_id' => $this->class_id ?: null,
            'location_id' => $this->location_id ?: null,
            'notes' => $this->notes,
            'lines' => $lines,
        ], $this->budget);

        Flux::toast(variant: 'success', text: __('Budget saved.'));

        $this->redirectRoute('budgets.index', $this->company, navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyRow(): array
    {
        $row = ['account_id' => null];

        for ($month = 1; $month <= 12; $month++) {
            $row["m{$month}"] = '';
        }

        return $row;
    }

    /**
     * @param  array<int, int>  $monthsCents
     * @return array<string, mixed>
     */
    protected function hydrateRow(int $accountId, array $monthsCents): array
    {
        $row = ['account_id' => $accountId];

        for ($month = 1; $month <= 12; $month++) {
            $cents = (int) ($monthsCents[$month] ?? 0);
            $row["m{$month}"] = $cents === 0 ? '' : Money::fromCents($cents)->toDecimalString();
        }

        return $row;
    }

    public function rowTotal(int $index): int
    {
        $total = 0;

        for ($month = 1; $month <= 12; $month++) {
            $value = (string) ($this->rows[$index]["m{$month}"] ?? '');
            $total += $value === '' ? 0 : (Money::tryFromString($value)?->cents ?? 0);
        }

        return $total;
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl" level="1">{{ $budget ? __('Edit budget') : __('New budget') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <flux:input wire:model="name" :label="__('Name')" required />
            <flux:input type="number" wire:model.live="fiscal_year" :label="__('Fiscal year')" required />
            <flux:select wire:model="class_id" :label="__('Class (optional)')">
                <flux:select.option :value="null">{{ __('All classes') }}</flux:select.option>
                @foreach ($this->classOptions as $id => $label)
                    <flux:select.option :value="$id">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="location_id" :label="__('Location (optional)')">
                <flux:select.option :value="null">{{ __('All locations') }}</flux:select.option>
                @foreach ($this->locationOptions as $id => $label)
                    <flux:select.option :value="$id">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @unless ($budget)
            <div class="flex flex-wrap items-end gap-3 rounded-lg border border-border bg-muted p-4">
                <flux:select wire:model.live="seedMode" :label="__('Start from')" class="w-56">
                    <flux:select.option value="blank">{{ __('Blank') }}</flux:select.option>
                    <flux:select.option value="actuals">{{ __('Prior-year actuals') }}</flux:select.option>
                    <flux:select.option value="copy">{{ __('Copy existing budget') }}</flux:select.option>
                </flux:select>
                @if ($seedMode === 'copy')
                    <flux:select wire:model="copyFromBudgetId" :label="__('Budget to copy')" class="w-56">
                        <flux:select.option :value="null">{{ __('— Select —') }}</flux:select.option>
                        @foreach ($this->otherBudgets as $id => $label)
                            <flux:select.option :value="$id">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                <flux:button type="button" variant="filled" icon="sparkles" wire:click="applySeed">{{ __('Apply') }}</flux:button>
            </div>
        @endunless

        @error('rows')<flux:text class="text-sm text-red-600">{{ $message }}</flux:text>@enderror

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-2 py-2 text-left font-medium">{{ __('Account') }}</th>
                        @foreach ($this->monthLabels as $label)
                            <th class="px-2 py-2 text-right font-medium">{{ $label }}</th>
                        @endforeach
                        <th class="px-2 py-2 text-right font-medium">{{ __('Total') }}</th>
                        <th class="w-10"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($rows as $i => $row)
                        <tr wire:key="row-{{ $i }}">
                            <td class="px-2 py-1 min-w-56">
                                <flux:select wire:model="rows.{{ $i }}.account_id">
                                    <flux:select.option :value="null">{{ __('— Account —') }}</flux:select.option>
                                    @foreach ($this->accountOptions as $id => $label)
                                        <flux:select.option :value="$id">{{ $label }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                @error('rows.'.$i.'.account_id')<flux:text class="text-xs text-red-600">{{ __('Required.') }}</flux:text>@enderror
                            </td>
                            @for ($month = 1; $month <= 12; $month++)
                                <td class="px-1 py-1">
                                    <x-amount-input model="rows.{{ $i }}.m{{ $month }}" modifiers=".live.debounce.500ms" class="w-24 text-right" placeholder="0.00" />
                                </td>
                            @endfor
                            <td class="px-2 py-1 text-right font-mono">{{ number_format($this->rowTotal($i) / 100, 2) }}</td>
                            <td class="px-2 py-1 text-right">
                                <flux:button type="button" size="sm" variant="ghost" icon="x-mark" wire:click="removeRow({{ $i }})" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-between">
            <flux:button type="button" variant="ghost" icon="plus" wire:click="addRow">{{ __('Add account') }}</flux:button>
            <div class="flex gap-2">
                <flux:button :href="route('budgets.index', $company)" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save budget') }}</flux:button>
            </div>
        </div>
    </form>
</section>
