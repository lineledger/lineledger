<?php

use App\Actions\Companies\DeleteCompany;
use App\Models\Company;
use App\Support\UserCompany;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Company $company;

    public string $deleteName = '';

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    #[Computed]
    public function deleteConfirmLabel(): string
    {
        return __('Type ":name" to confirm', ['name' => $this->company->name]);
    }

    public function deleteCompany(): void
    {
        Gate::authorize('delete', $this->company);

        $validated = $this->validate([
            'deleteName' => ['required', 'string'],
        ]);

        if ($validated['deleteName'] !== $this->company->name) {
            $this->addError('deleteName', __('The company name does not match.'));

            return;
        }

        app(DeleteCompany::class)->handle($this->company, Auth::user());

        Flux::toast(variant: 'success', text: __('Company deleted.'));

        $this->redirectRoute('companies.index', navigate: true);
    }

    /**
     * @return Collection<int, UserCompany>
     */
    #[Computed]
    public function otherCompanies(): Collection
    {
        return Auth::user()->toUserCompanies();
    }
}; ?>

<flux:modal name="delete-company" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <form wire:submit="deleteCompany" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Are you sure?') }}</flux:heading>
            <flux:subheading>
                {{ __('This action cannot be undone. This will permanently delete the company ":name".', ['name' => $company->name]) }}
            </flux:subheading>
        </div>

        <div class="space-y-4">
            <flux:input wire:model="deleteName" :label="$this->deleteConfirmLabel" required data-test="delete-company-name" />
        </div>

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" type="submit" data-test="delete-company-confirm">
                {{ __('Delete company') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
