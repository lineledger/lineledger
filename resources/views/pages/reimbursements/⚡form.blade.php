<?php

use App\Enums\AccountType;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\TaxCode;
use App\Actions\Purchasing\SaveBill;
use App\Rules\MoneyString;
use App\Services\Posting\BillPoster;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\TaxCalculator;
use App\Support\Money;
use App\Support\Quantity;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Reimbursement')] class extends Component {
    public Company $company;

    public ?Bill $bill = null;

    public ?int $contact_id = null;

    public string $bill_no = '';

    public string $bill_date = '';

    public string $due_date = '';

    public string $memo = '';

    /**
     * @var array<int, array{
     *     account_id: ?int, description: string,
     *     quantity: string, unit_price: string, tax_code_id: ?int,
     *     subtotal: int, tax: int, total: int
     * }>
     */
    public array $lines = [];

    public function mount(Company $company, ?Bill $bill = null): void
    {
        $this->company = $company;

        if ($bill && $bill->exists) {
            abort_if($bill->status === BillStatus::Void, 403, 'Voided reimbursements cannot be edited.');
            abort_if($bill->bill_type !== BillType::Reimbursement, 404);

            $this->bill = $bill->load('lines');
            $this->contact_id = $bill->contact_id;
            $this->bill_no = $bill->bill_no;
            $this->bill_date = $bill->bill_date->toDateString();
            $this->due_date = $bill->due_date->toDateString();
            $this->memo = $bill->memo ?? '';

            $this->lines = $bill->lines->map(fn ($l) => [
                'account_id' => $l->account_id,
                'description' => $l->description ?? '',
                'quantity' => Quantity::format($l->quantity),
                'unit_price' => Money::fromCents((int) $l->unit_price_cents)->toDecimalString(),
                'tax_code_id' => $l->tax_code_id,
                'secondary_tax_code_id' => $l->secondary_tax_code_id,
                'tax_code_ids' => array_values(array_filter([$l->tax_code_id, $l->secondary_tax_code_id])),
                'tax_override' => $l->tax_override_cents !== null ? Money::fromCents((int) $l->tax_override_cents)->toDecimalString() : '',
                'subtotal' => (int) $l->line_subtotal_cents,
                'auto_tax' => 0,
                'tax' => (int) $l->line_tax_cents,
                'secondary_tax' => (int) $l->secondary_tax_cents,
                'total' => (int) $l->line_total_cents,
            ])->all();
        } else {
            $this->bill_date = $this->company->currentDateTime()->toDateString();
            $this->due_date = $this->company->currentDateTime()->toDateString();
            $this->bill_no = app(DocumentNumberGenerator::class)->next($company, Bill::class, 'bill_no', 'REIM');
            $this->lines = [$this->emptyLine()];
        }
    }

    protected function emptyLine(): array
    {
        return [
            'account_id' => null,
            'description' => '',
            'quantity' => '1',
            'unit_price' => '0.00',
            'tax_code_id' => null,
            'secondary_tax_code_id' => null,
            'tax_code_ids' => [],
            'tax_override' => '',
            'subtotal' => 0,
            'auto_tax' => 0,
            'tax' => 0,
            'secondary_tax' => 0,
            'total' => 0,
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

    public function updatedLines(mixed $value, ?string $key = null): void
    {
        // Livewire passes a null key when the whole `lines` array is updated
        // (a top-level, dot-less path) rather than a single nested field.
        if ($key === null) {
            return;
        }

        $i = (int) explode('.', $key)[0];

        // The tax picker is a multi-select bound to tax_code_ids; fan the
        // (max two) chosen codes back out to the primary/secondary columns.
        if (str_ends_with($key, '.tax_code_ids')) {
            $ids = array_slice(array_values(array_unique(array_filter(
                array_map('intval', (array) $this->lines[$i]['tax_code_ids'])
            ))), 0, 2);
            $this->lines[$i]['tax_code_id'] = $ids[0] ?? null;
            $this->lines[$i]['secondary_tax_code_id'] = $ids[1] ?? null;
        }

        $this->recalcLine($i);
    }

    protected function recalcLine(int $i): void
    {
        $calc = app(TaxCalculator::class);

        $line = $this->lines[$i];

        $qty = $line['quantity'] === '' ? '0' : $line['quantity'];
        $price = $line['unit_price'] === '' ? '0' : $line['unit_price'];

        try {
            $unitCents = Money::fromString($price)->cents;
        } catch (\Throwable) {
            $unitCents = 0;
        }

        $taxCode = $line['tax_code_id'] ? TaxCode::find($line['tax_code_id']) : null;
        $secondaryTaxCode = ($line['secondary_tax_code_id'] ?? null) ? TaxCode::find($line['secondary_tax_code_id']) : null;

        // Keep the multi-select picker in step with the columns when a tax code
        // is set indirectly.
        $this->lines[$i]['tax_code_ids'] = array_values(array_filter([$line['tax_code_id'], $line['secondary_tax_code_id'] ?? null]));

        $totals = $calc->line($qty, $unitCents, $taxCode, 0, null, 0, null, $secondaryTaxCode);

        // A manual tax override (cents) wins over the auto-computed amount, mirroring
        // the bill/expense flow. Blank falls back to the calculated tax.
        $autoTax = $totals['tax_cents'];
        $override = trim((string) ($line['tax_override'] ?? ''));
        $taxCents = $override === '' ? $autoTax : (Money::tryFromString($override)?->cents ?? $autoTax);
        $secondaryTax = $totals['secondary_tax_cents'];

        $this->lines[$i]['subtotal'] = $totals['subtotal_cents'];
        $this->lines[$i]['auto_tax'] = $autoTax;
        $this->lines[$i]['tax'] = $taxCents;
        $this->lines[$i]['secondary_tax'] = $secondaryTax;
        $this->lines[$i]['total'] = $totals['subtotal_cents'] + $taxCents + $secondaryTax;
    }

    public function saveDraft(): void
    {
        if ($this->bill?->journal_entry_id) {
            $this->addError('lines', __('This reimbursement is posted. Use Save to update it in place.'));

            return;
        }

        $this->persist();
        Flux::toast(variant: 'success', text: __('Draft saved.'));
        $this->redirectRoute('reimbursements.edit', ['company' => $this->company->slug, 'bill' => $this->bill->id], navigate: true);
    }

    public function post(BillPoster $poster): void
    {
        $wasPosted = $this->bill?->journal_entry_id !== null;

        $this->persist();

        try {
            $wasPosted ? $poster->repost($this->bill) : $poster->post($this->bill);
        } catch (PeriodLockedException|RuntimeException $e) {
            $this->addError('lines', $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: $wasPosted ? __('Reimbursement updated.') : __('Reimbursement posted.'));
        $this->redirectRoute('reimbursements.show', ['company' => $this->company->slug, 'bill' => $this->bill->id], navigate: true);
    }

    protected function persist(): void
    {
        $companyId = $this->company->id;

        $validated = $this->validate([
            'contact_id' => ['required', 'integer', Rule::exists('contacts', 'id')->where('company_id', $companyId)->where('is_employee', true)],
            'bill_no' => ['required', 'string', 'max:40'],
            'bill_date' => ['required', 'date'],
            'due_date' => ['required', 'date'],
            'memo' => ['nullable', 'string'],
            'lines' => ['array', 'min:1'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['required', 'string', new MoneyString],
            'lines.*.tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('company_id', $companyId)],
            'lines.*.secondary_tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('company_id', $companyId)],
            'lines.*.tax_override' => ['nullable', 'string', new MoneyString],
        ]);

        $this->bill = app(SaveBill::class)->handle([
            'contact_id' => $validated['contact_id'],
            'bill_type' => BillType::Reimbursement->value,
            'bill_no' => $validated['bill_no'],
            'bill_date' => $validated['bill_date'],
            'due_date' => $validated['due_date'],
            'memo' => $validated['memo'] ?: null,
            'lines' => array_map(fn ($line) => [
                'account_id' => $line['account_id'],
                'description' => $line['description'] ?? '',
                'quantity' => $line['quantity'],
                'unit_price_cents' => Money::fromString($line['unit_price'])->cents,
                'tax_code_id' => $line['tax_code_id'] ?? null,
                'secondary_tax_code_id' => $line['secondary_tax_code_id'] ?? null,
                'tax_override_cents' => ($line['tax_override'] ?? '') !== ''
                    ? Money::fromString($line['tax_override'])->cents
                    : null,
            ], $validated['lines']),
        ], $this->bill);
    }

    #[Computed]
    public function employees()
    {
        return Contact::query()->where('is_employee', true)->where('is_active', true)->orderBy('display_name')->get(['id', 'display_name']);
    }

    #[Computed]
    public function expenseAccountOptions()
    {
        return Account::query()
            ->where('type', AccountType::Expense->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function taxCodeOptions()
    {
        return TaxCode::query()->where('is_active', true)->orderBy('code')->get();
    }

    #[Computed]
    public function totals(): array
    {
        $sub = array_sum(array_column($this->lines, 'subtotal'));
        $tax = array_sum(array_column($this->lines, 'tax')) + array_sum(array_column($this->lines, 'secondary_tax'));

        return ['subtotal' => $sub, 'tax' => $tax, 'total' => $sub + $tax];
    }

    /**
     * Per-tax-code breakdown of the live line tax, so the footer can show each
     * tax (e.g. GST and PST) on its own row rather than one combined "Tax" total.
     *
     * @return array<int, array{label: string, rate: float, tax_cents: int}>
     */
    #[Computed]
    public function taxBreakdown(): array
    {
        $codes = $this->taxCodeOptions->keyBy('id');
        $rows = [];

        foreach ($this->lines as $line) {
            foreach ([
                [$line['tax_code_id'] ?? null, (int) ($line['tax'] ?? 0)],
                [$line['secondary_tax_code_id'] ?? null, (int) ($line['secondary_tax'] ?? 0)],
            ] as [$id, $cents]) {
                if (! $id || $cents === 0) {
                    continue;
                }

                $code = $codes[$id] ?? null;
                $rows[$id] ??= [
                    'label' => $code ? (string) $code->name : '',
                    'rate' => $code ? $code->ratePercent() : 0.0,
                    'tax_cents' => 0,
                ];
                $rows[$id]['tax_cents'] += $cents;
            }
        }

        return array_values($rows);
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1" class="mb-6">{{ $bill?->id ? __('Edit reimbursement') : __('New reimbursement') }}</flux:heading>

    <form wire:submit="post" class="space-y-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:select wire:model.live="contact_id" :label="__('Employee')" required data-test="reimbursement-employee-select">
                <flux:select.option value="">{{ __('— Select employee —') }}</flux:select.option>
                @foreach ($this->employees as $c)
                    <flux:select.option :value="$c->id">{{ $c->display_name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="bill_no" :label="__('Reimbursement #')" required data-test="reimbursement-no-input" />
            <flux:input type="date" wire:model="bill_date" :label="__('Expense date')" required />
            <flux:input type="date" wire:model="due_date" :label="__('Pay by')" required />
        </div>

        <flux:input wire:model="memo" :label="__('Memo')" placeholder="{{ __('e.g. October expense report') }}" />

        <div class="overflow-x-auto rounded-lg border border-border">
            <table
                class="w-full text-sm"
                x-on:keydown.tab.capture="tabAddRow($event)"
                x-data="{
                    addRowAndFocus(next) {
                        this.$wire.addLine().then(() => {
                            this.$nextTick(() => this.$root.querySelector('[data-item-input=&quot;' + next + '&quot;], [data-line-first=&quot;' + next + '&quot;]')?.focus());
                        });
                    },
                    tabAddRow(event) {
                        if (event.shiftKey) return;
                        const row = event.target.closest('tr');
                        if (! row || ! row.parentElement) return;
                        const fields = [...row.querySelectorAll('input:not([type=hidden]), select, textarea, [data-test=&quot;line-tax&quot;]')].filter((el) => ! el.disabled && el.offsetParent !== null);
                        if (! fields.length || event.target !== fields[fields.length - 1]) return;
                        const rows = [...row.parentElement.querySelectorAll(':scope > tr')];
                        if (row !== rows[rows.length - 1]) return;
                        event.preventDefault();
                        this.addRowAndFocus(rows.length);
                    },
                }"
            >
                <thead class="hidden bg-muted lg:table-header-group">
                    <tr>
                        <th class="px-2 py-2 text-left">{{ __('Description') }}</th>
                        <th class="px-2 py-2 text-left w-52">{{ __('Expense account') }}</th>
                        <th class="px-2 py-2 text-right w-20">{{ __('Qty') }}</th>
                        <th class="px-2 py-2 text-right w-28">{{ __('Amount') }}</th>
                        <th class="px-2 py-2 text-left w-32">{{ __('Tax') }}</th>
                        <th class="px-2 py-2 text-right w-28">{{ __('Total') }}</th>
                        <th class="px-2 py-2 w-10"></th>
                    </tr>
                </thead>
                <tbody class="lg:divide-y lg:divide-border">
                    @foreach ($lines as $i => $line)
                        <tr wire:key="line-{{ $i }}" data-test="reimbursement-line-row" class="block border-b border-border p-3 lg:table-row lg:border-0 lg:p-0">
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Description') }}</span>
                                <flux:input wire:model="lines.{{ $i }}.description" data-line-first="{{ $i }}" />
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Expense account') }}</span>
                                <flux:select wire:model.live="lines.{{ $i }}.account_id" data-test="line-account">
                                    <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                    @foreach ($this->expenseAccountOptions as $opt)
                                        <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Qty') }}</span>
                                <flux:input wire:model.live="lines.{{ $i }}.quantity" class="lg:text-right" />
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Amount') }}</span>
                                <flux:input wire:model.live="lines.{{ $i }}.unit_price" class="lg:text-right" data-test="line-unit-price" />
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Tax') }}</span>
                                @php($selectedTaxIds = $line['tax_code_ids'] ?? [])
                                <flux:dropdown>
                                    <flux:button variant="outline" size="sm" icon:trailing="chevron-down" class="w-full justify-between font-normal" data-test="line-tax">
                                        <span class="truncate">{{ $this->taxCodeOptions->whereIn('id', $selectedTaxIds)->pluck('code')->implode(', ') ?: __('Select tax') }}</span>
                                    </flux:button>
                                    <flux:menu>
                                        <flux:menu.checkbox.group wire:model.live="lines.{{ $i }}.tax_code_ids">
                                            @foreach ($this->taxCodeOptions as $opt)
                                                <flux:menu.checkbox value="{{ $opt->id }}" :disabled="count($selectedTaxIds) === 2 && ! in_array($opt->id, $selectedTaxIds)" keep-open>{{ $opt->code }}</flux:menu.checkbox>
                                            @endforeach
                                        </flux:menu.checkbox.group>
                                    </flux:menu>
                                </flux:dropdown>
                                <flux:input wire:model.live.debounce.500ms="lines.{{ $i }}.tax_override" size="sm"
                                    class="mt-1 lg:text-right"
                                    placeholder="{{ number_format(($line['auto_tax'] ?? 0) / 100, 2) }}"
                                    data-test="line-tax-override" />
                            </td>
                            <td class="flex items-center justify-between px-2 py-1 font-mono lg:table-cell lg:py-2 lg:text-right" data-test="line-total">
                                <span class="text-xs font-medium text-muted-foreground lg:hidden">{{ __('Total') }}</span>
                                <span>{{ number_format($line['total'] / 100, 2) }}</span>
                            </td>
                            <td class="block px-2 pt-2 text-right lg:table-cell lg:p-2">
                                <flux:button variant="ghost" size="sm" icon="x-mark" type="button" tabindex="-1" wire:click="removeLine({{ $i }})">
                                    <span class="lg:hidden">{{ __('Remove line') }}</span>
                                </flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="hidden bg-muted lg:table-footer-group">
                    <tr>
                        <td colspan="5" class="px-2 py-2 text-right font-medium">{{ __('Subtotal') }}</td>
                        <td class="px-2 py-2 text-right font-mono" data-test="reimbursement-subtotal">{{ number_format($this->totals['subtotal'] / 100, 2) }}</td>
                        <td></td>
                    </tr>
                    @forelse ($this->taxBreakdown as $taxRow)
                        <tr data-test="reimbursement-tax-row">
                            <td colspan="5" class="px-2 py-2 text-right font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</td>
                            <td class="px-2 py-2 text-right font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</td>
                            <td></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-2 py-2 text-right font-medium">{{ __('Tax') }}</td>
                            <td class="px-2 py-2 text-right font-mono" data-test="reimbursement-tax">{{ number_format($this->totals['tax'] / 100, 2) }}</td>
                            <td></td>
                        </tr>
                    @endforelse
                    <tr class="text-base">
                        <td colspan="5" class="px-2 py-2 text-right font-semibold">{{ __('Total') }}</td>
                        <td class="px-2 py-2 text-right font-mono font-semibold" data-test="reimbursement-total">{{ number_format($this->totals['total'] / 100, 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

            {{-- Mobile totals (tfoot is desktop-only) --}}
            <div class="space-y-1 border-t border-border bg-muted px-3 py-3 text-sm lg:hidden">
                <div class="flex justify-between"><span class="font-medium">{{ __('Subtotal') }}</span><span class="font-mono">{{ number_format($this->totals['subtotal'] / 100, 2) }}</span></div>
                @forelse ($this->taxBreakdown as $taxRow)
                    <div class="flex justify-between"><span class="font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</span><span class="font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</span></div>
                @empty
                    <div class="flex justify-between"><span class="font-medium">{{ __('Tax') }}</span><span class="font-mono">{{ number_format($this->totals['tax'] / 100, 2) }}</span></div>
                @endforelse
                <div class="flex justify-between text-base"><span class="font-semibold">{{ __('Total') }}</span><span class="font-mono font-semibold">{{ number_format($this->totals['total'] / 100, 2) }}</span></div>
            </div>
        </div>

        @error('lines') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror

        <div class="flex items-center justify-between">
            <flux:button variant="filled" type="button" icon="plus" wire:click="addLine">{{ __('Add line') }}</flux:button>

            <div class="flex gap-2">
                @if ($bill?->journal_entry_id)
                    <flux:button variant="primary" type="submit" data-test="post-reimbursement-button">{{ __('Save changes') }}</flux:button>
                @else
                    <flux:button variant="filled" type="button" wire:click="saveDraft" data-test="save-draft-button">{{ __('Save draft') }}</flux:button>
                    <flux:button variant="primary" type="submit" data-test="post-reimbursement-button">{{ __('Post reimbursement') }}</flux:button>
                @endif
            </div>
        </div>
    </form>
</section>
