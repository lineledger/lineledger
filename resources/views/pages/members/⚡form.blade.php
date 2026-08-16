<?php

use App\Actions\Contacts\SaveContact;
use App\Actions\Membership\SaveMember;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Member;
use App\Models\MembershipLevel;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Member')] class extends Component {
    public Company $company;

    public ?Member $member = null;

    /** Whether the member is created for a brand-new contact or an existing one. */
    public string $contact_mode = 'new';

    public ?int $contact_id = null;

    public string $new_display_name = '';

    public string $new_company_name = '';

    public string $new_email = '';

    public string $new_phone = '';

    public string $new_billing_line1 = '';

    public string $new_billing_line2 = '';

    public string $new_billing_city = '';

    public string $new_billing_region = '';

    public string $new_billing_postal_code = '';

    public string $new_billing_country = '';

    public ?int $membership_level_id = null;

    public string $joined_on = '';

    public string $started_on = '';

    public string $expires_on = '';

    public string $dues = '';

    public bool $auto_renew = false;

    public string $notes = '';

    public bool $is_active = true;

    public function mount(Company $company, ?Member $member = null): void
    {
        $this->company = $company;

        abort_unless($company->tracksMembership(), 403);

        if ($member && $member->exists) {
            abort_unless($member->company_id === $company->id, 404);

            $this->member = $member;
            // An existing member is already tied to a contact — never re-create it.
            $this->contact_mode = 'existing';
            $this->contact_id = $member->contact_id;
            $this->membership_level_id = $member->membership_level_id;
            $this->joined_on = $member->joined_on?->toDateString() ?? '';
            $this->started_on = $member->started_on?->toDateString() ?? '';
            $this->expires_on = $member->expires_on?->toDateString() ?? '';
            $this->dues = $member->dues_cents !== null ? (string) Money::fromCents($member->dues_cents) : '';
            $this->auto_renew = $member->auto_renew;
            $this->notes = (string) $member->notes;
            $this->is_active = $member->is_active;
        } else {
            $this->joined_on = $company->currentDateTime()->toDateString();
            $this->started_on = $company->currentDateTime()->toDateString();
        }
    }

    #[Computed]
    public function contacts()
    {
        return Contact::query()
            ->where('is_active', true)
            ->orderBy('display_name')
            ->get(['id', 'display_name']);
    }

    #[Computed]
    public function levels()
    {
        return MembershipLevel::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'default_dues_cents']);
    }

    public function save()
    {
        // An existing member's contact is fixed; only new members may create one.
        if ($this->member?->exists) {
            $this->contact_mode = 'existing';
        }

        $rules = [
            'membership_level_id' => ['nullable', 'integer', Rule::exists('membership_levels', 'id')->where('company_id', $this->company->id)],
            'joined_on' => ['nullable', 'date'],
            'started_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
            'dues' => ['nullable', 'string'],
            'auto_renew' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];

        if ($this->contact_mode === 'new') {
            $rules['new_display_name'] = ['required', 'string', 'max:255'];
            $rules['new_company_name'] = ['nullable', 'string', 'max:255'];
            $rules['new_email'] = ['nullable', 'email', 'max:255'];
            $rules['new_phone'] = ['nullable', 'string', 'max:50'];
            $rules['new_billing_line1'] = ['nullable', 'string', 'max:255'];
            $rules['new_billing_line2'] = ['nullable', 'string', 'max:255'];
            $rules['new_billing_city'] = ['nullable', 'string', 'max:255'];
            $rules['new_billing_region'] = ['nullable', 'string', 'max:255'];
            $rules['new_billing_postal_code'] = ['nullable', 'string', 'max:255'];
            // contacts.billing_country is a 2-char ISO code column; max (not size)
            // because Livewire submits an untouched field as '' rather than null.
            $rules['new_billing_country'] = ['nullable', 'string', 'max:2'];
        } else {
            $rules['contact_id'] = ['required', 'integer', Rule::exists('contacts', 'id')->where('company_id', $this->company->id)];
        }

        $validated = $this->validate($rules);

        if ($this->contact_mode === 'new') {
            // Create the underlying contact (as a customer, so dues can be invoiced)
            // in the same step — no separate "create customer" trip required.
            $contact = app(SaveContact::class)->handle([
                'display_name' => $validated['new_display_name'],
                'company_name' => $validated['new_company_name'] ?: null,
                'email' => $validated['new_email'] ?: null,
                'phone' => $validated['new_phone'] ?: null,
                'billing_address' => [
                    'line1' => $validated['new_billing_line1'] ?: null,
                    'line2' => $validated['new_billing_line2'] ?: null,
                    'city' => $validated['new_billing_city'] ?: null,
                    'region' => $validated['new_billing_region'] ?: null,
                    'postal_code' => $validated['new_billing_postal_code'] ?: null,
                    'country' => mb_strtoupper($validated['new_billing_country']) ?: null,
                ],
            ], 'is_customer');

            $contactId = $contact->id;
        } else {
            $contactId = (int) $validated['contact_id'];

            // One membership per contact per company.
            $exists = Member::query()
                ->where('contact_id', $contactId)
                ->when($this->member, fn ($q) => $q->whereKeyNot($this->member->id))
                ->exists();

            if ($exists) {
                $this->addError('contact_id', __('This contact already has a membership.'));

                return;
            }
        }

        $member = app(SaveMember::class)->handle([
            'contact_id' => $contactId,
            'membership_level_id' => $validated['membership_level_id'] ?: null,
            'joined_on' => $validated['joined_on'] ?: null,
            'started_on' => $validated['started_on'] ?: null,
            'expires_on' => $validated['expires_on'] ?: null,
            'dues_cents' => $this->dues !== '' ? (Money::tryFromString($this->dues)?->cents ?? null) : null,
            'auto_renew' => $this->auto_renew,
            'notes' => $this->notes ?: null,
            'is_active' => $this->is_active,
        ], $this->member);

        Flux::toast(variant: 'success', text: __('Member saved.'));

        $this->redirectRoute('members.show', ['company' => $this->company, 'member' => $member], navigate: true);
    }
}; ?>

