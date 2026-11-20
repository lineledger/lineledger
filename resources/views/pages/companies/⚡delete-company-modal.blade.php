<?php

use App\Actions\Companies\DeleteCompany;
use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Services\Audit\SecurityLogRecorder;
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

    #[Computed]
    public function purgeAfterDays(): int
    {
        return max(0, (int) config('companies.purge_after_days'));
    }

    /**
     * Members lose access at once; the data itself is erased by
     * companies:purge-deleted once the grace period ends. Until then a site
     * admin can still restore the organization.
     */
    public function deleteCompany(SecurityLogRecorder $recorder): void
    {
        Gate::authorize('delete', $this->company);

        $validated = $this->validate([
            'deleteName' => ['required', 'string'],
        ]);

        if ($validated['deleteName'] !== $this->company->name) {
            $this->addError('deleteName', __('The company name does not match.'));

            return;
        }

        $purgeAt = now()->addDays($this->purgeAfterDays);

        app(DeleteCompany::class)->handle($this->company, Auth::user(), $purgeAt);

        $recorder->record(SecurityEvent::CompanyDeleted, Auth::user(), metadata: [
            'company_id' => $this->company->id,
            'slug' => $this->company->slug,
            'name' => $this->company->name,
            'actor' => Auth::user()?->email,
            'purge_at' => $purgeAt->toIso8601String(),
        ]);

        Flux::toast(variant: 'success', text: __('Company deleted. Its data will be permanently erased after :date.', [
            'date' => $purgeAt->isoFormat('LL'),
        ]));

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
                {{ __('Everyone loses access to ":name" right away. After :days days, it and all of its data — transactions, documents, attachments and backups — are permanently erased. Until then, only a site administrator can restore it.', ['name' => $company->name, 'days' => $this->purgeAfterDays]) }}
            </flux:subheading>
            <flux:subheading class="mt-2">
                {{ __('To keep a copy, download a backup first from Settings → Backup & export.') }}
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
