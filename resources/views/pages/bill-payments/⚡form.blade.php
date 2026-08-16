<?php

use App\Enums\AccountSubtype;
use App\Enums\BillPaymentStatus;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\PaymentMethod;
use App\Actions\Purchasing\SaveBillPayment;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\DocumentNumberGenerator;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pay bills')] class extends Component {
    public Company $company;

    public ?BillPayment $payment = null;

    public string $contactRole = 'vendor'; // vendor | employee — toggles the contact list

    public ?int $contact_id = null;

    public string $payment_no = '';

    public string $payment_date = '';

    public ?int $paid_from_account_id = null;

    public ?int $payment_method_id = null;

    public string $reference = '';

    public string $memo = '';

    /**
     * @var array<int, array{bill_id: int, bill_no: string, due_date: string, balance: int, apply: string}>
     */
    public array $applyTable = [];

    public function mount(Company $company, ?BillPayment $payment = null): void
    {
        $this->company = $company;

        if ($payment && $payment->exists) {
            abort_if($payment->status === BillPaymentStatus::Void, 403, 'Voided payments cannot be edited.');

            $this->payment = $payment->load('applications.bill');
            $this->contactRole = $payment->payment_type === BillType::Reimbursement ? 'employee' : 'vendor';
            $this->contact_id = $payment->contact_id;
            $this->payment_no = $payment->payment_no;
            $this->payment_date = $payment->payment_date->toDateString();
            $this->paid_from_account_id = $payment->paid_from_account_id;
            $this->payment_method_id = $payment->payment_method_id;
            $this->reference = $payment->reference ?? '';
            $this->memo = $payment->memo ?? '';

            $this->refreshApplyTable();

            return;
        }

        $this->payment_date = $this->company->currentDateTime()->toDateString();
        $this->payment_no = app(DocumentNumberGenerator::class)->next($company, BillPayment::class, 'payment_no', 'PAY');

        $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->first();
        $this->paid_from_account_id = $bank?->id;

        if (request('bill')) {
            $bill = Bill::find((int) request('bill'));
            if ($bill) {
                $this->contactRole = $bill->bill_type === BillType::Reimbursement ? 'employee' : 'vendor';
                $this->contact_id = $bill->contact_id;
                $this->refreshApplyTable();
            }
        }
    }

    public function updatedContactRole(): void
    {
        $this->contact_id = null;
        $this->applyTable = [];
    }

    public function updatedContactId(): void
    {
        $this->refreshApplyTable();

        // Default the memo to the supplier's account number so it prints on the
        // payment cheque (QuickBooks behaviour). The user can still overwrite it.
        if ($this->memo === '' && $this->contact_id && $contact = Contact::find($this->contact_id)) {
            if ($contact->account_no) {
                $this->memo = $contact->account_no;
            }
        }
    }

    protected function refreshApplyTable(): void
    {
        $this->applyTable = [];

        if (! $this->contact_id) {
            return;
        }

        // When editing for the same contact, pre-fill existing applications
        // so the user sees what was previously paid against each bill.
        $existing = collect();
        $editingSameContact = $this->payment?->exists && $this->payment->contact_id === $this->contact_id;

        if ($editingSameContact) {
            $existing = $this->payment->applications->keyBy('bill_id');
        }

        $billType = $this->contactRole === 'employee' ? BillType::Reimbursement : BillType::Vendor;

        $bills = Bill::query()
            ->where('contact_id', $this->contact_id)
            ->where('bill_type', $billType)
            ->where(function ($q) use ($existing) {
                $q->whereIn('status', [BillStatus::Posted->value, BillStatus::Partial->value]);
                if ($existing->isNotEmpty()) {
                    $q->orWhereIn('id', $existing->keys()->all());
                }
            })
            ->orderBy('due_date')
            ->get();

        foreach ($bills as $b) {
            $existingApplied = (int) ($existing->get($b->id)->amount_cents ?? 0);

            $this->applyTable[] = [
                'bill_id' => $b->id,
                'bill_no' => $b->bill_no,
                'due_date' => $b->due_date->toDateString(),
                'balance' => $b->balanceCents() + $existingApplied,
                'apply' => number_format($existingApplied / 100, 2, '.', ''),
            ];
        }
    }

    public function save(BillPaymentPoster $poster, bool $print = false): void
    {
        $companyId = $this->company->id;

        $roleFilter = $this->contactRole === 'employee' ? 'is_employee' : 'is_vendor';

        $validated = $this->validate([
            'contact_id' => ['required', 'integer', Rule::exists('contacts', 'id')->where('company_id', $companyId)->where($roleFilter, true)],
            'payment_no' => ['required', 'string', 'max:40'],
            'payment_date' => ['required', 'date'],
            'paid_from_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')->where('company_id', $companyId)],
            'reference' => [$this->isChequeMethod() ? 'required' : 'nullable', 'string', 'max:100'],
            'memo' => ['nullable', 'string'],
        ], [
            'reference.required' => __('Cheque # is required when paying by cheque.'),
        ]);

        $applications = [];
        $totalCents = 0;

        foreach ($this->applyTable as $row) {
            $applyCents = Money::tryFromString((string) $row['apply'])?->cents ?? 0;

            if ($applyCents <= 0) {
                continue;
            }

            if ($applyCents > $row['balance']) {
                $this->addError('applyTable', __('Cannot apply more than the bill balance.'));

                return;
            }

            $applications[] = ['bill_id' => $row['bill_id'], 'amount_cents' => $applyCents];
            $totalCents += $applyCents;
        }

        if ($totalCents <= 0) {
            $this->addError('applyTable', __('Enter an amount to apply to at least one bill.'));

            return;
        }

        $billType = $this->contactRole === 'employee' ? BillType::Reimbursement : BillType::Vendor;
        $wasPosted = $this->payment?->journal_entry_id !== null;

        $payment = app(SaveBillPayment::class)->handle([
            'contact_id' => $validated['contact_id'],
            'payment_type' => $billType->value,
            'payment_no' => $validated['payment_no'],
            'payment_date' => $validated['payment_date'],
            'paid_from_account_id' => $validated['paid_from_account_id'],
            'payment_method_id' => $validated['payment_method_id'] ?: null,
            'reference' => $validated['reference'] ?: null,
            'amount_cents' => $totalCents,
            'memo' => $validated['memo'] ?: null,
            'applications' => $applications,
        ], $this->payment);

        try {
            $wasPosted ? $poster->repost($payment) : $poster->post($payment);
        } catch (PeriodLockedException|\RuntimeException $e) {
            $this->addError('applyTable', $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: $wasPosted ? __('Payment updated.') : __('Payment recorded.'));

        if ($print && $this->isChequeMethod()) {
            // PDF response — needs a hard navigation, not Livewire's SPA navigate.
            $this->redirect(route('bill-payments.print-cheque', ['company' => $this->company->slug, 'payment' => $payment->id]));

            return;
        }

        $this->redirectRoute('bill-payments.show', ['company' => $this->company->slug, 'payment' => $payment->id], navigate: true);
    }

    public function saveAndPrint(BillPaymentPoster $poster): void
    {
        $this->save($poster, print: true);
    }

    public function isChequeMethod(): bool
    {
        if (! $this->payment_method_id) {
            return false;
        }

        return (bool) PaymentMethod::query()->whereKey($this->payment_method_id)->value('is_cheque');
    }

    #[Computed]
    public function contactOptions()
    {
        $field = $this->contactRole === 'employee' ? 'is_employee' : 'is_vendor';

        return Contact::query()->where($field, true)->where('is_active', true)->orderBy('display_name')->get(['id', 'display_name']);
    }

    #[Computed]
    public function bankAccountOptions()
    {
        // Active bank + credit-card accounts (a card-funded bill payment increases
        // the card liability), plus the one already selected so editing a payment
        // never drops a since-deactivated account.
        return Account::query()
            ->where(function ($q) {
                $q->where(fn ($inner) => $inner->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])->where('is_active', true));

                if ($this->paid_from_account_id) {
                    $q->orWhere('id', $this->paid_from_account_id);
                }
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'subtype']);
    }

    #[Computed]
    public function paymentMethodOptions()
    {
        return PaymentMethod::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'is_cheque']);
    }

    public function totalApplied(): int
    {
        $t = 0;
        foreach ($this->applyTable as $row) {
            try {
                $t += Money::fromString($row['apply'] === '' ? '0' : $row['apply'])->cents;
            } catch (\Throwable) {
            }
        }

        return $t;
    }

    /**
     * Outstanding credit for the selected vendor, with the open-bill total and net
     * balance, so the user can see why a bill's own balance differs from what is
     * actually owed the vendor. The mirror of the Receive payment credit summary:
     * vendor credits (and overpayments) net through the AP control account rather
     * than against a single bill. Null when no contact or no credit.
     *
     * @return array{credit: int, open_bills: int, net: int}|null
     */
    #[Computed]
    public function creditSummary(): ?array
    {
        if (! $this->contact_id) {
            return null;
        }

        $openBills = array_sum(array_column($this->applyTable, 'balance'));

        // The vendor's existing on-account credit = the gap between their open bills
        // and their true GL AP balance (vendor credits + unapplied payments + GL credits).
        // AP is credit-normal, so the balance is summed credit − debit (opposite of AR).
        // Same figure AP Aging uses; floored so a net-credit vendor reads $0 owing.
        $apAccountIds = Account::query()
            ->where('company_id', $this->company->id)
            ->where('subtype', AccountSubtype::AccountsPayable->value)
            ->pluck('id');

        $glAp = $apAccountIds->isEmpty() ? 0 : (int) DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $this->company->id)
            ->where('je.is_posted', true)
            ->whereIn('jl.account_id', $apAccountIds)
            ->where('jl.contact_id', $this->contact_id)
            ->sum(DB::raw('jl.credit_cents - jl.debit_cents'));

        $credit = max(0, $openBills - max(0, $glAp));

        if ($credit <= 0) {
            return null;
        }

        return [
            'credit' => $credit,
            'open_bills' => $openBills,
            'net' => $openBills - $credit,
        ];
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1" class="mb-6">{{ __('Pay bills') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:select wire:model.live="contactRole" :label="__('Pay to')">
                <flux:select.option value="vendor">{{ __('Vendor') }}</flux:select.option>
                <flux:select.option value="employee">{{ __('Employee (reimbursement)') }}</flux:select.option>
            </flux:select>

            <flux:select wire:model.live="contact_id" :label="$contactRole === 'employee' ? __('Employee') : __('Vendor')" required data-test="payment-contact-select">
                <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                @foreach ($this->contactOptions as $c)
                    <flux:select.option :value="$c->id">{{ $c->display_name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="payment_no" :label="__('Payment #')" required data-test="payment-no-input" />
            <flux:input type="date" wire:model="payment_date" :label="__('Date')" required />

            <flux:select wire:model="paid_from_account_id" :label="__('Pay from')" required data-test="payment-from-select">
                <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                @foreach ($this->bankAccountOptions as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}@if ($opt->subtype === AccountSubtype::CreditCard) ({{ __('Credit card') }})@endif</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="payment_method_id" :label="__('Method')" data-test="payment-method-select">
                <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                @foreach ($this->paymentMethodOptions as $m)
                    <flux:select.option :value="$m->id">{{ $m->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="reference" :label="$this->isChequeMethod() ? __('Cheque #') : __('Reference')" :required="$this->isChequeMethod()" :placeholder="$this->isChequeMethod() ? __('e.g. 1023') : ''" data-test="payment-reference-input" />
        </div>

        <flux:input wire:model="memo" :label="__('Memo')" />

        @if ($this->creditSummary)
            @php($creditNoun = $contactRole === 'employee' ? __('employee') : __('vendor'))
            <div class="rounded-lg border border-emerald-300 bg-emerald-50 p-4 dark:border-emerald-700/60 dark:bg-emerald-950/30" data-test="payment-credit-summary">
                <div class="flex items-start gap-3">
                    <flux:icon name="receipt-refund" class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                    <div class="flex-1">
                        <flux:text class="font-medium text-emerald-800 dark:text-emerald-200">
                            {{ __('This :who has :amount in available credit.', ['who' => $creditNoun, 'amount' => number_format($this->creditSummary['credit'] / 100, 2)]) }}
                            <flux:link
                                :href="route('reports.contact-statement', ['company' => $company->slug, 'contact' => $contact_id, 'kind' => 'ap'])"
                                wire:navigate
                                data-test="payment-credit-statement-link"
                            >{{ __('See AP Statement') }}</flux:link>
                        </flux:text>
                        <flux:text class="mt-1 text-sm text-emerald-700/90 dark:text-emerald-300/90">
                            {{ __('Credits reduce the :who\'s overall balance, not a single bill — so a bill can still show its full amount.', ['who' => $creditNoun]) }}
                        </flux:text>
                        <div class="mt-2 grid grid-cols-3 gap-3 text-sm">
                            <div>
                                <div class="text-muted-foreground">{{ __('Open bills') }}</div>
                                <div class="font-mono" data-test="payment-credit-open">{{ number_format($this->creditSummary['open_bills'] / 100, 2) }}</div>
                            </div>
                            <div>
                                <div class="text-muted-foreground">{{ __('Available credit') }}</div>
                                <div class="font-mono">({{ number_format($this->creditSummary['credit'] / 100, 2) }})</div>
                            </div>
                            <div>
                                <div class="text-muted-foreground">{{ __('Net balance') }}</div>
                                <div class="font-mono font-semibold" data-test="payment-credit-net">{{ number_format($this->creditSummary['net'] / 100, 2) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if (! empty($applyTable))
            <div>
                <flux:heading class="mb-2">{{ __('Open bills') }}</flux:heading>
                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted">
                            <tr>
                                <th class="px-3 py-2 text-left">{{ __('Bill #') }}</th>
                                <th class="px-3 py-2 text-left">{{ __('Due') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Open balance') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('Apply') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($applyTable as $i => $row)
                                <tr wire:key="apply-{{ $row['bill_id'] }}" data-test="apply-bill-row">
                                    <td class="px-3 py-2 font-mono">{{ $row['bill_no'] }}</td>
                                    <td class="px-3 py-2">{{ $row['due_date'] }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ number_format($row['balance'] / 100, 2) }}</td>
                                    <td class="px-3 py-2">
                                        <flux:input wire:model.live="applyTable.{{ $i }}.apply" class="text-right" data-test="apply-bill-input" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-muted">
                            <tr>
                                <td colspan="3" class="px-3 py-2 text-right font-medium">{{ __('Payment total') }}</td>
                                <td class="px-3 py-2 text-right font-mono" data-test="payment-total">{{ number_format($this->totalApplied() / 100, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                @error('applyTable') <flux:text class="mt-2 text-red-600">{{ $message }}</flux:text> @enderror
            </div>
        @elseif ($contact_id)
            <flux:text class="py-4 text-center text-muted-foreground">{{ __('No open bills for this contact.') }}</flux:text>
        @endif

        <div class="flex justify-end gap-2">
            @if ($this->isChequeMethod())
                <flux:button variant="filled" type="button" wire:click="saveAndPrint" icon="printer" data-test="save-and-print-cheque-button">
                    {{ __('Save, post & print cheque') }}
                </flux:button>
            @endif
            <flux:button variant="primary" type="submit" data-test="save-payment-button">{{ __('Save & post') }}</flux:button>
        </div>
    </form>
</section>
