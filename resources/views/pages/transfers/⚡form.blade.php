<?php

use App\Actions\Banking\SaveTransfer;
use App\Enums\AccountSubtype;
use App\Enums\TransferStatus;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\ReconciliationLockedException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Transfer;
use App\Rules\MoneyString;
use App\Services\Posting\TransferPoster;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Transfer')] class extends Component
{
    public Company $company;

    public ?Transfer $transfer = null;

    public ?int $from_account_id = null;

    public ?int $to_account_id = null;

    public string $transfer_no = '';

    public string $transfer_date = '';

    public string $from_amount = '0.00';

    public string $to_amount = '0.00';

    public string $memo = '';

    public function mount(Company $company, ?Transfer $transfer = null): void
    {
        $this->company = $company;

        if ($transfer && $transfer->exists) {
            abort_if($transfer->status === TransferStatus::Void, 403);
            abort_if($transfer->journal_entry_id, 403, 'Posted transfers cannot be edited. Void and re-create.');

            $this->transfer = $transfer;
            $this->from_account_id = $transfer->from_account_id;
            $this->to_account_id = $transfer->to_account_id;
            $this->transfer_no = $transfer->transfer_no;
            $this->transfer_date = $transfer->transfer_date->toDateString();
            $this->from_amount = Money::fromCents((int) $transfer->from_amount_cents)->toDecimalString();
            $this->to_amount = Money::fromCents((int) $transfer->to_amount_cents)->toDecimalString();
            $this->memo = $transfer->memo ?? '';
        } else {
            $this->transfer_date = $this->company->currentDateTime()->toDateString();
            $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->first();
            $this->from_account_id = $bank?->id;
        }
    }

    #[Computed]
    public function bankAccounts()
    {
        // Active bank + credit-card accounts (a transfer to a card pays it down),
        // plus the from/to already selected so editing a transfer never drops a
        // since-deactivated account from either select.
        $selected = array_filter([$this->from_account_id, $this->to_account_id]);

        return Account::query()
            ->where(function ($q) use ($selected) {
                $q->where(fn ($inner) => $inner->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])->where('is_active', true))
                    ->orWhere(fn ($inner) => $inner->where('use_in_transfers', true)->where('is_active', true));

                if ($selected !== []) {
                    $q->orWhereIn('id', $selected);
                }
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'currency_code', 'subtype']);
    }

    /**
     * Effective currency of an account (the company home currency when the
     * account itself is null/home).
     */
    public function accountCurrency(?int $accountId): ?string
    {
        if (! $accountId) {
            return null;
        }

        $account = $this->bankAccounts->firstWhere('id', $accountId);

        return $account?->currency_code ?? $this->company->currency_code;
    }

    #[Computed]
    public function isCrossCurrency(): bool
    {
        if (! $this->from_account_id || ! $this->to_account_id) {
            return false;
        }

        return $this->accountCurrency($this->from_account_id) !== $this->accountCurrency($this->to_account_id);
    }

    public function saveDraft(): void
    {
        $this->persist();
        Flux::toast(variant: 'success', text: __('Draft saved.'));
        $this->redirectRoute('transfers.edit', ['company' => $this->company->slug, 'transfer' => $this->transfer->id], navigate: true);
    }

    public function postTransfer(TransferPoster $poster): void
    {
        $this->persist();

        try {
            $poster->post($this->transfer);
        } catch (PeriodLockedException|ReconciliationLockedException|RuntimeException $e) {
            $this->addError('from_amount', $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Transfer posted.'));
        $this->redirectRoute('transfers.show', ['company' => $this->company->slug, 'transfer' => $this->transfer->id], navigate: true);
    }

    protected function persist(): void
    {
        $companyId = $this->company->id;

        $bankRule = fn () => ['required', 'integer', Rule::exists('accounts', 'id')->where(function ($q) use ($companyId) {
            $q->where('company_id', $companyId)
                ->where(fn ($inner) => $inner->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
                    ->orWhere('use_in_transfers', true));
        })];

        $validated = $this->validate([
            'from_account_id' => $bankRule(),
            'to_account_id' => [...$bankRule(), 'different:from_account_id'],
            'transfer_date' => ['required', 'date'],
            'from_amount' => ['required', 'string', new MoneyString],
            'to_amount' => [$this->isCrossCurrency ? 'required' : 'nullable', 'string', new MoneyString],
            'memo' => ['nullable', 'string'],
        ]);

        $fromCents = Money::fromString($validated['from_amount'])->cents;
        $toCents = $this->isCrossCurrency
            ? Money::fromString($validated['to_amount'])->cents
            : $fromCents;

        $this->transfer = app(SaveTransfer::class)->handle([
            'from_account_id' => $validated['from_account_id'],
            'to_account_id' => $validated['to_account_id'],
            'transfer_no' => $this->transfer_no ?: null,
            'transfer_date' => $validated['transfer_date'],
            'from_amount_cents' => $fromCents,
            'to_amount_cents' => $toCents,
            'memo' => $validated['memo'] ?: null,
        ], $this->transfer);
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1" class="mb-6">{{ $transfer?->id ? __('Edit transfer') : __('New transfer') }}</flux:heading>

    <form wire:submit="postTransfer" class="space-y-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:select wire:model.live="from_account_id" :label="__('From account')" required data-test="transfer-from-select">
                <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                @foreach ($this->bankAccounts as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}@if ($opt->currency_code) ({{ $opt->currency_code }})@endif</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="to_account_id" :label="__('To account')" required data-test="transfer-to-select">
                <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                @foreach ($this->bankAccounts as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}@if ($opt->currency_code) ({{ $opt->currency_code }})@endif</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input type="date" wire:model="transfer_date" :label="__('Date')" required />

            @if ($transfer?->id)
                <flux:input wire:model="transfer_no" :label="__('Transfer #')" readonly />
            @endif
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-amount-input model="from_amount" modifiers="" :label="$this->isCrossCurrency ? __('Amount sent') . ' (' . $this->accountCurrency($from_account_id) . ')' : __('Amount')" data-test="transfer-amount-input" />

            @if ($this->isCrossCurrency)
                <x-amount-input model="to_amount" modifiers="" :label="__('Amount received') . ' (' . $this->accountCurrency($to_account_id) . ')'" data-test="transfer-to-amount-input" />
            @endif
        </div>

        @if ($this->isCrossCurrency)
            <flux:callout icon="information-circle" variant="secondary">
                {{ __('This is a cross-currency transfer. Any difference in home-currency value posts to Exchange Gain or Loss.') }}
            </flux:callout>
        @endif

        <flux:input wire:model="memo" :label="__('Memo')" />

        @error('from_amount') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror

        <div class="flex justify-end gap-2">
            <flux:button variant="filled" type="button" wire:click="saveDraft" data-test="save-draft-button">{{ __('Save draft') }}</flux:button>
            <flux:button variant="primary" type="submit" data-test="post-transfer-button">{{ __('Post transfer') }}</flux:button>
        </div>
    </form>
</section>
