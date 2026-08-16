<?php

use App\Actions\Accounting\SaveJournalEntryTemplate;
use App\Models\Account;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Fund;
use App\Models\JournalEntryTemplate;
use App\Models\Location;
use App\Models\TaxCode;
use App\Rules\MoneyString;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Journal entry template')] class extends Component {
    public Company $company;

    public ?JournalEntryTemplate $journalEntryTemplate = null;

    public string $name = '';

    public bool $is_active = true;

    /**
     * @var array<int, array{account_id: ?int, debit: string, credit: string, memo: ?string, tax_code_id: ?int, class_id: ?int, location_id: ?int, fund_id: ?int}>
     */
    public array $lines = [];

    public function mount(Company $company, ?JournalEntryTemplate $journalEntryTemplate = null): void
    {
        $this->company = $company;

        if ($journalEntryTemplate && $journalEntryTemplate->exists) {
            $this->journalEntryTemplate = $journalEntryTemplate->load('lines');
            $this->name = $journalEntryTemplate->name;
            $this->is_active = $journalEntryTemplate->is_active;

            $this->lines = $journalEntryTemplate->lines->map(fn ($l) => [
                'account_id' => $l->account_id,
                'debit' => $l->debit_cents > 0 ? Money::fromCents($l->debit_cents)->toDecimalString() : '',
                'credit' => $l->credit_cents > 0 ? Money::fromCents($l->credit_cents)->toDecimalString() : '',
                'memo' => $l->memo,
                'tax_code_id' => $l->tax_code_id,
                'class_id' => $l->class_id,
                'location_id' => $l->location_id,
                'fund_id' => $l->fund_id,
            ])->all();
        } else {
            $this->lines = [$this->emptyLine(), $this->emptyLine()];
        }
    }

    /**
     * @return array{account_id: ?int, debit: string, credit: string, memo: ?string, tax_code_id: ?int, class_id: ?int, location_id: ?int, fund_id: ?int}
     */
    protected function emptyLine(): array
    {
        return [
            'account_id' => null,
            'debit' => '',
            'credit' => '',
            'memo' => null,
            'tax_code_id' => null,
            'class_id' => null,
            'location_id' => null,
            'fund_id' => null,
        ];
    }

    public function addLine(): void
    {
        $this->lines[] = $this->emptyLine();
    }

    public function removeLine(int $index): void
    {
        if (count($this->lines) <= 2) {
            return;
        }

        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    /**
     * Fill a blank tax code from the account's default when an account is picked.
     */
    public function updatedLines(mixed $value, ?string $key = null): void
    {
        if ($key === null || ! str_ends_with($key, '.account_id')) {
            return;
        }

        $index = (int) explode('.', $key)[0];

        if ($value && empty($this->lines[$index]['tax_code_id'])) {
            $this->lines[$index]['tax_code_id'] = Account::find($value)?->default_tax_code_id;
        }
    }

    public function save(): void
    {
        $companyId = $this->company->id;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'lines' => ['array', 'min:1'],
            'lines.*.account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'lines.*.debit' => ['nullable', 'string', new MoneyString],
            'lines.*.credit' => ['nullable', 'string', new MoneyString],
            'lines.*.memo' => ['nullable', 'string'],
            'lines.*.tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('company_id', $companyId)],
            'lines.*.class_id' => ['nullable', 'integer', Rule::exists('classifications', 'id')->where('company_id', $companyId)],
            'lines.*.location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'lines.*.fund_id' => ['nullable', 'integer', Rule::exists('funds', 'id')->where('company_id', $companyId)],
        ]);

        $editing = $this->journalEntryTemplate;
        abort_if($editing !== null && $editing->company_id !== $this->company->id, 403);

        $this->journalEntryTemplate = app(SaveJournalEntryTemplate::class)->handle([
            'name' => $validated['name'],
            'is_active' => $validated['is_active'],
            'lines' => array_map(fn (array $line): array => [
                'account_id' => $line['account_id'] ?? null,
                'debit_cents' => ($line['debit'] ?? '') !== '' ? Money::fromString($line['debit'])->cents : 0,
                'credit_cents' => ($line['credit'] ?? '') !== '' ? Money::fromString($line['credit'])->cents : 0,
                'memo' => $line['memo'] ?? null,
                'tax_code_id' => $line['tax_code_id'] ?? null,
                'class_id' => $line['class_id'] ?? null,
                'location_id' => $line['location_id'] ?? null,
                'fund_id' => $line['fund_id'] ?? null,
            ], $validated['lines']),
        ], $editing);

        Flux::toast(variant: 'success', text: __('Template saved.'));
        $this->redirectRoute('journal-entry-templates.index', ['company' => $this->company->slug], navigate: true);
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    #[Computed]
    public function accountOptions(): array
    {
        return Account::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->code} — {$a->name}"])
            ->all();
    }

    #[Computed]
    public function taxCodeOptions()
    {
        return TaxCode::query()->where('is_active', true)->orderBy('code')->get();
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
    public function fundOptions()
    {
        return Fund::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
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
    public function tracksFunds(): bool
    {
        return $this->company->tracksFunds();
    }

    #[Computed]
    public function totalDebitsCents(): int
    {
        return array_sum(array_map(fn ($l) => Money::tryFromString((string) $l['debit'])?->cents ?? 0, $this->lines));
    }

    #[Computed]
    public function totalCreditsCents(): int
    {
        return array_sum(array_map(fn ($l) => Money::tryFromString((string) $l['credit'])?->cents ?? 0, $this->lines));
    }

    #[Computed]
    public function differenceCents(): int
    {
        return $this->totalDebitsCents - $this->totalCreditsCents;
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1" class="mb-6">
        {{ $journalEntryTemplate?->id ? __('Edit journal entry template') : __('New journal entry template') }}
    </flux:heading>

    <form wire:submit="save" class="space-y-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:input wire:model="name" :label="__('Template name')" placeholder="{{ __('e.g. Monthly depreciation') }}" required data-test="journal-entry-template-name" />
            <div class="flex items-end">
                <flux:switch wire:model="is_active" :label="__('Active')" />
            </div>
        </div>

        <flux:separator :text="__('Lines')" />

        @php($lineGrid = 'lg:grid lg:grid-cols-[minmax(0,1fr)_8rem_7rem_7rem_minmax(0,1.1fr)_2.75rem] lg:items-start lg:gap-3')
        <div class="overflow-hidden rounded-lg border border-border text-sm">
            <div class="{{ $lineGrid }} hidden bg-muted px-3 py-2 font-medium text-muted-foreground">
                <div>{{ __('Account') }}</div>
                <div>{{ __('Tax code') }}</div>
                <div class="text-right">{{ __('Debit') }}</div>
                <div class="text-right">{{ __('Credit') }}</div>
                <div>{{ __('Line memo') }}</div>
                <div></div>
            </div>

            <div class="divide-y divide-border">
                @foreach ($lines as $i => $line)
                    <div wire:key="line-{{ $i }}" data-test="journal-entry-template-line-row" class="space-y-3 p-3">
                        <div class="{{ $lineGrid }} grid grid-cols-1 gap-3">
                            <div>
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Account') }}</span>
                                <flux:select wire:model.live="lines.{{ $i }}.account_id" data-test="line-account">
                                    <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                                    @foreach ($this->accountOptions as $opt)
                                        <flux:select.option :value="$opt['value']">{{ $opt['label'] }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                            <div>
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Tax code') }}</span>
                                <flux:select wire:model="lines.{{ $i }}.tax_code_id" data-test="line-tax">
                                    <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                    @foreach ($this->taxCodeOptions as $opt)
                                        <flux:select.option :value="$opt->id">{{ $opt->code }}</flux:select.option>
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

                        @if ($this->tracksClasses || $this->tracksLocations || $this->tracksFunds)
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
                                @if ($this->tracksFunds)
                                    <flux:select wire:model="lines.{{ $i }}.fund_id" :label="__('Fund')" class="sm:w-56" data-test="line-fund">
                                        <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                        @foreach ($this->fundOptions as $opt)
                                            <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Totals (desktop) — a template need not balance; this is a reference only. --}}
            <div class="{{ $lineGrid }} hidden border-t border-border bg-muted px-3 py-2">
                <div></div>
                <div class="text-right font-medium">{{ __('Totals') }}</div>
                <div class="text-right font-mono" data-test="total-debits">{{ number_format($this->totalDebitsCents / 100, 2) }}</div>
                <div class="text-right font-mono" data-test="total-credits">{{ number_format($this->totalCreditsCents / 100, 2) }}</div>
                <div class="text-muted-foreground" data-test="balance-indicator">
                    @if ($this->differenceCents === 0 && $this->totalDebitsCents > 0)
                        <span class="text-green-600">{{ __('Balanced') }}</span>
                    @elseif ($this->differenceCents !== 0)
                        <span class="text-amber-600">{{ __('Off by') }} {{ number_format(abs($this->differenceCents) / 100, 2) }}</span>
                    @endif
                </div>
                <div></div>
            </div>

            {{-- Totals (mobile) --}}
            <div class="space-y-1 border-t border-border bg-muted px-3 py-3 lg:hidden">
                <div class="flex justify-between"><span class="font-medium">{{ __('Total debits') }}</span><span class="font-mono">{{ number_format($this->totalDebitsCents / 100, 2) }}</span></div>
                <div class="flex justify-between"><span class="font-medium">{{ __('Total credits') }}</span><span class="font-mono">{{ number_format($this->totalCreditsCents / 100, 2) }}</span></div>
            </div>
        </div>

        @error('lines') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror

        <div class="flex items-center justify-between">
            <flux:button variant="filled" type="button" icon="plus" wire:click="addLine">{{ __('Add line') }}</flux:button>

            <div class="flex gap-2">
                <flux:button variant="ghost" :href="route('journal-entry-templates.index', ['company' => $company->slug])" wire:navigate>{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" type="submit" data-test="save-journal-entry-template-button">{{ __('Save template') }}</flux:button>
            </div>
        </div>
    </form>
</section>
