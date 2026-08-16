<?php

use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Services\Audit\SecurityLogRecorder;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    public Company $company;

    public string $invitationCode = '';

    public string $invitationEmail = '';

    public string $modalName = 'cancel-invitation';

    public function mount(
        Company $company,
        ?string $invitationCode = null,
        ?string $invitationEmail = null,
        ?string $modalName = null,
    ): void
    {
        $this->company = $company;
        $this->invitationCode = $invitationCode ?? '';
        $this->invitationEmail = $invitationEmail ?? '';
        $this->modalName = $modalName ?? ($invitationCode ? "cancel-invitation-{$invitationCode}" : 'cancel-invitation');
    }

    public function cancelInvitation(SecurityLogRecorder $recorder): void
    {
        $invitation = $this->company->invitations()->where('code', $this->invitationCode)->firstOrFail();

        if ($this->invitationEmail === '') {
            $this->invitationEmail = $invitation->email;
        }

        Gate::authorize('cancelInvitation', $this->company);

        $invitation->delete();

        $recorder->record(SecurityEvent::CompanyInvitationCancelled, Auth::user(), metadata: [
            'company_id' => $this->company->id,
            'invitation_email' => $invitation->email,
            'role' => $invitation->role->value,
        ]);

        $this->dispatch('close-modal', name: $this->modalName);

        Flux::toast(variant: 'success', text: __('Invitation cancelled.'));

        $this->redirectRoute('companies.edit', ['company' => $this->company->slug], navigate: true);
    }
}; ?>

<flux:modal :name="$modalName" focusable class="max-w-lg">
    <form wire:submit="cancelInvitation" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Cancel invitation') }}</flux:heading>
            <flux:subheading>
                {{ __('Are you sure you want to cancel the invitation for :email?', ['email' => $invitationEmail]) }}
            </flux:subheading>
        </div>
        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Keep invitation') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" type="submit" data-test="cancel-invitation-confirm">{{ __('Cancel invitation') }}</flux:button>
        </div>
    </form>
</flux:modal>
