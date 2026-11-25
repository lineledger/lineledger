<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    /**
     * Names of the organizations the user owns, which have to be deleted
     * before the account can be (the modal refuses otherwise).
     *
     * @return list<string>
     */
    #[Computed]
    public function ownedOrganizations(): array
    {
        $user = Auth::user();

        return $user instanceof User
            ? $user->ownedCompaniesBlockingAccountDeletion()->map(fn (Company $company): string => $company->name)->values()->all()
            : [];
    }
}; ?>

<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <flux:heading>{{ __('Delete account') }}</flux:heading>
        <flux:subheading>{{ __('Delete your account and all of its resources') }}</flux:subheading>
    </div>

    @if ($this->ownedOrganizations !== [])
        <flux:callout variant="warning" icon="building-office" data-test="delete-user-owns-organizations">
            <flux:callout.heading>{{ __('Delete your organizations first') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('You own :organizations. An organization can’t be left without an owner, so delete each one under Settings → Organizations before you delete your account.', ['organizations' => implode(', ', $this->ownedOrganizations)]) }}
            </flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" :href="route('companies.index')" wire:navigate>{{ __('Organizations') }}</flux:button>
            </x-slot>
        </flux:callout>
    @else
        <flux:modal.trigger name="confirm-user-deletion">
            <flux:button variant="danger" data-test="delete-user-button">
                {{ __('Delete account') }}
            </flux:button>
        </flux:modal.trigger>

        <livewire:pages::settings.delete-user-modal />
    @endif
</section>
