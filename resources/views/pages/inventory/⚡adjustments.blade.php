<?php

use App\Actions\Inventory\SaveStockAdjustment;
use App\Enums\StockAdjustmentReason;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockAdjustment;
use App\Rules\MoneyString;
use App\Services\Posting\StockAdjustmentPoster;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Stock adjustments')] class extends Component
{
    use WithPagination;
    public Company $company;

    #[Url(as: 'q')]
    public string $search = '';

    public string $f_reason = 'recount';

    public string $f_date = '';

    public string $f_notes = '';

    /** @var array<int, array{item_id: ?int, qty_change: string, unit_cost: string, class_id: ?int, location_id: ?int}> */
    public array $f_lines = [];

    public function mount(Company $company): void
    {
        $this->company = $company;
        $this->f_date = $this->company->currentDateTime()->toDateString();
        $this->addLine();
    }

    public function addLine(): void
    {
        $this->f_lines[] = ['item_id' => null, 'qty_change' => '', 'unit_cost' => '', 'class_id' => null, 'location_id' => null];
    }

    public function removeLine(int $index): void
    {
        unset($this->f_lines[$index]);
        $this->f_lines = array_values($this->f_lines);
    }

    public function openCreate(): void
    {
        $this->reset(['f_notes', 'f_lines']);
        $this->f_reason = 'recount';
        $this->f_date = $this->company->currentDateTime()->toDateString();
        $this->addLine();
        Flux::modal('adjustment-form')->show();
    }

    public function save(): void
    {
        $companyId = $this->company->id;

        $validated = $this->validate([
            'f_reason' => ['required', Rule::enum(StockAdjustmentReason::class)],
            'f_date' => ['required', 'date'],
            'f_notes' => ['nullable', 'string'],
            'f_lines' => ['array', 'min:1'],
            'f_lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')
                ->where('company_id', $companyId)
                ->where('track_inventory', true)],
            'f_lines.*.qty_change' => ['required', 'numeric', 'not_in:0'],
            'f_lines.*.unit_cost' => ['nullable', 'string', new MoneyString],
            'f_lines.*.class_id' => ['nullable', 'integer', Rule::exists('classifications', 'id')->where('company_id', $companyId)],
            'f_lines.*.location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('company_id', $companyId)],
        ]);

        $lines = array_map(function (array $line): array {
            $unitCostCents = $line['unit_cost'] !== '' && $line['unit_cost'] !== null
                ? Money::fromString($line['unit_cost'])->cents
                : 0;

            return [
                'item_id' => $line['item_id'],
                'qty_change' => $line['qty_change'],
                'unit_cost_cents' => $unitCostCents,
                'class_id' => $line['class_id'] ?? null,
                'location_id' => $line['location_id'] ?? null,
            ];
        }, $validated['f_lines']);

        $adj = app(SaveStockAdjustment::class)->handle([
            'adjustment_date' => $validated['f_date'],
            'reason' => $validated['f_reason'],
            'notes' => $validated['f_notes'] ?: null,
            'lines' => $lines,
        ]);

        app(StockAdjustmentPoster::class)->post($adj);

        Flux::modal('adjustment-form')->close();
        Flux::toast(variant: 'success', text: __('Stock adjustment posted.'));
    }

    public function void(int $id): void
    {
        $adj = StockAdjustment::findOrFail($id);
        abort_unless($adj->company_id === $this->company->id, 403);
        app(StockAdjustmentPoster::class)->void($adj);
        Flux::toast(variant: 'success', text: __('Adjustment voided.'));
    }

    #[Computed]
    public function adjustments()
    {
        return StockAdjustment::query()
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where('adjustment_no', 'like', $term)
                    ->orWhere('notes', 'like', $term);
            })
            ->orderByDesc('adjustment_date')
            ->orderByDesc('id')
            ->paginate(25);
    }

    #[Computed]
    public function trackedItems()
    {
        return Item::query()
            ->where('track_inventory', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'qty_on_hand_cached', 'unit_cost_cents_cached']);
    }

    #[Computed]
    public function classificationOptions()
    {
        return Classification::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function locationOptions()
    {
        return Location::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function tracksClasses(): bool
    {
        return (bool) $this->company->features_classes;
    }

    #[Computed]
    public function tracksLocations(): bool
    {
        return (bool) $this->company->features_locations;
    }

    #[Computed]
    public function dimensionColumns(): int
    {
        return (int) $this->tracksClasses + (int) $this->tracksLocations;
    }

    /**
     * Tailwind col-span class for the item select, narrowing as dimension columns appear.
     */
    #[Computed]
    public function itemColSpanClass(): string
    {
        return match ($this->dimensionColumns) {
            2 => 'col-span-3',
            1 => 'col-span-4',
            default => 'col-span-6',
        };
    }

    /**
     * Tailwind col-span class for the unit cost input, narrowing only when both dimensions show.
     */
    #[Computed]
    public function unitCostColSpanClass(): string
    {
        return $this->dimensionColumns === 2 ? 'col-span-2' : 'col-span-3';
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Stock adjustments') }}</flux:heading>
            <flux:text class="text-muted-foreground">{{ __('Recount, shrinkage, damage, opening balance, and write-off entries.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-adjustment-button">
            {{ __('New adjustment') }}
        </flux:button>
    </div>

    <div class="mb-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search') }}" class="max-w-sm" />
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left font-medium">{{ __('No.') }}</th>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Reason') }}</th>
                    <th class="px-4 py-2 text-right font-medium">{{ __('Lines') }}</th>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Status') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->adjustments as $adj)
                    <tr>
                        <td class="px-4 py-2 font-mono">{{ $adj->adjustment_no }}</td>
                        <td class="px-4 py-2">{{ $adj->adjustment_date->format('Y-m-d') }}</td>
                        <td class="px-4 py-2">{{ $adj->reason->label() }}</td>
                        <td class="px-4 py-2 text-right">{{ $adj->lines()->count() }}</td>
                        <td class="px-4 py-2">
                            @if ($adj->voided_at)
                                <flux:badge color="zinc" size="sm">{{ __('Voided') }}</flux:badge>
                            @elseif ($adj->posted_at)
                                <flux:badge color="emerald" size="sm">{{ __('Posted') }}</flux:badge>
                            @else
                                <flux:badge color="amber" size="sm">{{ __('Draft') }}</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            @if ($adj->posted_at && ! $adj->voided_at)
                                <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="void({{ $adj->id }})"
                                    wire:confirm="{{ __('Void this adjustment?') }}" />
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ __('No adjustments yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->adjustments->links() }}</div>

    <flux:modal name="adjustment-form" class="max-w-3xl">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ __('New stock adjustment') }}</flux:heading>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <flux:select wire:model="f_reason" :label="__('Reason')" required>
                    @foreach (StockAdjustmentReason::cases() as $r)
                        <flux:select.option :value="$r->value">{{ $r->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input type="date" wire:model="f_date" :label="__('Date')" required />
                <flux:input wire:model="f_notes" :label="__('Notes')" />
            </div>

            <div>
                <flux:heading size="sm" class="mb-2">{{ __('Lines') }}</flux:heading>
                <div class="space-y-2">
                    @foreach ($f_lines as $i => $line)
                        <div class="grid grid-cols-12 gap-2">
                            <div class="{{ $this->itemColSpanClass }}">
                                <flux:select wire:model="f_lines.{{ $i }}.item_id" required>
                                    <flux:select.option value="">{{ __('— Item —') }}</flux:select.option>
                                    @foreach ($this->trackedItems as $it)
                                        <flux:select.option :value="$it->id">
                                            {{ $it->name }} {{ $it->sku ? '('.$it->sku.')' : '' }}
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                            <div class="col-span-2">
                                <flux:input wire:model="f_lines.{{ $i }}.qty_change" placeholder="{{ __('Qty (±)') }}" required />
                            </div>
                            <div class="{{ $this->unitCostColSpanClass }}">
                                <flux:input wire:model="f_lines.{{ $i }}.unit_cost" placeholder="{{ __('Unit cost (for +)') }}" />
                            </div>
                            @if ($this->tracksClasses)
                                <div class="col-span-2">
                                    <flux:select wire:model="f_lines.{{ $i }}.class_id" data-test="line-class">
                                        <flux:select.option value="">{{ __('— Class —') }}</flux:select.option>
                                        @foreach ($this->classificationOptions as $opt)
                                            <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </div>
                            @endif
                            @if ($this->tracksLocations)
                                <div class="col-span-2">
                                    <flux:select wire:model="f_lines.{{ $i }}.location_id" data-test="line-location">
                                        <flux:select.option value="">{{ __('— Location —') }}</flux:select.option>
                                        @foreach ($this->locationOptions as $opt)
                                            <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </div>
                            @endif
                            <div class="col-span-1 flex items-end">
                                @if (count($f_lines) > 1)
                                    <flux:button variant="ghost" size="sm" icon="trash" wire:click="removeLine({{ $i }})" type="button" />
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                <flux:button variant="ghost" size="sm" icon="plus" wire:click="addLine" type="button" class="mt-2">
                    {{ __('Add line') }}
                </flux:button>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="adjustment-save-button">{{ __('Post adjustment') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
