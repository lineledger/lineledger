<?php

use App\Actions\Sales\SaveReceipt;
use App\Enums\AccountSubtype;
use App\Enums\InvoiceStatus;
use App\Enums\ReceiptStatus;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Rules\MoneyString;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\ReceiptPoster;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Receive payment')] class extends Component {
    public Company $company;

    public ?CustomerReceipt $receipt = null;

    public ?int $contact_id = null;

    public string $contact_query = '';

    public bool $contact_creating = false;

    public string $new_contact_name = '';

    public string $receipt_no = '';

    public string $receipt_date = '';

    public ?int $deposit_to_account_id = null;

    public ?int $payment_method_id = null;

    public string $reference = '';

    public string $amount = '';

    public string $memo = '';

    /**
     * @var array<int, array{invoice_id: int, invoice_no: string, due_date: string, balance: int, apply: string}>
     */
    public array $applyTable = [];

    public function mount(Company $company, ?CustomerReceipt $receipt = null): void
    {
        $this->company = $company;

        if ($receipt && $receipt->exists) {
            abort_if($receipt->status === ReceiptStatus::Void, 403, 'Voided receipts cannot be edited.');

            $this->receipt = $receipt->load('applications.invoice');
            $this->contact_id = $receipt->contact_id;
            $this->receipt_no = $receipt->receipt_no;
            $this->receipt_date = $receipt->receipt_date->toDateString();
            $this->deposit_to_account_id = $receipt->deposit_to_account_id;
            $this->payment_method_id = $receipt->payment_method_id;
            $this->reference = $receipt->reference ?? '';
            $this->amount = number_format($receipt->amount_cents / 100, 2, '.', '');
            $this->memo = $receipt->memo ?? '';

            $this->refreshApplyTable();

            return;
        }

        $this->receipt_date = $this->company->currentDateTime()->toDateString();
        $this->receipt_no = app(DocumentNumberGenerator::class)->next($company, CustomerReceipt::class, 'receipt_no', 'REC');

        $undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->where('is_active', true)->first();

        $this->deposit_to_account_id = $undeposited?->id ?? $this->bankAccounts->first()?->id;

        if (request('invoice')) {
            $inv = Invoice::find((int) request('invoice'));
            if ($inv) {
                $this->contact_id = $inv->contact_id;
                $this->amount = number_format($inv->balanceCents() / 100, 2, '.', '');
                $this->refreshApplyTable();
                $this->applyContactPaymentDefault();
            }
        }
    }

    public string $recent_invoice_id = '';

    public function selectContact(int $id): void
    {
        $this->contact_id = $id;
        $this->contact_creating = false;
        $this->new_contact_name = '';
        $this->contact_query = '';
        $this->resetErrorBag(['contact_id', 'new_contact_name']);

        $this->refreshApplyTable();
        $this->applyContactPaymentDefault();
    }

    /**
     * Pre-load the customer's preferred payment method (set on their profile) when one
     * is configured, so receiving a payment defaults to how they usually pay.
     */
    protected function applyContactPaymentDefault(): void
    {
        if (! $this->contact_id) {
            return;
        }

        $preferred = Contact::query()->whereKey($this->contact_id)->value('preferred_payment_method_id');

        if ($preferred) {
            $this->payment_method_id = (int) $preferred;
        }
    }

    /**
     * Quick-pick a customer from a recent open invoice. Sets the customer and
     * loads their open invoices into the apply table; the amount stays blank
     * for the user to enter.
     */
    public function updatedRecentInvoiceId(string $value): void
    {
        $invoice = $value === '' ? null : Invoice::find((int) $value);

        $this->recent_invoice_id = '';

        if (! $invoice) {
            return;
        }

        $this->selectContact($invoice->contact_id);
    }

    public function startNewContact(): void
    {
        $this->new_contact_name = trim($this->contact_query);
        $this->contact_creating = true;
        $this->contact_id = null;
        $this->contact_query = '';
        $this->applyTable = [];
        $this->resetErrorBag(['contact_id', 'new_contact_name']);
    }

    public function clearContact(): void
    {
        $this->contact_id = null;
        $this->contact_creating = false;
        $this->new_contact_name = '';
        $this->contact_query = '';
        $this->applyTable = [];
        $this->resetErrorBag(['contact_id', 'new_contact_name']);
    }

    public function updatedAmount(): void
    {
        $this->autoApply();
    }

    protected function refreshApplyTable(): void
    {
        $this->applyTable = [];

        if (! $this->contact_id) {
            return;
        }

        // When editing an existing receipt for the same contact, pre-fill from
        // its applications. The balance column shows what the invoice would
        // owe IF this receipt didn't exist (current balance + what THIS
        // receipt already applied) — that's the headroom for re-application.
        $existingApplications = collect();
        $editingSameContact = $this->receipt?->exists && $this->receipt->contact_id === $this->contact_id;

        if ($editingSameContact) {
            $existingApplications = $this->receipt->applications->keyBy('invoice_id');
        }

        $invoices = Invoice::query()
            ->where('contact_id', $this->contact_id)
            ->where(function ($q) use ($existingApplications) {
                $q->whereIn('status', [InvoiceStatus::Posted->value, InvoiceStatus::Partial->value]);

                if ($existingApplications->isNotEmpty()) {
                    // Include invoices the receipt already applies to even if now paid
                    $q->orWhereIn('id', $existingApplications->keys()->all());
                }
            })
            ->orderBy('due_date')
            ->get();

        foreach ($invoices as $inv) {
            $existing = $existingApplications->get($inv->id);
            $existingApplied = (int) ($existing->amount_cents ?? 0);

            $this->applyTable[] = [
                'invoice_id' => $inv->id,
                'invoice_no' => $inv->invoice_no,
                'due_date' => $inv->due_date->toDateString(),
                // Open balance treats this receipt's existing application as un-applied,
                // so the user sees the full headroom they can re-apply.
                'balance' => $inv->balanceCents() + $existingApplied,
                'apply' => number_format($existingApplied / 100, 2, '.', ''),
            ];
        }

        // Don't auto-apply on initial edit load — keep the user's existing distribution.
        if (! $editingSameContact) {
            $this->autoApply();
        }
    }

    /**
     * Walk the apply table top-to-bottom, applying available cents until the receipt is consumed.
     */
    protected function autoApply(): void
    {
        try {
            $remaining = $this->amount === '' ? 0 : Money::fromString($this->amount)->cents;
        } catch (\Throwable) {
            $remaining = 0;
        }

        foreach ($this->applyTable as $i => $row) {
            $applied = min($remaining, $row['balance']);
            $this->applyTable[$i]['apply'] = number_format($applied / 100, 2, '.', '');
            $remaining -= $applied;
        }
    }

    public function save(ReceiptPoster $poster): void
    {
        $companyId = $this->company->id;

        if ($this->contact_creating) {
            $this->validate(
                ['new_contact_name' => ['required', 'string', 'max:255']],
                attributes: ['new_contact_name' => __('customer name')],
            );

            $contact = Contact::create([
                'display_name' => trim($this->new_contact_name),
                'is_customer' => true,
                'is_active' => true,
            ]);

            $this->contact_id = $contact->id;
            $this->contact_creating = false;
            $this->new_contact_name = '';
        }

        $validated = $this->validate([
            'contact_id' => ['required', 'integer', Rule::exists('contacts', 'id')->where('company_id', $companyId)->where('is_customer', true)],
            'receipt_no' => ['required', 'string', 'max:40'],
            'receipt_date' => ['required', 'date'],
            'deposit_to_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')->where('company_id', $companyId)],
            'reference' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'string', new MoneyString],
            'memo' => ['nullable', 'string'],
        ]);

        $amountCents = Money::fromString($validated['amount'])->cents;

        if ($amountCents <= 0) {
            $this->addError('amount', __('Amount must be greater than zero.'));

            return;
        }

        $applications = [];
        $totalApplied = 0;

        foreach ($this->applyTable as $row) {
            $applyCents = Money::tryFromString((string) $row['apply'])?->cents ?? 0;

            if ($applyCents <= 0) {
                continue;
            }

            if ($applyCents > $row['balance']) {
                $this->addError('applyTable', __('Cannot apply more than the invoice balance.'));

                return;
            }

            $applications[] = ['invoice_id' => $row['invoice_id'], 'amount_cents' => $applyCents];
            $totalApplied += $applyCents;
        }

        if ($totalApplied > $amountCents) {
            $this->addError('applyTable', __('Applied total exceeds payment amount.'));

            return;
        }

        $wasPosted = $this->receipt?->journal_entry_id !== null;

        $receipt = app(SaveReceipt::class)->handle([
            'contact_id' => $validated['contact_id'],
            'receipt_no' => $validated['receipt_no'],
            'receipt_date' => $validated['receipt_date'],
            'deposit_to_account_id' => $validated['deposit_to_account_id'],
            'payment_method_id' => $validated['payment_method_id'] ?: null,
            'reference' => $validated['reference'] ?: null,
            'amount_cents' => $amountCents,
            'memo' => $validated['memo'] ?: null,
            'applications' => $applications,
        ], $this->receipt);

        try {
            $wasPosted ? $poster->repost($receipt) : $poster->post($receipt);
        } catch (PeriodLockedException|\RuntimeException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: $wasPosted ? __('Receipt updated.') : __('Payment recorded.'));
        $this->redirectRoute('receipts.show', ['company' => $this->company->slug, 'receipt' => $receipt->id], navigate: true);
    }

    #[Computed]
    public function customers()
    {
        $query = Contact::query()->where('is_customer', true)->where('is_active', true);

        if (trim($this->contact_query) !== '') {
            $query->where('display_name', 'like', '%'.trim($this->contact_query).'%');
        }

        return $query->orderBy('display_name')->limit(50)->get(['id', 'display_name']);
    }

    #[Computed]
    public function selectedContactName(): ?string
    {
        return $this->contact_id
            ? Contact::query()->where('id', $this->contact_id)->value('display_name')
            : null;
    }

    /**
     * Recent invoices with an open balance, newest first — a shortcut for
     * picking the customer to receive a payment from.
     */
    #[Computed]
    public function recentInvoices()
    {
        return Invoice::query()
            ->whereIn('status', [InvoiceStatus::Posted->value, InvoiceStatus::Partial->value])
            ->with('contact:id,display_name')
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'contact_id', 'invoice_no', 'invoice_date', 'total_cents', 'amount_paid_cents']);
    }

    #[Computed]
    public function depositToOptions()
    {
        // Active bank/undeposited accounts, plus whatever is already selected so an
        // edit never silently drops a since-deactivated account.
        return Account::query()
            ->where(function ($q) {
                $q->where(fn ($inner) => $inner
                    ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::UndepositedFunds->value])
                    ->where('is_active', true));

                if ($this->deposit_to_account_id) {
                    $q->orWhere('id', $this->deposit_to_account_id);
                }
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'subtype']);
    }

    #[Computed]
    public function bankAccounts()
    {
        return Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->get(['id']);
    }

    #[Computed]
    public function paymentMethodOptions()
    {
        return PaymentMethod::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Outstanding credit for the selected customer, with the open-invoice total and
     * net balance, so the user can see why an invoice's own balance differs from
     * what the customer actually owes. Null when no customer or no credit.
     *
     * @return array{credit: int, open_invoices: int, net: int}|null
     */
    #[Computed]
    public function creditSummary(): ?array
    {
        if (! $this->contact_id) {
            return null;
        }

        $openInvoices = array_sum(array_column($this->applyTable, 'balance'));

        // The customer's existing on-account credit = the gap between their open invoices
        // and their true GL AR balance (credit memos + unapplied payments + GL credits).
        // Same figure AR Aging uses; floored so a net-credit customer reads $0 owing.
        $arAccountIds = Account::query()
            ->where('company_id', $this->company->id)
            ->where('subtype', AccountSubtype::AccountsReceivable->value)
            ->pluck('id');

        $glAr = $arAccountIds->isEmpty() ? 0 : (int) DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $this->company->id)
            ->where('je.is_posted', true)
            ->whereIn('jl.account_id', $arAccountIds)
            ->where('jl.contact_id', $this->contact_id)
            ->sum(DB::raw('jl.debit_cents - jl.credit_cents'));

        $credit = max(0, $openInvoices - max(0, $glAr));

        if ($credit <= 0) {
            return null;
        }

        return [
            'credit' => $credit,
            'open_invoices' => $openInvoices,
            'net' => $openInvoices - $credit,
        ];
    }

    /**
     * Receipt amount not applied to any invoice — it posts to AR as an on-account
     * customer credit (an overpayment / prepayment), not lost. Surfaced so it's never silent.
     */
    public function unappliedCents(): int
    {
        try {
            $amount = $this->amount === '' ? 0 : Money::fromString($this->amount)->cents;
        } catch (\Throwable) {
            $amount = 0;
        }

        return max(0, $amount - $this->totalApplied());
    }

    public function totalApplied(): int
    {
        $total = 0;
        foreach ($this->applyTable as $row) {
            try {
                $total += Money::fromString($row['apply'] === '' ? '0' : $row['apply'])->cents;
            } catch (\Throwable) {
            }
        }

        return $total;
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1" class="mb-6">{{ __('Receive payment') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
        @if ($this->recentInvoices->isNotEmpty())
            <flux:select
                wire:model.live="recent_invoice_id"
                :label="__('Quick pick from a recent invoice')"
                class="md:max-w-md"
                data-test="receipt-recent-invoices"
            >
                <flux:select.option value="">{{ __('Recent invoices…') }}</flux:select.option>
                @foreach ($this->recentInvoices as $inv)
                    <flux:select.option :value="$inv->id">
                        {{ $inv->invoice_no }} — {{ $inv->contact?->display_name }} ({{ $inv->invoice_date->toDateString() }})
                    </flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-contact-combo
                :label="__('Customer')"
                :placeholder="__('Search or type to add a new customer…')"
                :add-label="__('customer')"
                :options="$this->customers"
                :selected-id="$contact_id"
                :selected-name="$this->selectedContactName"
                :query="$contact_query"
                :creating="$contact_creating"
                :new-name="$new_contact_name"
                data-test="receipt-customer-combo"
            />

            <flux:input wire:model="receipt_no" :label="__('Receipt #')" required data-test="receipt-no-input" />
            <flux:input type="date" wire:model="receipt_date" :label="__('Date')" required data-test="receipt-date-input" />
            <flux:input wire:model.live="amount" :label="__('Amount')" placeholder="0.00" required data-test="receipt-amount-input" />

            <flux:select wire:model="deposit_to_account_id" :label="__('Deposit to')" required data-test="receipt-deposit-select">
                <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                @foreach ($this->depositToOptions as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="payment_method_id" :label="__('Payment method')" data-test="receipt-method-select">
                <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                @foreach ($this->paymentMethodOptions as $m)
                    <flux:select.option :value="$m->id">{{ $m->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="reference" :label="__('Reference')" :placeholder="$company->jurisdiction->chequeLabel('ref')" />
        </div>

        <flux:input wire:model="memo" :label="__('Memo')" />

        @if ($this->creditSummary)
            @php($summary = $this->creditSummary)
            <div class="rounded-lg border border-emerald-300 bg-emerald-50 p-4 dark:border-emerald-700/60 dark:bg-emerald-950/30" data-test="receipt-credit-summary">
                <div class="flex items-start gap-3">
                    <flux:icon name="receipt-refund" class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                    <div class="flex-1">
                        <flux:text class="font-medium text-emerald-800 dark:text-emerald-200">
                            {{ __('This customer has :amount in available credit.', ['amount' => number_format($summary['credit'] / 100, 2)]) }}
                            <flux:link
                                :href="route('reports.contact-statement', ['company' => $company->slug, 'contact' => $contact_id, 'kind' => 'ar'])"
                                wire:navigate
                                data-test="receipt-credit-statement-link"
                            >{{ __('See AR Statement') }}</flux:link>
                        </flux:text>
                        <flux:text class="mt-1 text-sm text-emerald-700/90 dark:text-emerald-300/90">
                            {{ __('Credit memos reduce the customer\'s overall balance, not a single invoice — so an invoice can still show its full amount.') }}
                        </flux:text>
                        <div class="mt-2 grid grid-cols-3 gap-3 text-sm">
                            <div>
                                <div class="text-muted-foreground">{{ __('Open invoices') }}</div>
                                <div class="font-mono" data-test="receipt-credit-open">{{ number_format($summary['open_invoices'] / 100, 2) }}</div>
                            </div>
                            <div>
                                <div class="text-muted-foreground">{{ __('Available credit') }}</div>
                                <div class="font-mono">({{ number_format($summary['credit'] / 100, 2) }})</div>
                            </div>
                            <div>
                                <div class="text-muted-foreground">{{ __('Net balance') }}</div>
                                <div class="font-mono font-semibold" data-test="receipt-credit-net">{{ number_format($summary['net'] / 100, 2) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if (! empty($applyTable))
            <div>
                <flux:heading class="mb-2">{{ __('Apply to invoices') }}</flux:heading>
                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted">
                            <tr>
                                <th class="px-3 py-2 text-left">{{ __('Invoice') }}</th>
                                <th class="px-3 py-2 text-left">{{ __('Due') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Open balance') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Apply') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($applyTable as $i => $row)
                                <tr wire:key="apply-{{ $row['invoice_id'] }}" data-test="apply-row">
                                    <td class="px-3 py-2 font-mono">{{ $row['invoice_no'] }}</td>
                                    <td class="px-3 py-2">{{ $row['due_date'] }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ number_format($row['balance'] / 100, 2) }}</td>
                                    <td class="px-3 py-2">
                                        <flux:input wire:model.live="applyTable.{{ $i }}.apply" class="text-right" data-test="apply-input" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-muted">
                            <tr>
                                <td colspan="3" class="px-3 py-2 text-right font-medium">{{ __('Total applied') }}</td>
                                <td class="px-3 py-2 text-right font-mono" data-test="total-applied">{{ number_format($this->totalApplied() / 100, 2) }}</td>
                            </tr>
                            @if ($this->unappliedCents() > 0)
                                <tr class="text-amber-700 dark:text-amber-400">
                                    <td colspan="3" class="px-3 py-2 text-right font-medium">{{ __('Unapplied — left as customer credit') }}</td>
                                    <td class="px-3 py-2 text-right font-mono" data-test="receipt-unapplied">{{ number_format($this->unappliedCents() / 100, 2) }}</td>
                                </tr>
                            @endif
                        </tfoot>
                    </table>
                </div>

                @if ($this->unappliedCents() > 0)
                    <flux:text class="mt-2 text-sm text-amber-700 dark:text-amber-400" data-test="receipt-unapplied-note">
                        {{ __(':amount of this payment isn\'t applied to an invoice and will sit as an on-account credit for the customer. Increase an Apply amount, or lower the receipt Amount, to clear it.', ['amount' => '$'.number_format($this->unappliedCents() / 100, 2)]) }}
                    </flux:text>
                @endif

                @error('applyTable') <flux:text class="mt-2 text-red-600">{{ $message }}</flux:text> @enderror
            </div>
        @endif

        @error('amount') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror

        <div class="flex justify-end gap-2">
            <flux:button variant="primary" type="submit" data-test="save-receipt-button">{{ __('Save & post') }}</flux:button>
        </div>
    </form>
</section>
