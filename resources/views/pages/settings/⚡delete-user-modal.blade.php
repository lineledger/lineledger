<?php

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $user = Auth::user();

        // Every organization keeps an Owner: deleting the account of someone
        // who still owns one would leave it with nobody who can run or delete
        // it. They delete those organizations first.
        $owned = $user instanceof User ? $user->ownedCompaniesBlockingAccountDeletion() : collect();

        if ($owned->isNotEmpty()) {
            throw ValidationException::withMessages([
                'owned' => __('You still own :organizations. Delete each organization you own first, under Settings → Organizations, then delete your account.', [
                    'organizations' => $owned->map(fn (Company $company): string => $company->name)->implode(', '),
                ]),
            ]);
        }

        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <form method="POST" wire:submit="deleteUser" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Are you sure you want to delete your account?') }}</flux:heading>

            <flux:subheading>
                {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
            </flux:subheading>
        </div>

        @error('owned')
            <flux:callout variant="danger" icon="exclamation-triangle" data-test="delete-user-owned-organizations">
                <flux:callout.text>{{ $message }}</flux:callout.text>
            </flux:callout>
        @enderror

        <flux:input wire:model="password" :label="__('Password')" type="password" viewable />

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button variant="danger" type="submit" data-test="confirm-delete-user-button">
                {{ __('Delete account') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
