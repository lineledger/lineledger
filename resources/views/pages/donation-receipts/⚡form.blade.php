<?php

use App\Actions\Charity\SaveDonationReceipt;
use App\Enums\AccountType;
use App\Enums\GiftType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DonationReceipt;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Donation receipt')] class extends Component {
    public Company $company;

    public ?DonationReceipt $receipt = null;

    public ?int $contact_id = null;

    public string $gift_type = 'cash';

    public string $gift_date = '';

    public string $amount = '';

    public string $advantage = '';

    public string $advantage_description = '';

    public string $in_kind_description = '';

    public string $appraised_by = '';

    public string $appraisal_date = '';

    public ?int $revenue_account_id = null;

    public ?int $debit_account_id = null;

    public string $notes = '';

    public function mount(Company $company, ?DonationReceipt $donationReceipt = null): void
    {
        $this->company = $company;

        abort_unless($company->isRegisteredCharity(), 403);

        if ($donationReceipt && $donationReceipt->exists) {
            abort_unless($donationReceipt->company_id === $company->id, 404);
            abort_unless($donationReceipt->isDraft(), 403, __('Only draft receipts can be edited.'));

            $this->receipt = $donationReceipt;
            $this->contact_id = $donationReceipt->contact_id;
            $this->gift_type = $donationReceipt->gift_type->value;
            $this->gift_date = $donationReceipt->gift_date?->toDateString() ?? '';
            $this->amount = $donationReceipt->amount_cents ? (string) Money::fromCents($donationReceipt->amount_cents) : '';
            $this->advantage = $donationReceipt->advantage_cents ? (string) Money::fromCents($donationReceipt->advantage_cents) : '';
            $this->advantage_description = (string) $donationReceipt->advantage_description;
            $this->in_kind_description = (string) $donationReceipt->in_kind_description;
            $this->appraised_by = (string) $donationReceipt->appraised_by;
            $this->appraisal_date = $donationReceipt->appraisal_date?->toDateString() ?? '';
            $this->revenue_account_id = $donationReceipt->revenue_account_id;
            $this->debit_account_id = $donationReceipt->debit_account_id;
            $this->notes = (string) $donationReceipt->notes;
        } else {
            $this->gift_date = $company->currentDateTime()->toDateString();
        }
    }

    #[Computed]
    public function donors()
    {
        return Contact::query()
            ->orderByDesc('is_donor')
            ->orderBy('display_name')
            ->get(['id', 'display_name', 'is_donor']);
    }

    #[Computed]
    public function incomeAccounts()
    {
        return Account::query()
            ->where('type', AccountType::Income->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function assetAccounts()
    {
        return Account::query()
            ->where('type', AccountType::Asset->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    public function save()
    {
        $validated = $this->validate([
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('company_id', $this->company->id)],
            'gift_type' => ['required', Rule::enum(GiftType::class)],
            'gift_date' => ['required', 'date'],
            'amount' => ['required', 'string'],
            'advantage' => ['nullable', 'string'],
            'advantage_description' => ['nullable', 'string', 'max:1000'],
            'in_kind_description' => ['nullable', 'string', 'max:1000'],
            'appraised_by' => ['nullable', 'string', 'max:255'],
            'appraisal_date' => ['nullable', 'date'],
            'revenue_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $this->company->id)],
            'debit_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $this->company->id)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $amountCents = Money::tryFromString($this->amount)?->cents ?? 0;
        $advantageCents = $this->advantage !== '' ? (Money::tryFromString($this->advantage)?->cents ?? 0) : 0;

        if ($amountCents <= 0) {
            $this->addError('amount', __('Enter a gift amount greater than zero.'));

            return;
        }

        $receipt = app(SaveDonationReceipt::class)->handle([
            'contact_id' => $validated['contact_id'] ?: null,
            'gift_type' => $validated['gift_type'],
            'gift_date' => $validated['gift_date'],
            'amount_cents' => $amountCents,
            'advantage_cents' => $advantageCents,
            'advantage_description' => $this->advantage_description ?: null,
            'in_kind_description' => $this->gift_type === GiftType::InKind->value ? ($this->in_kind_description ?: null) : null,
            'appraised_by' => $this->gift_type === GiftType::InKind->value ? ($this->appraised_by ?: null) : null,
            'appraisal_date' => $this->gift_type === GiftType::InKind->value ? ($this->appraisal_date ?: null) : null,
            'revenue_account_id' => $validated['revenue_account_id'] ?: null,
            'debit_account_id' => $this->gift_type === GiftType::InKind->value ? ($validated['debit_account_id'] ?: null) : null,
            'notes' => $this->notes ?: null,
        ], $this->receipt);

        Flux::toast(variant: 'success', text: __('Donation receipt saved.'));

        $this->redirectRoute('donation-receipts.show', ['company' => $this->company, 'donationReceipt' => $receipt], navigate: true);
    }
}; ?>

<section class="mx-auto w-full max-w-2xl">
    <flux:heading size="xl" level="1" data-test="page-title">{{ $receipt?->exists ? __('Edit donation receipt') : __('New donation receipt') }}</flux:heading>
    <flux:subheading class="mb-6">{{ __('Drafts can be edited until issued. Issuing locks the serial number.') }}</flux:subheading>

    <form wire:submit="save" class="space-y-6">
        <flux:select wire:model="contact_id" :label="__('Donor')" data-test="donation-receipt-contact">
            <flux:select.option value="">{{ __('No linked contact') }}</flux:select.option>
            @foreach ($this->donors as $donor)
                <flux:select.option value="{{ $donor->id }}">{{ $donor->display_name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="gift_type" :label="__('Gift type')" data-test="donation-receipt-gift-type">
            @foreach (GiftType::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="grid grid-cols-2 gap-4">
            <flux:input type="date" wire:model="gift_date" :label="__('Gift date')" required data-test="donation-receipt-gift-date" />
            <flux:input wire:model="amount" :label="__('Fair market value')" placeholder="0.00" required data-test="donation-receipt-amount" />
        </div>

        <div class="grid grid-cols-2 gap-4">
            <flux:input wire:model="advantage" :label="__('Advantage value')" placeholder="0.00" :description="__('Value of anything the donor received in return.')" data-test="donation-receipt-advantage" />
            <flux:input wire:model="advantage_description" :label="__('Advantage description')" data-test="donation-receipt-advantage-description" />
        </div>

        <flux:select wire:model="revenue_account_id" :label="__('Donation revenue account')" data-test="donation-receipt-revenue-account">
            <flux:select.option value="">{{ __('Default donation income account') }}</flux:select.option>
            @foreach ($this->incomeAccounts as $account)
                <flux:select.option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($gift_type === \App\Enums\GiftType::InKind->value)
            <div class="space-y-4 rounded-lg border border-border p-4" data-test="donation-receipt-in-kind-fields">
                <flux:heading size="sm">{{ __('Gift in kind') }}</flux:heading>
                <flux:textarea wire:model="in_kind_description" :label="__('Description of property')" rows="2" data-test="donation-receipt-in-kind-description" />
                <flux:select wire:model="debit_account_id" :label="__('Asset / expense account to debit')" :description="__('Where the gift is recorded at fair market value.')" data-test="donation-receipt-debit-account">
                    <flux:select.option value="">{{ __('Select an account') }}</flux:select.option>
                    @foreach ($this->assetAccounts as $account)
                        <flux:select.option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="appraised_by" :label="__('Appraised by')" :description="__('Required for gifts over $1,000.')" data-test="donation-receipt-appraised-by" />
                    <flux:input type="date" wire:model="appraisal_date" :label="__('Appraisal date')" data-test="donation-receipt-appraisal-date" />
                </div>
            </div>
        @endif

        <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" data-test="donation-receipt-notes" />

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" :href="route('donation-receipts.index', ['company' => $company])" wire:navigate>{{ __('Cancel') }}</flux:button>
            <flux:button variant="primary" type="submit" data-test="donation-receipt-save-button">{{ __('Save draft') }}</flux:button>
        </div>
    </form>
</section>
