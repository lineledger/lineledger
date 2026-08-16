<?php

use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Notifications\Companies\CompanyInvitation as CompanyInvitationNotification;
use App\Rules\UniqueCompanyInvitation;
use App\Services\Audit\SecurityLogRecorder;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Company $company;

    public string $inviteEmail = '';

    public string $inviteRole = 'accountant';

    /** @var array<int, string> */
    public array $inviteSections = [];

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    public function createInvitation(SecurityLogRecorder $recorder): void
    {
        Gate::authorize('inviteMember', $this->company);

        $validated = $this->validate([
            'inviteEmail' => ['required', 'string', 'email', 'max:255', new UniqueCompanyInvitation($this->company)],
            'inviteRole' => ['required', 'string', Rule::enum(CompanyRole::class)->except(CompanyRole::Owner)],
            'inviteSections' => [Rule::requiredIf(fn () => $this->inviteRole === CompanyRole::Custom->value), 'array'],
            'inviteSections.*' => [Rule::in(Section::values())],
        ]);

        $role = CompanyRole::from($validated['inviteRole']);

        $invitation = $this->company->invitations()->create([
            'email' => $validated['inviteEmail'],
            'role' => $role,
            'sections' => $role->usesCustomSections() ? array_values($validated['inviteSections']) : null,
            'invited_by' => Auth::id(),
            'expires_at' => now()->addDays(3),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new CompanyInvitationNotification($invitation));

        $recorder->record(SecurityEvent::CompanyMemberInvited, Auth::user(), metadata: [
            'company_id' => $this->company->id,
            'invitation_email' => $invitation->email,
            'role' => $role->value,
        ]);

        $this->reset('inviteEmail', 'inviteRole', 'inviteSections');
        $this->dispatch('close-modal', name: 'invite-member');

        Flux::toast(variant: 'success', text: __('Invitation sent.'));

        $this->redirectRoute('companies.edit', ['company' => $this->company->slug], navigate: true);
    }

    #[Computed]
    public function availableRoles(): array
    {
        return CompanyRole::assignable();
    }

    /**
     * @return array<int, Section>
     */
    #[Computed]
    public function sectionOptions(): array
    {
        return Section::cases();
    }
}; ?>

<flux:modal name="invite-member" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <form wire:submit="createInvitation" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Invite a member') }}</flux:heading>
            <flux:subheading>{{ __('Send an invitation to join this company.') }}</flux:subheading>
        </div>

        <div class="space-y-4">
            <flux:input wire:model="inviteEmail" type="email" :label="__('Email address')" required data-test="invite-email" />

            <flux:select wire:model.live="inviteRole" :label="__('Role')" data-test="invite-role">
                @foreach ($this->availableRoles as $role)
                    <flux:select.option value="{{ $role['value'] }}">{{ $role['label'] }} — {{ $role['description'] }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($inviteRole === \App\Enums\CompanyRole::Custom->value)
                <flux:fieldset>
                    <flux:legend>{{ __('Sections') }}</flux:legend>
                    <flux:description>{{ __('Choose which sections this member can access.') }}</flux:description>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach ($this->sectionOptions as $section)
                            <flux:checkbox
                                wire:model="inviteSections"
                                value="{{ $section->value }}"
                                :label="$section->label()"
                                data-test="invite-section-{{ $section->value }}"
                            />
                        @endforeach
                    </div>
                    <flux:error name="inviteSections" />
                </flux:fieldset>
            @endif
        </div>

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="primary" type="submit" data-test="invite-submit">{{ __('Send invitation') }}</flux:button>
        </div>
    </form>
</flux:modal>
