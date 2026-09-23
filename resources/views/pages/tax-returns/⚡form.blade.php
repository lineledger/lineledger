<?php

use App\Enums\TaxReturnAdjustmentKind;
use App\Enums\TaxReturnStatus;
use App\Livewire\Concerns\GuardsEditLockedForm;
use App\Models\Account;
use App\Models\Company;
use App\Models\TaxAgency;
use App\Models\TaxReturn;
use App\Rules\MoneyString;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Tax\TaxReturnCalculator;
use App\Services\Tax\TaxReturnFigures;
use App\Services\Tax\TaxReturnFiler;
use App\Support\Accounting\ControlAccountRoles;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Tax return')] class extends Component {
    use GuardsEditLockedForm;

    public Company $company;

    public ?TaxReturn $taxReturn = null;

    public ?int $tax_agency_id = null;

    public string $tax_return_no = '';

    public string $period_start = '';

    public string $period_end = '';

    public string $filing_reference = '';

    public string $notes = '';

    /**
     * Journal line IDs the user has unchecked — omitted from totals and the
     * filed snapshot. Useful when imported opening balances surface lines that
     * don't belong to the current filing period.
     *
     * @var int[]
     */
    public array $excludedLineIds = [];

    /**
     * Manual adjustments to the return, as typed: amounts are decimal strings.
     *
     * @var array<int, array{kind: string, account_id: ?int, amount: string, memo: string}>
     */
    public array $adjustments = [];

    /**
     * The filer has reviewed a non-zero difference from the payable account and
     * accepts it. Cleared by any change that could move the figures.
     */
    public bool $acceptDifference = false;

    /** The difference that was on screen when it was accepted. */
    public ?int $acceptedDifferenceCents = null;

    protected function editLockRecord(): ?Model
    {
        return $this->taxReturn;
    }

    public function mount(Company $company, ?TaxReturn $tax_return = null): void
    {
        $this->company = $company;

        if ($tax_return && $tax_return->exists) {
            abort_if($tax_return->status !== TaxReturnStatus::Draft, 403, 'Only draft returns can be edited.');

            $this->taxReturn = $tax_return;
            $this->tax_agency_id = $tax_return->tax_agency_id;
            $this->tax_return_no = $tax_return->tax_return_no;
            $this->period_start = $tax_return->period_start->toDateString();
            $this->period_end = $tax_return->period_end->toDateString();
            $this->filing_reference = $tax_return->filing_reference ?? '';
            $this->notes = $tax_return->notes ?? '';
            $this->excludedLineIds = array_map('intval', $tax_return->excluded_journal_line_ids ?? []);
            $this->adjustments = $tax_return->adjustments->map(fn ($adjustment) => [
                'kind' => $adjustment->kind->value,
                'account_id' => (int) $adjustment->account_id,
                'amount' => Money::fromCents((int) $adjustment->amount_cents)->toDecimalString(),
                'memo' => $adjustment->memo ?? '',
            ])->all();
        } else {
            $defaultStart = $this->company->currentDateTime()->startOfQuarter()->subQuarter();
            $this->period_start = $defaultStart->toDateString();
            $this->period_end = $defaultStart->copy()->endOfQuarter()->toDateString();
            $this->tax_return_no = app(DocumentNumberGenerator::class)
                ->next($company, TaxReturn::class, 'tax_return_no', 'TR');
        }
    }

    /**
     * Anything that can move the figures withdraws an accepted difference, so
     * the filer always accepts the number actually on screen.
     */
    public function updated(string $property): void
    {
        if ($property !== 'acceptDifference') {
            $this->resetAcceptance();
        }
    }

    public function updatedAcceptDifference(bool $value): void
    {
        $this->acceptedDifferenceCents = $value ? $this->preview?->differenceCents() : null;
    }

    protected function resetAcceptance(): void
    {
        $this->acceptDifference = false;
        $this->acceptedDifferenceCents = null;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, TaxAgency>
     */
    #[Computed]
    public function agencies()
    {
        return TaxAgency::query()->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function agency(): ?TaxAgency
    {
        return $this->tax_agency_id ? TaxAgency::query()->with('payableAccount')->find($this->tax_agency_id) : null;
    }

    /**
     * Accounts an adjustment can be coded to: the agency's payable account first
     * (no ledger entry), then every other active account bar the AR/AP control
     * accounts, plus any already on a row.
     *
     * @return list<array{id: int, label: string}>
     */
    #[Computed]
    public function adjustmentAccountOptions(): array
    {
        $payable = $this->agency?->payableAccount;
        $controlAccountIds = array_keys(ControlAccountRoles::map());
        $onRows = collect($this->adjustments)->pluck('account_id')->filter()->map('intval')->all();

        $accounts = Account::query()
            ->where(function ($q) use ($onRows) {
                $q->where('is_active', true);

                if ($onRows !== []) {
                    $q->orWhereIn('id', $onRows);
                }
            })
            ->whereNotIn('id', $controlAccountIds)
            ->when($payable, fn ($q) => $q->whereKeyNot($payable->id))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $options = $accounts->map(fn (Account $account) => [
            'id' => (int) $account->id,
            'label' => "{$account->code} — {$account->name}",
        ])->all();

        if ($payable) {
            array_unshift($options, [
                'id' => (int) $payable->id,
                'label' => "{$payable->code} — {$payable->name} ".__('(no ledger entry)'),
            ]);
        }

        return $options;
    }

    /**
     * The adjustment rows the preview can use: a known kind, an account and a
     * non-zero amount that parses. Half-typed rows are simply left out.
     *
     * @return list<array{kind: string, account_id: int, amount_cents: int, memo: ?string}>
     */
    protected function usableAdjustments(): array
    {
        $rows = [];

        foreach ($this->adjustments as $row) {
            $cents = Money::tryFromString((string) ($row['amount'] ?? ''))?->cents ?? 0;

            if (! TaxReturnAdjustmentKind::tryFrom((string) ($row['kind'] ?? '')) || empty($row['account_id']) || $cents === 0) {
                continue;
            }

            $rows[] = [
                'kind' => (string) $row['kind'],
                'account_id' => (int) $row['account_id'],
                'amount_cents' => $cents,
                'memo' => ($row['memo'] ?? '') === '' ? null : (string) $row['memo'],
            ];
        }

        return $rows;
    }

    #[Computed]
    public function preview(): ?TaxReturnFigures
    {
        if (! $this->agency || ! $this->period_start || ! $this->period_end) {
            return null;
        }

        try {
            $start = CarbonImmutable::parse($this->period_start);
            $end = CarbonImmutable::parse($this->period_end);
        } catch (\Throwable) {
            return null;
        }

        return app(TaxReturnCalculator::class)->calculate(
            $this->agency,
            $start,
            $end,
            $this->excludedLineIds,
            $this->usableAdjustments(),
        );
    }

    /**
     * Filing needs the return to agree with the ledger, or the difference on
     * screen to have been accepted.
     */
    #[Computed]
    public function canFile(): bool
    {
        $difference = $this->preview?->differenceCents() ?? 0;

        return $difference === 0 || ($this->acceptDifference && $this->acceptedDifferenceCents === $difference);
    }

    public function toggleLine(int $journalLineId): void
    {
        if (in_array($journalLineId, $this->excludedLineIds, true)) {
            $this->excludedLineIds = array_values(array_diff($this->excludedLineIds, [$journalLineId]));
        } else {
            $this->excludedLineIds[] = $journalLineId;
        }

        $this->resetAcceptance();
    }

    public function addAdjustment(): void
    {
        $this->adjustments[] = [
            'kind' => TaxReturnAdjustmentKind::Other->value,
            'account_id' => $this->agency?->payable_account_id ? (int) $this->agency->payable_account_id : null,
            'amount' => '',
            'memo' => '',
        ];

        $this->resetAcceptance();
    }

    public function removeAdjustment(int $i): void
    {
        unset($this->adjustments[$i]);
        $this->adjustments = array_values($this->adjustments);

        $this->resetAcceptance();
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedAttributes(): array
    {
        return $this->validate([
            'tax_agency_id' => ['required', Rule::exists('tax_agencies', 'id')->where('company_id', $this->company->id)],
            'tax_return_no' => [
                'required', 'string', 'max:40',
                Rule::unique('tax_returns', 'tax_return_no')
                    ->where('company_id', $this->company->id)
                    ->ignore($this->taxReturn?->id),
            ],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'filing_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'adjustments' => ['array'],
            'adjustments.*.kind' => ['required', Rule::enum(TaxReturnAdjustmentKind::class)],
            'adjustments.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $this->company->id)],
            'adjustments.*.amount' => ['required', new MoneyString, function (string $attribute, mixed $value, \Closure $fail): void {
                if (Money::tryFromString((string) $value)?->cents === 0) {
                    $fail(__('Enter an amount other than zero.'));
                }
            }],
            'adjustments.*.memo' => ['nullable', 'string', 'max:255'],
        ], [], [
            'adjustments.*.kind' => __('type'),
            'adjustments.*.account_id' => __('account'),
            'adjustments.*.amount' => __('amount'),
            'adjustments.*.memo' => __('memo'),
        ]);
    }

    protected function persistDraft(): TaxReturn
    {
        $data = $this->validatedAttributes();

        // Only keep exclusions that match a line currently on the return, so a
        // changed period or agency never leaves orphaned IDs on the record.
        $visibleIds = $this->preview?->returnLines()->pluck('journal_line_id')->map('intval')->all() ?? [];
        $data['excluded_journal_line_ids'] = array_values(array_intersect($this->excludedLineIds, $visibleIds));

        $data['adjustments'] = array_map(fn (array $row) => [
            'kind' => $row['kind'],
            'account_id' => (int) $row['account_id'],
            'amount_cents' => Money::fromString((string) $row['amount'])->cents,
            'memo' => $row['memo'] ?? null,
        ], array_values($this->adjustments));

        return $this->taxReturn = app(\App\Actions\Tax\SaveTaxReturn::class)->handle($data, $this->taxReturn);
    }

    public function saveDraft(): void
    {
        $return = $this->persistDraft();

        Flux::toast(variant: 'success', text: __('Draft saved.'));
        $this->redirectRoute('tax-returns.show', ['company' => $this->company->slug, 'tax_return' => $return->id], navigate: true);
    }

    public function fileReturn(TaxReturnFiler $filer): void
    {
        $return = $this->persistDraft();

        try {
            $filed = $filer->file($return, $this->acceptDifference ? $this->acceptedDifferenceCents : null);
        } catch (\RuntimeException $e) {
            $this->resetAcceptance();
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Tax return filed.'));
        $this->redirectRoute('tax-returns.show', ['company' => $this->company->slug, 'tax_return' => $filed->id], navigate: true);
    }
}; ?>

<section class="w-full">
    @if ($editLockBlocked) <x-edit-lock.blocked :lock="$this->editLockView" /> @else
    <x-edit-lock.status :lock="$this->editLockView" />

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $taxReturn ? __('Edit tax return') : __('File a tax return') }}</flux:heading>
            <flux:subheading>{{ __('Pick a tax agency and period, check the figures and adjust them, and reconcile them to the ledger before filing — once filed, the snapshot is permanent and the period is locked for that agency.') }}</flux:subheading>
        </div>
        <div class="flex gap-2">
            <flux:button variant="ghost" :href="route('tax-returns.index', ['company' => $company->slug])" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
        <flux:select wire:model.live="tax_agency_id" :label="__('Tax agency')" data-test="tax-agency-select">
            <flux:select.option value="">{{ __('Choose an agency…') }}</flux:select.option>
            @foreach ($this->agencies as $agency)
                <flux:select.option :value="$agency->id">{{ $agency->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input :label="__('Return #')" wire:model="tax_return_no" data-test="tax-return-no-input" />
        <flux:input :label="__('Period start')" type="date" wire:model.live="period_start" data-test="period-start-input" />
        <flux:input :label="__('Period end')" type="date" wire:model.live="period_end" data-test="period-end-input" />
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
        <flux:input :label="__('Filing reference (optional)')" wire:model="filing_reference" placeholder="{{ __('Government confirmation #') }}" />
        <flux:textarea :label="__('Notes')" wire:model="notes" rows="2" />
    </div>

    @php($figures = $this->preview)

    <div class="mt-8">
        <flux:heading size="lg">{{ __('Preview') }}</flux:heading>
        <flux:subheading>{{ __('Live list of every journal line that will be snapshotted on filing. Uncheck a line to leave it out — handy for imported balances that don’t belong to this period.') }}</flux:subheading>

        <x-tax-return.tiles :collected="$figures?->collectedCents ?? 0" :paid="$figures?->paidCents ?? 0" :other="$figures?->otherAdjustmentsCents ?? 0" :net="$figures?->netCents ?? 0" test-prefix="preview" />

        <div class="mt-4 overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left w-10">{{ __('Include') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Entry #') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Document') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Bucket') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($figures?->returnLines() ?? [] as $line)
                        @php($excluded = $line['excluded'])
                        <tr wire:key="preview-line-{{ $line['journal_line_id'] }}" @class(['opacity-40' => $excluded])>
                            <td class="px-4 py-2">
                                <flux:checkbox
                                    :checked="! $excluded"
                                    wire:click="toggleLine({{ $line['journal_line_id'] }})"
                                    data-test="include-line-{{ $line['journal_line_id'] }}"
                                />
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">{{ $line['entry_date']->toDateString() }}</td>
                            <td class="px-4 py-2 font-mono">{{ $line['entry_no'] }}</td>
                            <td class="px-4 py-2">{{ $line['doc_label'] }}</td>
                            <td class="px-4 py-2"><x-tax-return.bucket-badge :bucket="$line['bucket']" /></td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($line['amount_cents'] / 100, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ __('No transactions in this period for this agency.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-8">
        <flux:heading size="lg">{{ __('Adjustments') }}</flux:heading>
        <flux:subheading>
            {{ __('Change the tax collected or the ITCs by any amount, or add a commission, fee or correction. Coded to the tax payable account, an adjustment only changes the return — the amount is already in the ledger. Coded to any other account, filing posts it against the tax payable account on the last day of the period.') }}
        </flux:subheading>

        @if ($adjustments !== [])
            <div class="mt-4 overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted">
                        <tr>
                            <th class="px-4 py-2 text-left">{{ __('Type') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('Account') }}</th>
                            <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('Memo') }}</th>
                            <th class="px-4 py-2 w-10"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($adjustments as $i => $row)
                            <tr wire:key="adjustment-{{ $i }}" class="align-top">
                                <td class="px-4 py-2 min-w-32">
                                    <flux:select wire:model.live="adjustments.{{ $i }}.kind" data-test="adjustment-kind-{{ $i }}">
                                        @foreach (TaxReturnAdjustmentKind::cases() as $kind)
                                            <flux:select.option :value="$kind->value">{{ __($kind->label()) }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <flux:error name="adjustments.{{ $i }}.kind" />
                                </td>
                                <td class="px-4 py-2 min-w-64">
                                    <flux:select wire:model.live="adjustments.{{ $i }}.account_id" data-test="adjustment-account-{{ $i }}">
                                        <flux:select.option value="">{{ __('Choose an account…') }}</flux:select.option>
                                        @foreach ($this->adjustmentAccountOptions as $option)
                                            <flux:select.option :value="$option['id']">{{ $option['label'] }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <flux:error name="adjustments.{{ $i }}.account_id" />
                                </td>
                                <td class="px-4 py-2 min-w-32">
                                    <x-amount-input model="adjustments.{{ $i }}.amount" modifiers=".live.debounce.500ms" class:input="text-right" data-test="adjustment-amount-{{ $i }}" />
                                    <flux:error name="adjustments.{{ $i }}.amount" />
                                </td>
                                <td class="px-4 py-2 min-w-48">
                                    <flux:input wire:model="adjustments.{{ $i }}.memo" placeholder="{{ __('e.g. Collector’s commission') }}" data-test="adjustment-memo-{{ $i }}" />
                                </td>
                                <td class="px-4 py-2">
                                    <flux:button variant="ghost" size="sm" icon="trash" wire:click="removeAdjustment({{ $i }})" data-test="remove-adjustment-{{ $i }}" :aria-label="__('Remove adjustment')" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <flux:text class="mt-2 text-xs text-muted-foreground">{{ __('Collected and ITC amounts add to that box. Other amounts are signed by their effect on the net owing — enter a commission you keep as a negative amount.') }}</flux:text>
        @endif

        <div class="mt-3">
            <flux:button size="sm" icon="plus" wire:click="addAdjustment" data-test="add-adjustment">{{ __('Add adjustment') }}</flux:button>
        </div>
    </div>

    @if ($figures)
        <div class="mt-8">
            <x-tax-return.reconciliation
                :reconciliation="$figures->reconciliation"
                :account="$this->agency?->payableAccount"
                :period-start="$period_start"
                :period-end="$period_end"
                :ledger-lines="$figures->ledgerOnlyLines()"
            />

            @if (! $figures->reconciles())
                <div class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-800 dark:bg-amber-950" data-test="difference-warning">
                    <div class="font-medium">{{ __('The return differs from the ledger by :amount.', ['amount' => number_format($figures->differenceCents() / 100, 2)]) }}</div>
                    <div class="mt-1 text-muted-foreground">{{ __('Add an adjustment until the difference is zero, or accept it to file anyway.') }}</div>
                    <div class="mt-3">
                        <flux:checkbox wire:model.live="acceptDifference" :label="__('File with a difference of :amount', ['amount' => number_format($figures->differenceCents() / 100, 2)])" data-test="accept-difference" />
                    </div>
                </div>
            @endif
        </div>
    @endif

    <div class="mt-6 flex justify-end gap-2">
        <flux:button variant="filled" wire:click="saveDraft" data-test="save-draft-button">{{ __('Save draft') }}</flux:button>
        <flux:button variant="primary" wire:click="fileReturn" :disabled="! $this->canFile" wire:confirm="{{ __('File this return? The snapshot is permanent, the period will be locked for this agency, and adjustments coded to other accounts will post to the ledger.') }}" data-test="file-return-button">{{ __('File return') }}</flux:button>
    </div>
    @endif
</section>
