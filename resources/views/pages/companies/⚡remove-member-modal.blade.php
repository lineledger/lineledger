<?php

use App\Actions\Companies\Members\RemoveMember;
use App\Models\Company;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    public Company $company;

    /** Locked: the members page mounts one modal per member it lists. */
    #[Locked]
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

    public function removeMember(RemoveMember $remove): void
    {
        Gate::authorize('removeMember', $this->company);

        // Only a member of this organization, and never an owner — the members
        // page offers no remove button for one (the Action refuses it too).
        $membership = $this->company->memberships()
            ->where('user_id', $this->memberId)
            ->firstOrFail();

        $user = User::findOrFail($membership->user_id);

        if ($this->memberName === '') {
            $this->memberName = $user->name;
        }

        $remove->handle($this->company, Auth::user(), $user);

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
