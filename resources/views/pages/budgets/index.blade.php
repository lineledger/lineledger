<?php

use App\Models\Budget;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Budgets')] class extends Component {
    public Company $company;

    #[Computed]
    public function budgets()
    {
        return Budget::query()
            ->where('company_id', $this->company->id)
            ->withCount('lines')
            ->with(['classification', 'location'])
            ->orderByDesc('fiscal_year')
            ->orderBy('name')
            ->get();
    }

    public function duplicate(int $id): void
    {
        $budget = Budget::where('company_id', $this->company->id)->findOrFail($id);

        $copy = DB::transaction(function () use ($budget): Budget {
            $clone = Budget::create([
                'name' => $budget->name.' '.__('(copy)'),
                'fiscal_year' => $budget->fiscal_year,
                'class_id' => $budget->class_id,
                'location_id' => $budget->location_id,
                'notes' => $budget->notes,
            ]);

            // Fresh query (not the parent's relation) so no stale in-memory lines
            // leak into the copy.
            foreach ($budget->lines()->get() as $line) {
                $clone->lines()->create($line->only([
                    'account_id', 'line_order',
                    'month_1_cents', 'month_2_cents', 'month_3_cents', 'month_4_cents',
                    'month_5_cents', 'month_6_cents', 'month_7_cents', 'month_8_cents',
                    'month_9_cents', 'month_10_cents', 'month_11_cents', 'month_12_cents',
                ]));
            }

            return $clone;
        });

        $this->redirectRoute('budgets.edit', [$this->company, $copy], navigate: true);
    }

    public function delete(int $id): void
    {
        Budget::where('company_id', $this->company->id)->findOrFail($id)->delete();

        unset($this->budgets);

        Flux::toast(variant: 'success', text: __('Budget deleted.'));
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Budgets') }}</flux:heading>
            <flux:subheading>{{ __('Set monthly account targets and track them against actuals.') }}</flux:subheading>
        </div>
        <flux:button :href="route('budgets.create', $company)" wire:navigate variant="primary" icon="plus">
            {{ __('New budget') }}
        </flux:button>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-3 text-left font-medium">{{ __('Name') }}</th>
                    <th class="px-4 py-3 text-left font-medium">{{ __('Fiscal year') }}</th>
                    <th class="px-4 py-3 text-left font-medium">{{ __('Scope') }}</th>
                    <th class="px-4 py-3 text-right font-medium">{{ __('Accounts') }}</th>
                    <th class="px-4 py-3 text-right font-medium">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->budgets as $budget)
                    <tr wire:key="budget-{{ $budget->id }}">
                        <td class="px-4 py-3">
                            <flux:link :href="route('budgets.edit', [$company, $budget])" wire:navigate>{{ $budget->name }}</flux:link>
                        </td>
                        <td class="px-4 py-3">{{ $budget->fiscal_year }}</td>
                        <td class="px-4 py-3">
                            @if ($budget->classification || $budget->location)
                                <flux:badge size="sm" color="zinc">{{ $budget->classification?->name ?? $budget->location?->name }}</flux:badge>
                            @else
                                <span class="text-muted-foreground">{{ __('All') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">{{ $budget->lines_count }}</td>
                        <td class="px-4 py-3 text-right">
                            <flux:dropdown>
                                <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />
                                <flux:menu>
                                    <flux:menu.item icon="chart-bar" :href="route('reports.budget-vs-actual', $company).'?budgetId='.$budget->id" wire:navigate>{{ __('Budget vs. Actual') }}</flux:menu.item>
                                    <flux:menu.item icon="pencil" :href="route('budgets.edit', [$company, $budget])" wire:navigate>{{ __('Edit') }}</flux:menu.item>
                                    <flux:menu.item icon="document-duplicate" wire:click="duplicate({{ $budget->id }})">{{ __('Duplicate') }}</flux:menu.item>
                                    <flux:menu.separator />
                                    <flux:menu.item icon="trash" variant="danger" wire:click="delete({{ $budget->id }})" wire:confirm="{{ __('Delete this budget?') }}">{{ __('Delete') }}</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ __('No budgets yet.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
