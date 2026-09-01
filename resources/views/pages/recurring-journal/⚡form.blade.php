<?php

use App\Actions\Accounting\SaveRecurringJournalEntry;
use App\Enums\RecurrenceEndType;
use App\Enums\RecurrenceFrequency;
use App\Models\Account;
use App\Models\Classification;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\RecurringJournalEntry;
use App\Rules\MoneyString;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Recurring journal entry')] class extends Component {
    public Company $company;

    public ?RecurringJournalEntry $recurring = null;

    public string $name = '';

    public string $memo = '';

    public string $frequency = 'monthly';

    public string $start_date = '';

    public ?int $day_of_month = null;

    public string $end_type = 'never';

    public string $end_date = '';

    public ?int $max_occurrences = null;

    #[Url(as: 'from')]
    public ?int $memorizeFromId = null;

    /**
     * @var array<int, array{account_id: ?int, contact_id: ?int, debit: string, credit: string, memo: ?string, class_id: ?int, location_id: ?int}>
     */
    public array $lines = [];

    public function mount(Company $company, ?RecurringJournalEntry $recurring = null): void
    {
        $this->company = $company;

        if ($recurring && $recurring->exists) {
            $this->recurring = $recurring->load('lines');
            $this->name = $recurring->name ?? '';
            $this->memo = $recurring->memo ?? '';
            $this->frequency = $recurring->frequency->value;
            $this->start_date = $recurring->start_date->toDateString();
            $this->day_of_month = $recurring->day_of_month;
            $this->end_type = $recurring->end_type->value;
            $this->end_date = $recurring->end_date?->toDateString() ?? '';
            $this->max_occurrences = $recurring->max_occurrences;
            $this->lines = $recurring->lines->map(fn ($l) => $this->mapLine($l))->all();

            return;
        }

        $today = $this->company->currentDateTime();
        $this->start_date = $today->toDateString();
        $this->day_of_month = (int) $today->format('j');
        $this->lines = [$this->emptyLine(), $this->emptyLine()];

        if ($this->memorizeFromId) {
            $this->prefillFromJournalEntry($this->memorizeFromId);
        }
    }

    /**
     * Seed the template from an existing journal entry's lines (the "Memorize" flow).
     */
    protected function prefillFromJournalEntry(int $entryId): void
    {
        $entry = JournalEntry::query()
            ->where('company_id', $this->company->id)
            ->with('lines')
            ->find($entryId);

        if (! $entry) {
            return;
        }

        $this->name = $entry->memo ? mb_substr($entry->memo, 0, 255) : '';
        $this->memo = $entry->memo ?? '';
        $this->lines = $entry->lines->map(fn ($l) => $this->mapLine($l))->all();
    }

    /**
     * @return array{account_id: ?int, contact_id: ?int, debit: string, credit: string, memo: ?string, class_id: ?int, location_id: ?int}
     */
    protected function mapLine($line): array
    {
        return [
            'account_id' => $line->account_id,
            'contact_id' => $line->contact_id,
            'debit' => $line->debit_cents > 0 ? Money::fromCents((int) $line->debit_cents)->toDecimalString() : '',
            'credit' => $line->credit_cents > 0 ? Money::fromCents((int) $line->credit_cents)->toDecimalString() : '',
            'memo' => $line->memo,
            'class_id' => $line->class_id,
            'location_id' => $line->location_id,
        ];
    }

    /**
     * @return array{account_id: ?int, contact_id: ?int, debit: string, credit: string, memo: ?string, class_id: ?int, location_id: ?int}
     */
    protected function emptyLine(): array
    {
        return [
            'account_id' => null,
            'contact_id' => null,
            'debit' => '',
            'credit' => '',
            'memo' => null,
            'class_id' => null,
            'location_id' => null,
        ];
    }

    public function addLine(): void
    {
        $this->lines[] = $this->emptyLine();
    }

    public function removeLine(int $i): void
    {
        if (count($this->lines) <= 1) {
            return;
        }
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    public function save(): void
    {
        $companyId = $this->company->id;

        $validated = $this->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string'],
            'frequency' => ['required', Rule::in(array_column(RecurrenceFrequency::cases(), 'value'))],
            'start_date' => ['required', 'date'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'end_type' => ['required', Rule::in(array_column(RecurrenceEndType::cases(), 'value'))],
            'end_date' => ['nullable', 'required_if:end_type,on_date', 'date', 'after_or_equal:start_date'],
            'max_occurrences' => ['nullable', 'required_if:end_type,after_occurrences', 'integer', 'min:1'],
            'lines' => ['array', 'min:1'],
            'lines.*.account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'lines.*.debit' => ['nullable', 'string', new MoneyString],
            'lines.*.credit' => ['nullable', 'string', new MoneyString],
            'lines.*.memo' => ['nullable', 'string'],
        ]);

        $lines = [];
        $debits = 0;
        $credits = 0;

        foreach ($this->lines as $line) {
            $debit = Money::tryFromString((string) ($line['debit'] ?? ''))?->cents ?? 0;
            $credit = Money::tryFromString((string) ($line['credit'] ?? ''))?->cents ?? 0;

            if ($debit === 0 && $credit === 0) {
                continue;
            }

            if (empty($line['account_id'])) {
                throw ValidationException::withMessages(['lines' => __('Every line with an amount needs an account.')]);
            }

            $debits += $debit;
            $credits += $credit;

            $lines[] = [
                'account_id' => (int) $line['account_id'],
                'debit_cents' => $debit,
                'credit_cents' => $credit,
                'memo' => $line['memo'] ?? null,
                'contact_id' => $line['contact_id'] ?? null,
                'class_id' => $line['class_id'] ?? null,
                'location_id' => $line['location_id'] ?? null,
            ];
        }

        if ($lines === [] || $debits !== $credits || $debits === 0) {
            throw ValidationException::withMessages([
                'lines' => __('The template must balance — total debits must equal total credits.'),
            ]);
        }

        $this->recurring = app(SaveRecurringJournalEntry::class)->handle([
            'name' => $validated['name'] ?: null,
            'memo' => $validated['memo'] ?: null,
            'frequency' => $validated['frequency'],
            'start_date' => $validated['start_date'],
            'day_of_month' => $validated['day_of_month'] ?: null,
            'end_type' => $validated['end_type'],
            'end_date' => $validated['end_date'] ?: null,
            'max_occurrences' => $validated['max_occurrences'] ?: null,
            'lines' => $lines,
        ], $this->recurring);

        Flux::toast(variant: 'success', text: __('Recurring journal entry saved.'));
        $this->redirectRoute('recurring-journal.show', ['company' => $this->company->slug, 'recurring' => $this->recurring->id], navigate: true);
    }

    #[Computed]
    public function accountOptions()
    {
        $lineAccountIds = collect($this->lines)->pluck('account_id')->filter()->all();

        return Account::query()
            ->where(function ($q) use ($lineAccountIds) {
                $q->where('is_active', true);
                if ($lineAccountIds !== []) {
                    $q->orWhereIn('id', $lineAccountIds);
                }
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
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
    public function classificationOptions()
    {
        return $this->tracksClasses
            ? Classification::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            : collect();
    }

    #[Computed]
    public function locationOptions()
    {
        return $this->tracksLocations
            ? Location::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            : collect();
    }

    #[Computed]
    public function totalDebitsCents(): int
    {
        return collect($this->lines)->sum(fn ($l) => Money::tryFromString((string) ($l['debit'] ?? ''))?->cents ?? 0);
    }

    #[Computed]
    public function totalCreditsCents(): int
    {
        return collect($this->lines)->sum(fn ($l) => Money::tryFromString((string) ($l['credit'] ?? ''))?->cents ?? 0);
    }

    #[Computed]
    public function differenceCents(): int
    {
        return $this->totalDebitsCents - $this->totalCreditsCents;
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1" class="mb-6">
        {{ $recurring?->id ? __('Edit memorized journal entry') : __('New memorized journal entry') }}
    </flux:heading>

    <form wire:submit="save" class="space-y-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:input wire:model="name" :label="__('Schedule name')" placeholder="{{ __('e.g. Monthly depreciation') }}" data-test="recurring-journal-name-input" />
            <flux:input wire:model="memo" :label="__('Memo')" :description="__('Applied to each generated entry.')" />
        </div>

        <flux:separator :text="__('Schedule')" />

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <flux:select wire:model.live="frequency" :label="__('Frequency')" data-test="recurring-frequency">
                @foreach (\App\Enums\RecurrenceFrequency::cases() as $f)
                    <flux:select.option :value="$f->value">{{ $f->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input type="date" wire:model="start_date" :label="__('Start date')" required data-test="recurring-start-date" />

            @if ($frequency !== 'weekly')
                <flux:input type="number" min="1" max="31" wire:model="day_of_month" :label="__('Day of month')" placeholder="{{ __('1–31') }}" data-test="recurring-day-of-month" />
            @endif
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <flux:select wire:model.live="end_type" :label="__('Ends')" data-test="recurring-end-type">
                @foreach (\App\Enums\RecurrenceEndType::cases() as $e)
                    <flux:select.option :value="$e->value">{{ $e->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($end_type === 'on_date')
                <flux:input type="date" wire:model="end_date" :label="__('End date')" data-test="recurring-end-date" />
            @elseif ($end_type === 'after_occurrences')
                <flux:input type="number" min="1" wire:model="max_occurrences" :label="__('Number of occurrences')" data-test="recurring-max-occurrences" />
            @endif
        </div>

        <flux:separator :text="__('Template lines')" />

        @php($lineGrid = 'lg:grid lg:grid-cols-[minmax(0,1fr)_7rem_7rem_minmax(0,1.1fr)_2.75rem] lg:items-start lg:gap-3')
        <div class="overflow-hidden rounded-lg border border-border text-sm">
            <div class="{{ $lineGrid }} hidden bg-muted px-3 py-2 font-medium text-muted-foreground">
                <div>{{ __('Account') }}</div>
                <div class="text-right">{{ __('Debit') }}</div>
                <div class="text-right">{{ __('Credit') }}</div>
                <div>{{ __('Line memo') }}</div>
                <div></div>
            </div>

            <div class="divide-y divide-border">
                @foreach ($lines as $i => $line)
                    <div wire:key="rj-line-{{ $i }}" data-test="recurring-journal-line-row" class="space-y-3 p-3">
                        <div class="{{ $lineGrid }} grid grid-cols-1 gap-3">
                            <div>
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Account') }}</span>
                                <flux:select wire:model.live="lines.{{ $i }}.account_id" data-test="line-account">
                                    <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                                    @foreach ($this->accountOptions as $opt)
                                        <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                            <div>
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Debit') }}</span>
                                <x-amount-input model="lines.{{ $i }}.debit" class="lg:text-right" placeholder="0.00" data-test="line-debit" />
                            </div>
                            <div>
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Credit') }}</span>
                                <x-amount-input model="lines.{{ $i }}.credit" class="lg:text-right" placeholder="0.00" data-test="line-credit" />
                            </div>
                            <div>
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Line memo') }}</span>
                                <flux:input wire:model="lines.{{ $i }}.memo" />
                            </div>
                            <div class="flex lg:justify-end lg:pt-1">
                                <flux:button variant="ghost" size="sm" icon="x-mark" type="button" wire:click="removeLine({{ $i }})">
                                    <span class="lg:hidden">{{ __('Remove line') }}</span>
                                </flux:button>
                            </div>
                        </div>

                        @if ($this->tracksClasses || $this->tracksLocations)
                            <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                                @if ($this->tracksClasses)
                                    <flux:select wire:model="lines.{{ $i }}.class_id" :label="__('Class')" class="sm:w-56" data-test="line-class">
                                        <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                        @foreach ($this->classificationOptions as $opt)
                                            <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @endif
                                @if ($this->tracksLocations)
                                    <flux:select wire:model="lines.{{ $i }}.location_id" :label="__('Location')" class="sm:w-56" data-test="line-location">
                                        <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                        @foreach ($this->locationOptions as $opt)
                                            <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="{{ $lineGrid }} hidden border-t border-border bg-muted px-3 py-2">
                <div class="text-right font-medium">{{ __('Totals') }}</div>
                <div class="text-right font-mono" data-test="total-debits">{{ number_format($this->totalDebitsCents / 100, 2) }}</div>
                <div class="text-right font-mono" data-test="total-credits">{{ number_format($this->totalCreditsCents / 100, 2) }}</div>
                <div class="text-muted-foreground" data-test="balance-indicator">
                    @if ($this->differenceCents === 0 && $this->totalDebitsCents > 0)
                        <span class="text-green-600">{{ __('Balanced') }}</span>
                    @elseif ($this->totalDebitsCents === 0 && $this->totalCreditsCents === 0)
                        {{ __('Enter amounts') }}
                    @else
                        <span class="text-amber-600">{{ __('Out of balance by') }} {{ number_format(abs($this->differenceCents) / 100, 2) }}</span>
                    @endif
                </div>
                <div></div>
            </div>

            <div class="space-y-1 border-t border-border bg-muted px-3 py-3 lg:hidden">
                <div class="flex justify-between"><span class="font-medium">{{ __('Total debits') }}</span><span class="font-mono" data-test="total-debits-mobile">{{ number_format($this->totalDebitsCents / 100, 2) }}</span></div>
                <div class="flex justify-between"><span class="font-medium">{{ __('Total credits') }}</span><span class="font-mono" data-test="total-credits-mobile">{{ number_format($this->totalCreditsCents / 100, 2) }}</span></div>
            </div>
        </div>

        @error('lines') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror

        <div class="flex items-center justify-between">
            <flux:button variant="filled" type="button" icon="plus" wire:click="addLine">{{ __('Add line') }}</flux:button>

            <div class="flex gap-2">
                <flux:button variant="ghost" :href="route('recurring-journal.index', ['company' => $company->slug])" wire:navigate>{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" type="submit" data-test="save-recurring-journal-button">{{ __('Save schedule') }}</flux:button>
            </div>
        </div>
    </form>
</section>
