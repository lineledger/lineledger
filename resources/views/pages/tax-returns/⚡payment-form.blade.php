<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\TaxReturnPaymentDirection;
use App\Enums\TaxReturnStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\TaxReturn;
use App\Models\TaxReturnPayment;
use App\Rules\MoneyString;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\TaxReturnPaymentPoster;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Record tax payment')] class extends Component {
    public Company $company;

    public TaxReturn $taxReturn;

    public string $direction = '';

    public string $payment_no = '';

    public string $payment_date = '';

    public ?int $bank_account_id = null;

    public ?int $payment_method_id = null;

    public string $reference = '';

    public string $net_amount = '0.00';

    public string $penalty = '0.00';

    public ?int $penalty_account_id = null;

    public string $interest = '0.00';

    public ?int $interest_account_id = null;

    public string $commission = '0.00';

    public ?int $commission_account_id = null;

    public string $notes = '';

    public function mount(Company $company, TaxReturn $tax_return): void
    {
        $this->company = $company;

        abort_if($tax_return->status !== TaxReturnStatus::Filed, 403, 'Payments can only be recorded against filed tax returns.');

        $this->taxReturn = $tax_return->load('taxAgency.payableAccount');

        $this->direction = ($this->taxReturn->net_cents >= 0
            ? TaxReturnPaymentDirection::Outgoing
            : TaxReturnPaymentDirection::Incoming)->value;

        $this->payment_date = $this->company->currentDateTime()->toDateString();
        $this->payment_no = app(DocumentNumberGenerator::class)
            ->next($company, TaxReturnPayment::class, 'payment_no', 'TRP');

        $this->net_amount = Money::fromCents(abs((int) $this->taxReturn->net_cents))->toDecimalString();

        $defaultBank = Account::query()
            ->where('subtype', AccountSubtype::Bank->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->first();
        $this->bank_account_id = $defaultBank?->id;
    }

    public function directionEnum(): TaxReturnPaymentDirection
    {
        return TaxReturnPaymentDirection::from($this->direction);
    }

    public function isOutgoing(): bool
    {
        return $this->directionEnum() === TaxReturnPaymentDirection::Outgoing;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Account>
     */
    #[Computed]
    public function bankAccounts()
    {
        return Account::query()
            ->where('subtype', AccountSubtype::Bank->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Account>
     */
    #[Computed]
    public function expenseAccounts()
    {
        return Account::query()
            ->where('type', AccountType::Expense->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Account>
     */
    #[Computed]
    public function incomeAccounts()
    {
        return Account::query()
            ->where('type', AccountType::Income->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, PaymentMethod>
     */
    #[Computed]
    public function paymentMethods()
    {
        return PaymentMethod::query()->where('is_active', true)->orderBy('name')->get();
    }

    public function totalCents(): int
    {
        $net = Money::tryFromString((string) $this->net_amount)?->cents ?? 0;
        $penalty = $this->isOutgoing() ? (Money::tryFromString((string) $this->penalty)?->cents ?? 0) : 0;
        $interest = Money::tryFromString((string) $this->interest)?->cents ?? 0;
        $commission = $this->isOutgoing() ? (Money::tryFromString((string) $this->commission)?->cents ?? 0) : 0;

        return $this->isOutgoing()
            ? $net + $penalty + $interest + $commission
            : $net + $interest;
    }

    public function save(TaxReturnPaymentPoster $poster): void
    {
        $rules = [
            'payment_no' => [
                'required', 'string', 'max:40',
                Rule::unique('tax_return_payments', 'payment_no')->where('company_id', $this->company->id),
            ],
            'payment_date' => ['required', 'date'],
            'bank_account_id' => [
                'required',
                Rule::exists('accounts', 'id')->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Bank->value),
            ],
            'payment_method_id' => ['nullable', Rule::exists('payment_methods', 'id')->where('company_id', $this->company->id)],
            'reference' => ['nullable', 'string', 'max:120'],
            'net_amount' => ['required', 'string', new MoneyString],
            'penalty' => ['required', 'string', new MoneyString],
            'interest' => ['required', 'string', new MoneyString],
            'commission' => ['required', 'string', new MoneyString],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        $this->validate($rules);

        $netCents = Money::fromString($this->net_amount)->cents;
        $penaltyCents = $this->isOutgoing() ? Money::fromString($this->penalty)->cents : 0;
        $interestCents = Money::fromString($this->interest)->cents;
        $commissionCents = $this->isOutgoing() ? Money::fromString($this->commission)->cents : 0;

        if ($penaltyCents > 0 && ! $this->penalty_account_id) {
            $this->addError('penalty_account_id', __('Choose an account for the penalty amount.'));

            return;
        }

        if ($interestCents > 0 && ! $this->interest_account_id) {
            $this->addError('interest_account_id', __('Choose an account for the interest amount.'));

            return;
        }

        if ($commissionCents > 0 && ! $this->commission_account_id) {
            $this->addError('commission_account_id', __('Choose an account for the commission amount.'));

            return;
        }

        $payment = app(\App\Actions\Tax\SaveTaxReturnPayment::class)->handle([
            'tax_return_id' => $this->taxReturn->id,
            'payment_no' => $this->payment_no,
            'payment_date' => $this->payment_date,
            'direction' => $this->direction,
            'bank_account_id' => $this->bank_account_id,
            'payment_method_id' => $this->payment_method_id,
            'reference' => $this->reference ?: null,
            'net_amount_cents' => $netCents,
            'penalty_cents' => $penaltyCents,
            'penalty_account_id' => $this->penalty_account_id,
            'interest_cents' => $interestCents,
            'interest_account_id' => $this->interest_account_id,
            'commission_cents' => $commissionCents,
            'commission_account_id' => $this->commission_account_id,
            'notes' => $this->notes ?: null,
        ]);

        try {
            $poster->post($payment);
        } catch (\RuntimeException $e) {
            // Roll back the draft so the unique payment_no isn't burned.
            $payment->forceDelete();
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Tax payment recorded.'));
        $this->redirectRoute('tax-returns.payments.show', [
            'company' => $this->company->slug,
            'tax_return' => $this->taxReturn->id,
            'payment' => $payment->id,
        ], navigate: true);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">
                @if ($this->isOutgoing())
                    {{ __('Record tax payment') }}
                @else
                    {{ __('Record tax refund') }}
                @endif
            </flux:heading>
            <flux:subheading>
                {{ $taxReturn->taxAgency->name }} — {{ $taxReturn->tax_return_no }}
                ({{ $taxReturn->period_start->toDateString() }} → {{ $taxReturn->period_end->toDateString() }})
            </flux:subheading>
        </div>
        <flux:button variant="ghost" :href="route('tax-returns.show', ['company' => $company->slug, 'tax_return' => $taxReturn->id])" wire:navigate>{{ __('Cancel') }}</flux:button>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
        <flux:input :label="__('Payment #')" wire:model="payment_no" data-test="payment-no-input" />
        <flux:input :label="__('Payment date')" type="date" wire:model="payment_date" data-test="payment-date-input" />

        <flux:select wire:model="bank_account_id" :label="__('Bank account')" data-test="bank-account-select">
            <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
            @foreach ($this->bankAccounts as $account)
                <flux:select.option :value="$account->id">{{ $account->code }} — {{ $account->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model="payment_method_id" :label="__('Payment method')">
            <flux:select.option value="">{{ __('—') }}</flux:select.option>
            @foreach ($this->paymentMethods as $pm)
                <flux:select.option :value="$pm->id">{{ $pm->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="mt-4">
        <flux:input :label="__('Reference (optional)')" wire:model="reference" placeholder="{{ __('Confirmation # or tracking #') }}" />
    </div>

    <flux:separator class="my-6" />

    <div class="space-y-4">
        <flux:heading size="lg">{{ __('Amounts') }}</flux:heading>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:input
                :label="$this->isOutgoing() ? __('Net tax payment') : __('Net tax refund')"
                wire:model.live="net_amount"
                data-test="net-amount-input"
            />

            @if ($this->isOutgoing())
                <flux:input :label="__('Penalty')" wire:model.live="penalty" data-test="penalty-input" />
                @if (((float) $penalty) > 0)
                    <flux:select wire:model="penalty_account_id" :label="__('Penalty account')" data-test="penalty-account-select">
                        <flux:select.option value="">{{ __('Choose expense account…') }}</flux:select.option>
                        @foreach ($this->expenseAccounts as $a)
                            <flux:select.option :value="$a->id">{{ $a->code }} — {{ $a->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:input :label="__('Interest paid')" wire:model.live="interest" data-test="interest-input" />
                @if (((float) $interest) > 0)
                    <flux:select wire:model="interest_account_id" :label="__('Interest account')" data-test="interest-account-select">
                        <flux:select.option value="">{{ __('Choose expense account…') }}</flux:select.option>
                        @foreach ($this->expenseAccounts as $a)
                            <flux:select.option :value="$a->id">{{ $a->code }} — {{ $a->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:input :label="__('Commission / processing fee')" wire:model.live="commission" data-test="commission-input" />
                @if (((float) $commission) > 0)
                    <flux:select wire:model="commission_account_id" :label="__('Commission account')" data-test="commission-account-select">
                        <flux:select.option value="">{{ __('Choose expense account…') }}</flux:select.option>
                        @foreach ($this->expenseAccounts as $a)
                            <flux:select.option :value="$a->id">{{ $a->code }} — {{ $a->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            @else
                <flux:input :label="__('Interest received')" wire:model.live="interest" data-test="interest-input" />
                @if (((float) $interest) > 0)
                    <flux:select wire:model="interest_account_id" :label="__('Interest income account')" data-test="interest-account-select">
                        <flux:select.option value="">{{ __('Choose income account…') }}</flux:select.option>
                        @foreach ($this->incomeAccounts as $a)
                            <flux:select.option :value="$a->id">{{ $a->code }} — {{ $a->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            @endif
        </div>
    </div>

    <flux:textarea :label="__('Notes')" wire:model="notes" rows="2" class="mt-4" />

    <div class="mt-6 flex items-center justify-between gap-4">
        <div class="text-sm">
            <span class="text-muted-foreground">{{ __('Total moving through bank') }}:</span>
            <span class="ml-2 text-xl font-mono font-semibold" data-test="payment-total">{{ number_format($this->totalCents() / 100, 2) }}</span>
        </div>
        <flux:button variant="primary" wire:click="save" wire:confirm="{{ __('Record this payment? It posts immediately to the GL.') }}" data-test="record-payment-button">
            {{ $this->isOutgoing() ? __('Record payment') : __('Record refund') }}
        </flux:button>
    </div>
</section>
