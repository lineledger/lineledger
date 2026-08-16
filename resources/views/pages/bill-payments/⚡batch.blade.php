<?php

use App\Actions\Purchasing\SaveBillPayment;
use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Services\Posting\BillPaymentPoster;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pay bills')] class extends Component
{
    public Company $company;

    public string $payment_date = '';

    public ?int $paid_from_account_id = null;

    public ?int $payment_method_id = null;

    public string $memo = '';

    /**
     * One row per open vendor bill across all suppliers.
     *
     * @var array<int, array{bill_id: int, contact_id: int, vendor_name: string, bill_no: string, due_date: string, balance: int, apply: string}>
     */
    public array $rows = [];

    public function mount(Company $company): void
    {
        $this->company = $company;
        $this->payment_date = $company->currentDateTime()->toDateString();

        $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->first();
        $this->paid_from_account_id = $bank?->id;

        $this->loadOpenBills();
    }

    protected function loadOpenBills(): void
    {
        $this->rows = [];

        $bills = Bill::query()
            ->with('contact:id,display_name')
            ->where('bill_type', BillType::Vendor->value)
            ->whereIn('status', [BillStatus::Posted->value, BillStatus::Partial->value])
            ->orderBy('contact_id')
            ->orderBy('due_date')
            ->get();

        foreach ($bills as $b) {
            $this->rows[] = [
                'bill_id' => $b->id,
                'contact_id' => (int) $b->contact_id,
                'vendor_name' => $b->contact?->display_name ?? '—',
                'bill_no' => $b->bill_no,
                'due_date' => $b->due_date->toDateString(),
                'balance' => $b->balanceCents(),
                'apply' => '',
            ];
        }
    }

    public function payInFull(int $i): void
    {
        $this->rows[$i]['apply'] = number_format($this->rows[$i]['balance'] / 100, 2, '.', '');
    }

    public function totalApplied(): int
    {
        $t = 0;
        foreach ($this->rows as $row) {
            $t += Money::tryFromString((string) $row['apply'])?->cents ?? 0;
        }

        return $t;
    }

    public function pay(BillPaymentPoster $poster): void
    {
        $companyId = $this->company->id;

        $validated = $this->validate([
            'payment_date' => ['required', 'date'],
            'paid_from_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')->where('company_id', $companyId)],
            'memo' => ['nullable', 'string'],
        ]);

        // Group valid applications by vendor — one BillPayment is created per vendor.
        $byVendor = [];
        foreach ($this->rows as $row) {
            $applyCents = Money::tryFromString((string) $row['apply'])?->cents ?? 0;

            if ($applyCents <= 0) {
                continue;
            }

            if ($applyCents > $row['balance']) {
                $this->addError('rows', __('Cannot apply more than the bill balance for :no.', ['no' => $row['bill_no']]));

                return;
            }

            $byVendor[$row['contact_id']][] = ['bill_id' => $row['bill_id'], 'amount_cents' => $applyCents];
        }

        if ($byVendor === []) {
            $this->addError('rows', __('Enter an amount to apply to at least one bill.'));

            return;
        }

        $count = 0;

        try {
            DB::transaction(function () use ($validated, $byVendor, $poster, &$count) {
                foreach ($byVendor as $contactId => $applications) {
                    $total = array_sum(array_column($applications, 'amount_cents'));

                    $payment = app(SaveBillPayment::class)->handle([
                        'contact_id' => (int) $contactId,
                        'payment_type' => BillType::Vendor->value,
                        'payment_date' => $validated['payment_date'],
                        'paid_from_account_id' => $validated['paid_from_account_id'],
                        'payment_method_id' => $validated['payment_method_id'] ?: null,
                        'amount_cents' => $total,
                        'memo' => $validated['memo'] ?: null,
                        'applications' => $applications,
                    ]);

                    $poster->post($payment);
                    $count++;
                }
            });
        } catch (PeriodLockedException|RuntimeException $e) {
            $this->addError('rows', $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __(':count payment(s) recorded.', ['count' => $count]));
        $this->redirectRoute('bill-payments.index', ['company' => $this->company->slug], navigate: true);
    }

    #[Computed]
    public function bankAccountOptions()
    {
        return Account::query()
            ->where(function ($q) {
                $q->where(fn ($inner) => $inner->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])->where('is_active', true));

                if ($this->paid_from_account_id) {
                    $q->orWhere('id', $this->paid_from_account_id);
                }
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function paymentMethodOptions()
    {
        return PaymentMethod::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }
}; ?>

<section class="w-full">
    <div class="mb-6">
        <flux:heading size="xl" level="1">{{ __('Pay bills') }}</flux:heading>
        <flux:subheading>{{ __('Pay open bills across all suppliers at once. One payment is created per supplier.') }}</flux:subheading>
    </div>

    <form wire:submit="pay" class="space-y-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <flux:select wire:model="paid_from_account_id" :label="__('Pay from')" required data-test="batch-account-select">
                <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                @foreach ($this->bankAccountOptions as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input type="date" wire:model="payment_date" :label="__('Payment date')" required />

            <flux:select wire:model="payment_method_id" :label="__('Payment method')">
                <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                @foreach ($this->paymentMethodOptions as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <flux:input wire:model="memo" :label="__('Memo')" />

        @error('rows') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Supplier') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Bill #') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Due date') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Balance') }}</th>
                        <th class="px-4 py-2 text-right w-32">{{ __('Payment') }}</th>
                        <th class="px-4 py-2 w-10"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($rows as $i => $row)
                        <tr wire:key="batch-row-{{ $row['bill_id'] }}" data-test="batch-bill-row">
                            <td class="px-4 py-2">{{ $row['vendor_name'] }}</td>
                            <td class="px-4 py-2 font-mono">{{ $row['bill_no'] }}</td>
                            <td class="px-4 py-2 whitespace-nowrap">{{ $row['due_date'] }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($row['balance'] / 100, 2) }}</td>
                            <td class="px-4 py-2">
                                <x-amount-input model="rows.{{ $i }}.apply" class="text-right" data-test="batch-apply-input" />
                            </td>
                            <td class="px-2 py-2 text-right">
                                <flux:button variant="ghost" size="sm" type="button" wire:click="payInFull({{ $i }})" data-test="batch-pay-full">{{ __('Full') }}</flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ __('No open bills.') }}</td></tr>
                    @endforelse
                </tbody>
                @if ($rows !== [])
                    <tfoot class="bg-muted">
                        <tr class="text-base">
                            <td colspan="4" class="px-4 py-2 text-right font-semibold">{{ __('Total payment') }}</td>
                            <td class="px-4 py-2 text-right font-mono font-semibold" data-test="batch-total">{{ number_format($this->totalApplied() / 100, 2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <div class="flex justify-end">
            <flux:button variant="primary" type="submit" data-test="batch-pay-button">{{ __('Record payments') }}</flux:button>
        </div>
    </form>
</section>