<section class="mx-auto w-full max-w-2xl">
    <flux:heading size="xl" level="1" data-test="page-title">{{ $member?->exists ? __('Edit member') : __('New member') }}</flux:heading>
    <flux:subheading class="mb-6">{{ __('Members are billed for dues as invoices.') }}</flux:subheading>

    <form wire:submit="save" class="space-y-6">
        @if ($member?->exists)
            <flux:select wire:model="contact_id" :label="__('Contact')" required data-test="member-contact">
                <flux:select.option value="">{{ __('Select a contact') }}</flux:select.option>
                @foreach ($this->contacts as $contact)
                    <flux:select.option value="{{ $contact->id }}">{{ $contact->display_name }}</flux:select.option>
                @endforeach
            </flux:select>
        @else
            <flux:radio.group wire:model.live="contact_mode" :label="__('Contact')" variant="segmented" data-test="member-contact-mode">
                <flux:radio value="new" :label="__('New contact')" />
                <flux:radio value="existing" :label="__('Existing contact')" />
            </flux:radio.group>

            @if ($contact_mode === 'new')
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:input wire:model="new_display_name" :label="__('Name')" required data-test="member-new-name" />
                    <flux:input wire:model="new_company_name" :label="__('Company name')" data-test="member-new-company-name" />
                    <flux:input wire:model="new_email" type="email" :label="__('Email')" data-test="member-new-email" />
                    <flux:input wire:model="new_phone" :label="__('Phone')" data-test="member-new-phone" />
                </div>

                <div class="space-y-3 rounded-lg border border-border p-4">
                    <flux:heading size="sm">{{ __('Address') }}</flux:heading>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:input wire:model="new_billing_line1" :label="__('Address line 1')" data-test="member-new-billing-line1" />
                        <flux:input wire:model="new_billing_line2" :label="__('Address line 2')" data-test="member-new-billing-line2" />
                        <flux:input wire:model="new_billing_city" :label="__('City')" data-test="member-new-billing-city" />
                        <flux:input wire:model="new_billing_region" :label="__('Province / State')" data-test="member-new-billing-region" />
                        <flux:input wire:model="new_billing_postal_code" :label="__('Postal / ZIP')" data-test="member-new-billing-postal-code" />
                        <flux:input wire:model="new_billing_country" :label="__('Country')" maxlength="2" placeholder="CA" :description="__('Two-letter code')" data-test="member-new-billing-country" />
                    </div>
                </div>
            @else
                <flux:select wire:model="contact_id" :label="__('Select contact')" required data-test="member-contact">
                    <flux:select.option value="">{{ __('Select a contact') }}</flux:select.option>
                    @foreach ($this->contacts as $contact)
                        <flux:select.option value="{{ $contact->id }}">{{ $contact->display_name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        @endif

        <flux:select wire:model="membership_level_id" :label="__('Membership level')" data-test="member-level">
            <flux:select.option value="">{{ __('No level') }}</flux:select.option>
            @foreach ($this->levels as $level)
                <flux:select.option value="{{ $level->id }}">{{ $level->name }} ({{ Money::fromCents($level->default_dues_cents, $company->currency_code) }})</flux:select.option>
            @endforeach
        </flux:select>

        <div class="grid grid-cols-3 gap-4">
            <flux:input type="date" wire:model="joined_on" :label="__('Joined')" data-test="member-joined-on" />
            <flux:input type="date" wire:model="started_on" :label="__('Term start')" data-test="member-started-on" />
            <flux:input type="date" wire:model="expires_on" :label="__('Expires')" data-test="member-expires-on" />
        </div>

        <flux:input wire:model="dues" :label="__('Dues override')" placeholder="{{ __('Defaults to the level dues') }}" :description="__('Leave blank to use the level default.')" data-test="member-dues" />

        <flux:switch wire:model="auto_renew" :label="__('Auto-renew')" :description="__('Generate dues invoices automatically each billing period.')" data-test="member-auto-renew" />

        <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" data-test="member-notes" />

        <flux:switch wire:model="is_active" :label="__('Active')" data-test="member-active" />

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" :href="route('members.index', ['company' => $company])" wire:navigate>{{ __('Cancel') }}</flux:button>
            <flux:button variant="primary" type="submit" data-test="member-save-button">{{ __('Save') }}</flux:button>
        </div>
    </form>
</section>
