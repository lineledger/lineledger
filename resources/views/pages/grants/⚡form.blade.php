<?php

use App\Actions\Fundraising\SaveGrant;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Fund;
use App\Models\Grant;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Grant')] class extends Component {
    public Company $company;

    public ?Grant $grant = null;

    public ?int $funder_contact_id = null;

    public string $name = '';

    public string $award = '';

    public bool $is_restricted = true;

    public ?int $fund_id = null;

    public string $period_start = '';

    public string $period_end = '';

    public bool $receivable_on_award = false;

    public ?int $deposit_to_account_id = null;

    public ?int $deferred_account_id = null;

    public ?int $revenue_account_id = null;

    public string $recognition_method = 'manual';

    public string $notes = '';

    public function mount(Company $company, ?Grant $grant = null): void
    {
        $this->company = $company;

        abort_unless($company->tracksFundraising(), 403);

        if ($grant && $grant->exists) {
            abort_unless($grant->company_id === $company->id, 404);
            abort_unless($grant->isDraft(), 403, __('Only draft grants can be edited.'));

            $this->grant = $grant;
            $this->funder_contact_id = $grant->funder_contact_id;
            $this->name = $grant->name;
            $this->award = $grant->award_amount_cents ? (string) Money::fromCents($grant->award_amount_cents) : '';
            $this->is_restricted = $grant->is_restricted;
            $this->fund_id = $grant->fund_id;
            $this->period_start = $grant->period_start?->toDateString() ?? '';
            $this->period_end = $grant->period_end?->toDateString() ?? '';
            $this->receivable_on_award = $grant->receivable_on_award;
            $this->deposit_to_account_id = $grant->deposit_to_account_id;
            $this->deferred_account_id = $grant->deferred_account_id;
            $this->revenue_account_id = $grant->revenue_account_id;
            $this->recognition_method = $grant->recognition_method;
            $this->notes = (string) $grant->notes;
        }
    }

    #[Computed]
    public function funders()
    {
        return Contact::query()->orderByDesc('is_donor')->orderBy('display_name')->get(['id', 'display_name']);
    }

    #[Computed]
    public function assetAccounts()
    {
        return Account::query()->where('type', AccountType::Asset->value)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function liabilityAccounts()
    {
        return Account::query()->where('type', AccountType::Liability->value)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function incomeAccounts()
    {
        return Account::query()->where('type', AccountType::Income->value)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function funds()
    {
        return Fund::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    public function save()
    {
        $validated = $this->validate([
            'funder_contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('company_id', $this->company->id)],
            'name' => ['required', 'string', 'max:255'],
            'award' => ['required', 'string'],
            'is_restricted' => ['boolean'],
            'fund_id' => ['nullable', 'integer', Rule::exists('funds', 'id')->where('company_id', $this->company->id)],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'receivable_on_award' => ['boolean'],
            'deposit_to_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $this->company->id)],
            'deferred_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $this->company->id)],
            'revenue_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $this->company->id)],
            'recognition_method' => ['required', Rule::in(['manual', 'straight_line'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $awardCents = Money::tryFromString($this->award)?->cents ?? 0;

        if ($awardCents <= 0) {
            $this->addError('award', __('Enter an award amount greater than zero.'));

            return;
        }

        $grant = app(SaveGrant::class)->handle([
            'funder_contact_id' => $validated['funder_contact_id'] ?: null,
            'name' => $validated['name'],
            'award_amount_cents' => $awardCents,
            'is_restricted' => $this->is_restricted,
            'fund_id' => $this->company->tracksFunds() ? ($validated['fund_id'] ?: null) : null,
            'period_start' => $validated['period_start'] ?: null,
            'period_end' => $validated['period_end'] ?: null,
            'receivable_on_award' => $this->receivable_on_award,
            'deposit_to_account_id' => $validated['deposit_to_account_id'] ?: null,
            'deferred_account_id' => $validated['deferred_account_id'] ?: null,
            'revenue_account_id' => $validated['revenue_account_id'] ?: null,
            'recognition_method' => $validated['recognition_method'],
            'notes' => $this->notes ?: null,
        ], $this->grant);

        Flux::toast(variant: 'success', text: __('Grant saved.'));

        $this->redirectRoute('grants.show', ['company' => $this->company, 'grant' => $grant], navigate: true);
    }
}; ?>

<section class="mx-auto w-full max-w-2xl">
    <flux:heading size="xl" level="1" data-test="page-title">{{ $grant?->exists ? __('Edit grant') : __('New grant') }}</flux:heading>
    <flux:subheading class="mb-6">{{ __('Drafts can be edited until the award is posted.') }}</flux:subheading>

    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="name" :label="__('Grant name')" required data-test="grant-name" />

        <div class="grid grid-cols-2 gap-4">
            <flux:select wire:model="funder_contact_id" :label="__('Funder')" data-test="grant-funder">
                <flux:select.option value="">{{ __('No funder') }}</flux:select.option>
                @foreach ($this->funders as $funder)
                    <flux:select.option value="{{ $funder->id }}">{{ $funder->display_name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="award" :label="__('Award amount')" placeholder="0.00" required data-test="grant-award" />
        </div>

        <div class="grid grid-cols-2 gap-4">
            <flux:input type="date" wire:model="period_start" :label="__('Period start')" data-test="grant-period-start" />
            <flux:input type="date" wire:model="period_end" :label="__('Period end')" data-test="grant-period-end" />
        </div>

        <flux:select wire:model="deposit_to_account_id" :label="__('Deposit to / receivable account')" data-test="grant-deposit-account">
            <flux:select.option value="">{{ __('Select an account') }}</flux:select.option>
            @foreach ($this->assetAccounts as $account)
                <flux:select.option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model="revenue_account_id" :label="__('Grant revenue account')" data-test="grant-revenue-account">
            <flux:select.option value="">{{ __('Default grant revenue account') }}</flux:select.option>
            @foreach ($this->incomeAccounts as $account)
                <flux:select.option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:switch wire:model.live="is_restricted" :label="__('Restricted grant')" data-test="grant-restricted" />

        @if ($is_restricted)
            <div class="space-y-4 rounded-lg border border-border p-4" data-test="grant-restriction-fields">
                @if ($company->tracksFunds())
                    <flux:select wire:model="fund_id" :label="__('Fund')" data-test="grant-fund">
                        <flux:select.option value="">{{ __('No fund') }}</flux:select.option>
                        @foreach ($this->funds as $fund)
                            <flux:select.option value="{{ $fund->id }}">{{ $fund->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                @if ($company->usesDeferralMethod())
                    <flux:select wire:model="deferred_account_id" :label="__('Deferred / restricted liability')" data-test="grant-deferred-account">
                        <flux:select.option value="">{{ __('Default deferred-grants account') }}</flux:select.option>
                        @foreach ($this->liabilityAccounts as $account)
                            <flux:select.option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="recognition_method" :label="__('Recognition method')" data-test="grant-recognition-method">
                        <flux:select.option value="manual">{{ __('Manual') }}</flux:select.option>
                        <flux:select.option value="straight_line">{{ __('Straight-line over the period') }}</flux:select.option>
                    </flux:select>
                @endif
            </div>
        @endif

        <flux:switch wire:model="receivable_on_award" :label="__('Recognize a receivable on award')" :description="__('The funder has committed but not yet paid.')" data-test="grant-receivable" />

        <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" data-test="grant-notes" />

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" :href="route('grants.index', ['company' => $company])" wire:navigate>{{ __('Cancel') }}</flux:button>
            <flux:button variant="primary" type="submit" data-test="grant-save-button">{{ __('Save draft') }}</flux:button>
        </div>
    </form>
</section>
