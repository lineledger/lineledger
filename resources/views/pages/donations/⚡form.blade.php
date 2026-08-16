<?php

use App\Actions\Fundraising\SaveDonation;
use App\Enums\AccountType;
use App\Enums\GiftType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Donation;
use App\Models\Fund;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Donation')] class extends Component {
    public Company $company;

    public ?Donation $donation = null;

    public ?int $contact_id = null;

    public string $gift_type = 'cash';

    public string $donation_date = '';

    public string $amount = '';

    public ?int $deposit_to_account_id = null;

    public bool $is_restricted = false;

    public ?int $fund_id = null;

    public string $restriction_note = '';

    public ?int $revenue_account_id = null;

    public ?int $deferred_account_id = null;

    public bool $issue_receipt = false;

    public string $notes = '';

    public function mount(Company $company, ?Donation $donation = null): void
    {
        $this->company = $company;

        abort_unless($company->tracksFundraising(), 403);

        if ($donation && $donation->exists) {
            abort_unless($donation->company_id === $company->id, 404);
            abort_unless($donation->isDraft(), 403, __('Only draft donations can be edited.'));

            $this->donation = $donation;
            $this->contact_id = $donation->contact_id;
            $this->gift_type = $donation->gift_type->value;
            $this->donation_date = $donation->donation_date?->toDateString() ?? '';
            $this->amount = $donation->amount_cents ? (string) Money::fromCents($donation->amount_cents) : '';
            $this->deposit_to_account_id = $donation->deposit_to_account_id;
            $this->is_restricted = $donation->is_restricted;
            $this->fund_id = $donation->fund_id;
            $this->restriction_note = (string) $donation->restriction_note;
            $this->revenue_account_id = $donation->revenue_account_id;
            $this->deferred_account_id = $donation->deferred_account_id;
            $this->notes = (string) $donation->notes;
        } else {
            $this->donation_date = $company->currentDateTime()->toDateString();
        }
    }

    #[Computed]
    public function donors()
    {
        return Contact::query()->orderByDesc('is_donor')->orderBy('display_name')->get(['id', 'display_name', 'is_donor']);
    }

    #[Computed]
    public function depositAccounts()
    {
        return Account::query()
            ->whereIn('type', [AccountType::Asset->value, AccountType::Expense->value])
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function incomeAccounts()
    {
        return Account::query()->where('type', AccountType::Income->value)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function liabilityAccounts()
    {
        return Account::query()->where('type', AccountType::Liability->value)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function funds()
    {
        return Fund::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    public function save()
    {
        $validated = $this->validate([
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('company_id', $this->company->id)],
            'gift_type' => ['required', Rule::enum(GiftType::class)],
            'donation_date' => ['required', 'date'],
            'amount' => ['required', 'string'],
            'deposit_to_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $this->company->id)],
            'is_restricted' => ['boolean'],
            'fund_id' => ['nullable', 'integer', Rule::exists('funds', 'id')->where('company_id', $this->company->id)],
            'restriction_note' => ['nullable', 'string', 'max:1000'],
            'revenue_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $this->company->id)],
            'deferred_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $this->company->id)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $amountCents = Money::tryFromString($this->amount)?->cents ?? 0;

        if ($amountCents <= 0) {
            $this->addError('amount', __('Enter a donation amount greater than zero.'));

            return;
        }

        $donation = app(SaveDonation::class)->handle([
            'contact_id' => $validated['contact_id'] ?: null,
            'gift_type' => $validated['gift_type'],
            'donation_date' => $validated['donation_date'],
            'amount_cents' => $amountCents,
            'deposit_to_account_id' => $validated['deposit_to_account_id'],
            'is_restricted' => $this->is_restricted,
            'fund_id' => $this->company->tracksFunds() ? ($validated['fund_id'] ?: null) : null,
            'restriction_note' => $this->is_restricted ? ($this->restriction_note ?: null) : null,
            'revenue_account_id' => $validated['revenue_account_id'] ?: null,
            'deferred_account_id' => $this->is_restricted ? ($validated['deferred_account_id'] ?: null) : null,
            'issue_receipt' => $this->issue_receipt && $this->company->isRegisteredCharity(),
            'notes' => $this->notes ?: null,
        ], $this->donation);

        Flux::toast(variant: 'success', text: __('Donation saved.'));

        $this->redirectRoute('donations.show', ['company' => $this->company, 'donation' => $donation], navigate: true);
    }
}; ?>

<section class="mx-auto w-full max-w-2xl">
    <flux:heading size="xl" level="1" data-test="page-title">{{ $donation?->exists ? __('Edit donation') : __('Record donation') }}</flux:heading>
    <flux:subheading class="mb-6">{{ __('Records the gift to the general ledger. Drafts can be edited until posted.') }}</flux:subheading>

    <form wire:submit="save" class="space-y-6">
        <flux:select wire:model="contact_id" :label="__('Donor')" data-test="donation-contact">
            <flux:select.option value="">{{ __('Anonymous / no contact') }}</flux:select.option>
            @foreach ($this->donors as $donor)
                <flux:select.option value="{{ $donor->id }}">{{ $donor->display_name }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="grid grid-cols-3 gap-4">
            <flux:select wire:model="gift_type" :label="__('Gift type')" data-test="donation-gift-type">
                @foreach (GiftType::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input type="date" wire:model="donation_date" :label="__('Date')" required data-test="donation-date" />
            <flux:input wire:model="amount" :label="__('Amount')" placeholder="0.00" required data-test="donation-amount" />
        </div>

        <flux:select wire:model="deposit_to_account_id" :label="__('Deposit to / record against')" :description="__('Bank or undeposited funds for cash; the asset account for an in-kind gift.')" required data-test="donation-deposit-account">
            <flux:select.option value="">{{ __('Select an account') }}</flux:select.option>
            @foreach ($this->depositAccounts as $account)
                <flux:select.option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model="revenue_account_id" :label="__('Donation revenue account')" data-test="donation-revenue-account">
            <flux:select.option value="">{{ __('Default donation income account') }}</flux:select.option>
            @foreach ($this->incomeAccounts as $account)
                <flux:select.option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:switch wire:model.live="is_restricted" :label="__('Restricted gift')" :description="__('The donor restricted how this gift may be used.')" data-test="donation-restricted" />

        @if ($is_restricted)
            <div class="space-y-4 rounded-lg border border-border p-4" data-test="donation-restriction-fields">
                @if ($company->tracksFunds())
                    <flux:select wire:model="fund_id" :label="__('Fund')" data-test="donation-fund">
                        <flux:select.option value="">{{ __('No fund') }}</flux:select.option>
                        @foreach ($this->funds as $fund)
                            <flux:select.option value="{{ $fund->id }}">{{ $fund->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                @if ($company->usesDeferralMethod())
                    <flux:select wire:model="deferred_account_id" :label="__('Deferred / restricted liability')" :description="__('Where the restricted gift is held until the funds are spent.')" data-test="donation-deferred-account">
                        <flux:select.option value="">{{ __('Default deferred-grants account') }}</flux:select.option>
                        @foreach ($this->liabilityAccounts as $account)
                            <flux:select.option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                <flux:textarea wire:model="restriction_note" :label="__('Restriction')" rows="2" data-test="donation-restriction-note" />
            </div>
        @endif

        @if ($company->isRegisteredCharity() && ! $donation?->exists)
            <flux:switch wire:model="issue_receipt" :label="__('Create an official donation receipt')" :description="__('Spawns a draft receipt you can review and issue.')" data-test="donation-issue-receipt" />
        @endif

        <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" data-test="donation-notes" />

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" :href="route('donations.index', ['company' => $company])" wire:navigate>{{ __('Cancel') }}</flux:button>
            <flux:button variant="primary" type="submit" data-test="donation-save-button">{{ __('Save draft') }}</flux:button>
        </div>
    </form>
</section>
