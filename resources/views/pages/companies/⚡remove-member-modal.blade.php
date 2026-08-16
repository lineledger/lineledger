<?php

use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Models\User;
use App\Services\Audit\SecurityLogRecorder;
use App\Services\Security\AccessRevoker;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    public Company $company;

    public ?int $memberId = null;

    public string $memberName = '';

    public string $modalName = 'remove-member';

    public function mount(
        Company $company,
        ?int $memberId = null,
        ?string $memberName = null,
        ?string $modalName = null,
    ): void
    {
        $this->company = $company;
        $this->memberId = $memberId;
        $this->memberName = $memberName ?? '';
        $this->modalName = $modalName ?? ($memberId ? "remove-member-{$memberId}" : 'remove-member');
    }

    public function removeMember(SecurityLogRecorder $recorder, AccessRevoker $revoker): void
    {
        Gate::authorize('removeMember', $this->company);

        $user = User::findOrFail($this->memberId);

        if ($this->memberName === '') {
            $this->memberName = $user->name;
        }

        $this->company->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($user->isCurrentCompany($this->company)) {
            $user->switchCompany($user->personalCompany());
        }

        // Tear down credentials minted under the now-revoked membership:
        // sessions, the company API keys they created, and OAuth tokens.
        $revoker->revokeForRemoval($user, $this->company);

        $recorder->record(SecurityEvent::CompanyMemberRemoved, Auth::user(), metadata: [
            'company_id' => $this->company->id,
            'removed_user_id' => $user->id,
            'removed_user_email' => $user->email,
        ]);

        $this->dispatch('close-modal', name: $this->modalName);

        Flux::toast(variant: 'success', text: __('Member removed.'));

        $this->redirectRoute('companies.edit', ['company' => $this->company->slug], navigate: true);
    }
}; ?>

<flux:modal :name="$modalName" focusable class="max-w-lg">
    <form wire:submit="removeMember" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Remove member') }}</flux:heading>
            <flux:subheading>
                {{ __('Are you sure you want to remove :name from this company?', ['name' => $memberName]) }}
            </flux:subheading>
        </div>
        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" type="submit" data-test="remove-member-confirm">{{ __('Remove member') }}</flux:button>
        </div>
    </form>
</flux:modal>
