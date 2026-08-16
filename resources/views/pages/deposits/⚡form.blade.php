<?php

use App\Actions\Banking\SaveDeposit;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\DepositStatus;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Account;
use App\Models\Classification;
use App\Models\Company;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\DepositLine;
use App\Models\Location;
use App\Models\SalesReceipt;
use App\Rules\MoneyString;
use App\Services\Posting\DepositPoster;
use App\Services\Posting\DocumentNumberGenerator;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Make deposit')] class extends Component
{
    public Company $company;

    public ?Deposit $deposit = null;

    public ?int $bank_account_id = null;

    public string $deposit_no = '';

    public string $deposit_date = '';

    public string $memo = '';

    #[Url(as: 'from')]
    public ?int $duplicateFromId = null;

    /**
     * Receipts available for deposit: keyed by receipt_id → include
     *
     * @var array<int, array{receipt_id: int, date: string, contact: string, amount: int, included: bool}>
     */
    public array $availableReceipts = [];

    /**
     * "Other" deposit lines (e.g. owner contribution, refund)
     *
     * @var array<int, array{account_id: ?int, contact_id: ?int, description: string, amount: string}>
     */
    public array $otherLines = [];

    public function mount(Company $company, ?Deposit $deposit = null): void
    {
        $this->company = $company;

        if ($deposit && $deposit->exists) {
            abort_if($deposit->status === DepositStatus::Void, 403);

            $this->deposit = $deposit->load('lines.customerReceipt', 'lines.salesReceipt');
            $this->bank_account_id = $deposit->bank_account_id;
            $this->deposit_no = $deposit->deposit_no;
            $this->deposit_date = $deposit->deposit_date->toDateString();
            $this->memo = $deposit->memo ?? '';
            $this->otherLines = $this->mapOtherLines($deposit);

            $this->loadAvailableReceipts();

            return;
        }

        $this->deposit_date = $this->company->currentDateTime()->toDateString();
        $this->bank_account_id = $this->defaultBankAccountId();
        $this->deposit_no = app(DocumentNumberGenerator::class)->next($company, Deposit::class, 'deposit_no', 'DEP');

        $this->loadAvailableReceipts();

        if ($this->duplicateFromId) {
            $this->prefillFrom($this->duplicateFromId);
        }
    }

    /**
     * Copy a source deposit's bank, memo and "other" lines into a fresh, unsaved
     * draft. Receipt-source lines are skipped — those receipts are already
     * deposited — and the new draft keeps today's date and generated number.
     * Ignores a source from another company.
     */
    protected function prefillFrom(int $sourceId): void
    {
        $source = Deposit::query()
            ->where('company_id', $this->company->id)
            ->with('lines')
            ->find($sourceId);

        if (! $source) {
            return;
        }

        $this->bank_account_id = $source->bank_account_id;
        $this->memo = $source->memo ?? '';
        $this->otherLines = $this->mapOtherLines($source);
    }

    /**
     * Map a deposit's non-receipt ("other") lines into the editable line shape.
     *
     * @return array<int, array{account_id: ?int, contact_id: ?int, description: string, amount: string, class_id: ?int, location_id: ?int}>
     */
    protected function mapOtherLines(Deposit $deposit): array
    {
        return $deposit->lines
            ->reject(fn (DepositLine $line) => $line->isReceiptSource())
            ->map(fn (DepositLine $line) => [
                'account_id' => $line->account_id,
                'contact_id' => $line->contact_id,
                'description' => $line->description ?? '',
                'amount' => number_format($line->amount_cents / 100, 2, '.', ''),
                'class_id' => $line->class_id,
                'location_id' => $line->location_id,
            ])->values()->all();
    }

    /**
     * Receipts parked in Undeposited Funds AND not already on a posted/draft
     * deposit — both Receive-Payment receipts and pay-now Sales Receipts.
     */
    protected function loadAvailableReceipts(): void
    {
        $undep = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->where('is_active', true)->first();

        if (! $undep) {
            return;
        }

        // Receipts already locked into OTHER draft/posted deposits are excluded;
        // when editing, this deposit's own receipts stay selectable + pre-checked.
        $depositedIds = fn (string $column) => DepositLine::query()
            ->whereHas('deposit', function ($q) {
                $q->whereIn('status', ['draft', 'posted']);

                if ($this->deposit) {
                    $q->whereKeyNot($this->deposit->id);
                }
            })
            ->whereNotNull($column)
            ->pluck($column)
            ->all();

        $rows = collect();

        // --- Customer receipts (Receive Payment) ---
        $currentCustomerIds = $this->deposit
            ? $this->deposit->lines->whereNotNull('customer_receipt_id')->pluck('customer_receipt_id')->map(fn ($id) => (int) $id)->all()
            : [];

        CustomerReceipt::query()
            ->with('contact')
            ->where('deposit_to_account_id', $undep->id)
            ->where('status', 'posted')
            ->whereNotIn('id', $depositedIds('customer_receipt_id'))
            ->orderBy('receipt_date')
            ->get()
            ->each(fn ($r) => $rows->push([
                'source' => 'customer',
                'receipt_id' => $r->id,
                'date' => $r->receipt_date->toDateString(),
                'receipt_no' => $r->receipt_no,
                'contact' => $r->contact?->display_name ?? '—',
                'reference' => $r->reference,
                'amount' => (int) $r->amount_cents,
                'included' => $this->deposit ? in_array($r->id, $currentCustomerIds, true) : true,
            ]));

        // --- Pay-now Sales Receipts ---
        $currentSalesIds = $this->deposit
            ? $this->deposit->lines->whereNotNull('sales_receipt_id')->pluck('sales_receipt_id')->map(fn ($id) => (int) $id)->all()
            : [];

        SalesReceipt::query()
            ->with('contact')
            ->where('deposit_to_account_id', $undep->id)
            ->where('status', 'posted')
            ->whereNotIn('id', $depositedIds('sales_receipt_id'))
            ->orderBy('receipt_date')
            ->get()
            ->each(fn ($r) => $rows->push([
                'source' => 'sales',
                'receipt_id' => $r->id,
                'date' => $r->receipt_date->toDateString(),
                'receipt_no' => $r->sales_receipt_no,
                'contact' => $r->contact?->display_name ?? __('Cash sale'),
                'reference' => $r->reference,
                'amount' => (int) $r->total_cents,
                'included' => $this->deposit ? in_array($r->id, $currentSalesIds, true) : true,
            ]));

        $this->availableReceipts = $rows->sortBy('date')->values()->all();
    }

    public function addOtherLine(): void
    {
        $this->otherLines[] = [
            'account_id' => null,
            'contact_id' => null,
            'description' => '',
            'amount' => '0.00',
            'class_id' => null,
            'location_id' => null,
        ];
    }

    public function removeOtherLine(int $i): void
    {
        unset($this->otherLines[$i]);
        $this->otherLines = array_values($this->otherLines);
    }

    /**
     * Validate the form and build the SaveDeposit payload, or null after adding a
     * validation error. Shared by save() (posts) and saveDraft() (leaves unposted).
     *
     * @return array<string, mixed>|null
     */
    protected function prepareDepositData(): ?array
    {
        $companyId = $this->company->id;

        $validated = $this->validate([
            'bank_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)->where('subtype', AccountSubtype::Bank->value)],
            'deposit_no' => ['required', 'string', 'max:40'],
            'deposit_date' => ['required', 'date'],
            'memo' => ['nullable', 'string'],
            'otherLines.*.account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'otherLines.*.amount' => ['nullable', 'string', new MoneyString],
            'otherLines.*.class_id' => ['nullable', 'integer', Rule::exists('classifications', 'id')->where('company_id', $companyId)],
            'otherLines.*.location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('company_id', $companyId)],
        ]);

        // Validate at least one included line
        $included = collect($this->availableReceipts)->where('included', true);
        $otherWithAmount = collect($this->otherLines)->filter(function ($l) {
            try {
                // Non-zero: positive = added funds, negative = a fee/deduction.
                return Money::fromString($l['amount'] === '' ? '0' : $l['amount'])->cents !== 0;
            } catch (Throwable) {
                return false;
            }
        });

        if ($included->isEmpty() && $otherWithAmount->isEmpty()) {
            $this->addError('deposit', __('Select at least one receipt or add an "other" line.'));

            return null;
        }

        foreach ($otherWithAmount as $line) {
            if (! $line['account_id']) {
                $this->addError('otherLines', __('Account required for other deposit lines.'));

                return null;
            }
        }

        $lines = [];

        foreach ($included as $row) {
            if (($row['source'] ?? 'customer') === 'sales') {
                $lines[] = [
                    'sales_receipt_id' => $row['receipt_id'],
                    'description' => 'Sales receipt '.$row['receipt_no'].' — '.$row['contact'],
                    'amount_cents' => $row['amount'],
                ];

                continue;
            }

            $lines[] = [
                'customer_receipt_id' => $row['receipt_id'],
                'description' => 'Receipt '.$row['receipt_no'].' — '.$row['contact'],
                'amount_cents' => $row['amount'],
            ];
        }

        foreach ($otherWithAmount as $line) {
            $lines[] = [
                'account_id' => $line['account_id'],
                'contact_id' => $line['contact_id'] ?: null,
                'description' => $line['description'] ?: null,
                'amount_cents' => Money::fromString($line['amount'])->cents,
                'class_id' => $line['class_id'] ?? null,
                'location_id' => $line['location_id'] ?? null,
            ];
        }

        return [
            'bank_account_id' => $validated['bank_account_id'],
            'deposit_no' => $validated['deposit_no'],
            'deposit_date' => $validated['deposit_date'],
            'memo' => $validated['memo'] ?: null,
            'lines' => $lines,
        ];
    }

    public function save(DepositPoster $poster): void
    {
        $data = $this->prepareDepositData();

        if ($data === null) {
            return;
        }

        $wasPosted = $this->deposit?->journal_entry_id !== null;

        // Save the deposit and (re)post its GL entry in one transaction so a lock
        // failure on repost can't leave the deposit lines out of sync with the ledger.
        try {
            $deposit = DB::transaction(function () use ($data, $poster, $wasPosted): Deposit {
                $deposit = app(SaveDeposit::class)->handle($data, $this->deposit);

                $wasPosted ? $poster->repost($deposit) : $poster->post($deposit);

                return $deposit;
            });
        } catch (PeriodLockedException|RuntimeException $e) {
            $this->addError('deposit', $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: $wasPosted ? __('Deposit updated.') : __('Deposit posted.'));
        $this->redirectRoute('deposits.show', ['company' => $this->company->slug, 'deposit' => $deposit->id], navigate: true);
    }

    /**
     * Save the deposit slip without posting it to the GL — leaves it in Draft so
     * it can be reviewed and posted later from the deposit page.
     */
    public function saveDraft(): void
    {
        if ($this->deposit?->journal_entry_id) {
            $this->addError('deposit', __('This deposit is already posted. Use Save changes to update it.'));

            return;
        }

        $data = $this->prepareDepositData();

        if ($data === null) {
            return;
        }

        $deposit = app(SaveDeposit::class)->handle($data, $this->deposit);

        Flux::toast(variant: 'success', text: __('Draft saved.'));
        $this->redirectRoute('deposits.show', ['company' => $this->company->slug, 'deposit' => $deposit->id], navigate: true);
    }

    /**
     * Default "Deposit to" for a new deposit: reuse the bank account from the
     * most recent deposit so the last-used account is remembered between
     * deposits. Falls back to the lowest-code active bank account when there is
     * no prior deposit, or its account is no longer an active bank.
     */
    protected function defaultBankAccountId(): ?int
    {
        $activeBankIds = $this->bankAccounts->pluck('id');

        $lastUsed = Deposit::query()
            ->whereNotNull('bank_account_id')
            ->latest('id')
            ->value('bank_account_id');

        return $activeBankIds->contains($lastUsed) ? (int) $lastUsed : $activeBankIds->first();
    }

    #[Computed]
    public function bankAccounts()
    {
        return Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function otherAccountOptions()
    {
        return Account::query()
            ->whereIn('type', [AccountType::Income->value, AccountType::Equity->value, AccountType::Liability->value, AccountType::Asset->value])
            ->where('id', '!=', $this->bank_account_id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
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

    public function totalCents(): int
    {
        $sum = 0;

        foreach ($this->availableReceipts as $r) {
            if ($r['included']) {
                $sum += $r['amount'];
            }
        }

        foreach ($this->otherLines as $l) {
            try {
                $sum += Money::fromString($l['amount'] === '' ? '0' : $l['amount'])->cents;
            } catch (Throwable) {
            }
        }

        return $sum;
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1" class="mb-6">{{ $deposit ? __('Edit deposit') : __('Make deposit') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <flux:select wire:model="bank_account_id" :label="__('Deposit to')" required data-test="deposit-bank-select">
                <flux:select.option value="">{{ __('— Select bank —') }}</flux:select.option>
                @foreach ($this->bankAccounts as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="deposit_no" :label="__('Deposit #')" required data-test="deposit-no-input" />
            <flux:input type="date" wire:model="deposit_date" :label="__('Date')" required />
        </div>

        <flux:input wire:model="memo" :label="__('Memo')" />

        <div>
            <flux:heading class="mb-2">{{ __('Undeposited receipts') }}</flux:heading>
            @if (empty($availableReceipts))
                <flux:text class="py-4 text-center text-muted-foreground">{{ __('No undeposited receipts.') }}</flux:text>
            @else
                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted">
                            <tr>
                                <th class="px-3 py-2"></th>
                                <th class="px-3 py-2 text-left">{{ __('Date') }}</th>
                                <th class="px-3 py-2 text-left">{{ __('Receipt #') }}</th>
                                <th class="px-3 py-2 text-left">{{ __('From') }}</th>
                                <th class="px-3 py-2 text-left">{{ __('Ref') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($availableReceipts as $i => $r)
                                <tr wire:key="receipt-{{ $r['source'] }}-{{ $r['receipt_id'] }}" data-test="receipt-pick-row">
                                    <td class="px-3 py-2"><flux:checkbox wire:model.live="availableReceipts.{{ $i }}.included" data-test="receipt-pick-check" /></td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $r['date'] }}</td>
                                    <td class="px-3 py-2 font-mono">{{ $r['receipt_no'] }}</td>
                                    <td class="px-3 py-2">{{ $r['contact'] }}</td>
                                    <td class="px-3 py-2 text-muted-foreground">{{ $r['reference'] }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ number_format($r['amount'] / 100, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div>
            <div class="mb-2 flex items-center justify-between">
                <div>
                    <flux:heading>{{ __('Other deposits') }}</flux:heading>
                    <flux:text class="text-xs text-muted-foreground">{{ __('Add owner contributions or refunds; enter a negative amount to net out a bank/merchant fee.') }}</flux:text>
                </div>
                <flux:button variant="filled" size="sm" icon="plus" type="button" wire:click="addOtherLine">{{ __('Add line') }}</flux:button>
            </div>

            @if (! empty($otherLines))
                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted">
                            <tr>
                                <th class="px-3 py-2 text-left w-52">{{ __('Account') }}</th>
                                <th class="px-3 py-2 text-left">{{ __('Description') }}</th>
                                @if ($this->tracksClasses)
                                    <th class="px-3 py-2 text-left w-40">{{ __('Class') }}</th>
                                @endif
                                @if ($this->tracksLocations)
                                    <th class="px-3 py-2 text-left w-40">{{ __('Location') }}</th>
                                @endif
                                <th class="px-3 py-2 text-right w-28">{{ __('Amount') }}</th>
                                <th class="px-3 py-2 w-10"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($otherLines as $i => $line)
                                <tr wire:key="other-{{ $i }}" data-test="other-line-row">
                                    <td class="px-3 py-2">
                                        <flux:select wire:model="otherLines.{{ $i }}.account_id">
                                            <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                            @foreach ($this->otherAccountOptions as $opt)
                                                <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                    </td>
                                    <td class="px-3 py-2"><flux:input wire:model="otherLines.{{ $i }}.description" /></td>
                                    @if ($this->tracksClasses)
                                        <td class="px-3 py-2">
                                            <flux:select wire:model="otherLines.{{ $i }}.class_id" data-test="other-line-class">
                                                <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                                @foreach ($this->classificationOptions as $opt)
                                                    <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                                @endforeach
                                            </flux:select>
                                        </td>
                                    @endif
                                    @if ($this->tracksLocations)
                                        <td class="px-3 py-2">
                                            <flux:select wire:model="otherLines.{{ $i }}.location_id" data-test="other-line-location">
                                                <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                                @foreach ($this->locationOptions as $opt)
                                                    <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                                @endforeach
                                            </flux:select>
                                        </td>
                                    @endif
                                    <td class="px-3 py-2"><x-amount-input model="otherLines.{{ $i }}.amount" class="text-right" data-test="other-amount" /></td>
                                    <td class="px-3 py-2 text-right">
                                        <flux:button variant="ghost" size="sm" icon="x-mark" type="button" wire:click="removeOtherLine({{ $i }})" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="flex items-center justify-between rounded-lg bg-muted p-4">
            <span class="text-base font-semibold">{{ __('Deposit total') }}</span>
            <span class="text-lg font-mono font-semibold" data-test="deposit-total">{{ number_format($this->totalCents() / 100, 2) }}</span>
        </div>

        @error('deposit') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror

        <div class="flex justify-end gap-2">
            @if ($deposit?->journal_entry_id)
                <flux:button variant="primary" type="submit" data-test="save-deposit-button">{{ __('Save changes') }}</flux:button>
            @else
                <flux:button variant="filled" type="button" wire:click="saveDraft" data-test="save-deposit-draft-button">{{ __('Save draft') }}</flux:button>
                <flux:button variant="primary" type="submit" data-test="save-deposit-button">{{ __('Save & post') }}</flux:button>
            @endif
        </div>
    </form>
</section>
