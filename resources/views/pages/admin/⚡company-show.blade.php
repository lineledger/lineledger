<?php

use App\Actions\Companies\DeleteCompany;
use App\Actions\Companies\PurgeCompany;
use App\Actions\Companies\RestoreCompany;
use App\Enums\CompanyRole;
use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Services\Audit\SecurityLogRecorder;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Site Admin — Company')] class extends Component {
    public Company $company;

    public string $purgeName = '';

    public function mount(Company $company): void
    {
        abort_unless(auth()->user()?->site_admin, 404);

        $this->company = $company->loadMissing([
            'members' => fn ($q) => $q->wherePivot('role', CompanyRole::Owner->value),
        ]);
    }

    /**
     * Grant or revoke the per-company payroll override. When set, this company
     * sees the Payroll section even if the platform-wide kill switch is off, and
     * the per-company features_payroll opt-in is treated as satisfied.
     */
    public function togglePayrollOverride(): void
    {
        abort_unless(auth()->user()?->site_admin, 404);

        $enable = $this->company->payroll_admin_enabled_at === null;

        $this->company->forceFill([
            'payroll_admin_enabled_at' => $enable ? now() : null,
            'payroll_admin_enabled_by' => $enable ? auth()->user()?->email : null,
        ])->save();

        Flux::toast(variant: 'success', text: $enable
            ? __('Payroll enabled for :name.', ['name' => $this->company->name])
            : __('Payroll override removed for :name.', ['name' => $this->company->name]));
    }

    /**
     * Mark the company deleted. Reversible — memberships survive, so restore
     * hands back the company with its owner and roles intact.
     */
    public function deleteCompany(SecurityLogRecorder $recorder): void
    {
        abort_unless(auth()->user()?->site_admin, 404);

        if ($this->company->trashed()) {
            return;
        }

        app(DeleteCompany::class)->handle($this->company);

        $recorder->record(SecurityEvent::CompanyDeleted, auth()->user(), metadata: $this->companyMetadata());

        Flux::toast(variant: 'success', text: __(':name marked as deleted.', ['name' => $this->company->name]));
    }

    public function restoreCompany(SecurityLogRecorder $recorder): void
    {
        abort_unless(auth()->user()?->site_admin, 404);

        if (! $this->company->trashed()) {
            return;
        }

        app(RestoreCompany::class)->handle($this->company);

        $recorder->record(SecurityEvent::CompanyRestored, auth()->user(), metadata: $this->companyMetadata());

        Flux::toast(variant: 'success', text: __(':name restored.', ['name' => $this->company->name]));
    }

    /**
     * Permanently destroy an already-deleted company. Offered only on a trashed
     * company and gated on retyping its exact name — there is no undo.
     */
    public function purgeCompany(SecurityLogRecorder $recorder): void
    {
        abort_unless(auth()->user()?->site_admin, 404);

        // Purging a live company is never offered in the UI; refuse it outright
        // so a crafted request can't skip the delete-then-purge two-step.
        abort_unless($this->company->trashed(), 403);

        $validated = $this->validate([
            'purgeName' => ['required', 'string'],
        ]);

        if ($validated['purgeName'] !== $this->company->name) {
            $this->addError('purgeName', __('The organization name does not match.'));

            return;
        }

        // Recorded before the delete: afterwards this metadata is the only
        // surviving trace of the company.
        $recorder->record(SecurityEvent::CompanyPurged, auth()->user(), metadata: $this->companyMetadata());

        app(PurgeCompany::class)->handle($this->company);

        Flux::toast(variant: 'success', text: __(':name permanently deleted.', ['name' => $this->company->name]));

        $this->redirectRoute('admin.companies', navigate: true);
    }

    #[Computed]
    public function purgeConfirmLabel(): string
    {
        return __('Type ":name" to confirm', ['name' => $this->company->name]);
    }

    /**
     * @return array<string, mixed>
     */
    private function companyMetadata(): array
    {
        return [
            'company_id' => $this->company->id,
            'slug' => $this->company->slug,
            'name' => $this->company->name,
            'actor' => auth()->user()?->email,
        ];
    }
}; ?>

<x-pages::admin.layout
    :heading="$company->name"
    :subheading="__('Operator-only controls for this specific company.')"
    content-class="max-w-3xl"
>
    <div class="mb-4">
        <flux:link :href="route('admin.companies')" wire:navigate variant="ghost">
            ← {{ __('All companies') }}
        </flux:link>
    </div>

    @if ($company->trashed())
        <flux:callout variant="danger" icon="trash" class="mb-6" data-test="company-deleted-banner">
            <flux:callout.heading>{{ __('Deleted :date', ['date' => $company->deleted_at?->isoFormat('LL')]) }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Members cannot reach this organization. Restore it to give access back, or permanently delete it below.') }}
            </flux:callout.text>
        </flux:callout>

        @if ($company->members->isEmpty())
            <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6" data-test="company-ownerless-warning">
                <flux:callout.heading>{{ __('No owner on record') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('This organization was deleted before membership rows were preserved, so restoring it will not give anyone access. An owner has to be re-attached manually.') }}
                </flux:callout.text>
            </flux:callout>
        @endif
    @endif

    {{-- Identity --}}
    <div class="space-y-4 rounded-lg border border-border p-4">
        <flux:heading size="sm">{{ __('Identity') }}</flux:heading>
        <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-muted-foreground">{{ __('Slug') }}</dt>
                <dd class="font-mono">{{ $company->slug }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ __('Owner') }}</dt>
                <dd>{{ $company->members->first()?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ __('Created') }}</dt>
                <dd>{{ $company->created_at?->isoFormat('LL') ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ __('Country') }}</dt>
                <dd>{{ $company->address_country ?? '—' }}</dd>
            </div>
        </dl>
    </div>

    {{-- Payroll override --}}
    <div class="mt-6 space-y-3 rounded-lg border border-border p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <flux:heading size="sm">{{ __('Payroll access') }}</flux:heading>
                <flux:text class="mt-1 text-sm text-muted-foreground">
                    {{ __('Grant payroll to this company even when the platform-wide Payroll section is turned off. Implicitly enables the per-company payroll feature.') }}
                </flux:text>
                @if ($company->payroll_admin_enabled_at)
                    <flux:text class="mt-2 text-xs text-muted-foreground">
                        {{ __('Granted :when by :by', [
                            'when' => $company->payroll_admin_enabled_at->isoFormat('LL'),
                            'by' => $company->payroll_admin_enabled_by ?? '—',
                        ]) }}
                    </flux:text>
                @endif
            </div>
            <div class="flex items-center gap-2">
                @if ($company->payroll_admin_enabled_at)
                    <flux:badge color="green" size="sm">{{ __('Enabled') }}</flux:badge>
                @else
                    <flux:badge color="zinc" size="sm">{{ __('Off') }}</flux:badge>
                @endif
                <flux:switch
                    :checked="$company->payroll_admin_enabled_at !== null"
                    wire:click="togglePayrollOverride"
                    data-test="toggle-payroll-override"
                />
            </div>
        </div>
    </div>

    {{-- Danger zone: delete / restore / purge --}}
    <div class="mt-6 space-y-3 rounded-lg border border-red-500/40 p-4">
        <flux:heading size="sm">{{ __('Danger zone') }}</flux:heading>

        @if ($company->trashed())
            <flux:text class="text-sm text-muted-foreground">
                {{ __('Restoring gives every member their access back exactly as it was. Permanent deletion destroys the ledger, every document and every uploaded file, and cannot be undone.') }}
            </flux:text>

            <div class="flex flex-wrap gap-2">
                <flux:button
                    wire:click="restoreCompany"
                    wire:confirm="{{ __('Restore :name? Members will get access back immediately.', ['name' => $company->name]) }}"
                    variant="primary"
                    size="sm"
                    data-test="restore-company"
                >
                    {{ __('Restore organization') }}
                </flux:button>

                <flux:modal.trigger name="purge-company">
                    <flux:button variant="danger" size="sm" data-test="purge-company">
                        {{ __('Delete permanently') }}
                    </flux:button>
                </flux:modal.trigger>
            </div>
        @else
            <flux:text class="text-sm text-muted-foreground">
                {{ __('Marks the organization deleted. Members lose access immediately, nothing is destroyed, and it can be restored from this page.') }}
            </flux:text>

            <flux:button
                wire:click="deleteCompany"
                wire:confirm="{{ __('Mark :name as deleted? Members will lose access immediately. This can be undone.', ['name' => $company->name]) }}"
                variant="danger"
                size="sm"
                data-test="delete-company"
            >
                {{ __('Mark as deleted') }}
            </flux:button>
        @endif
    </div>

    <flux:modal name="purge-company" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form wire:submit="purgeCompany" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Permanently delete this organization?') }}</flux:heading>
                <flux:subheading>
                    {{ __('This destroys the general ledger, every document, every uploaded file and every backup for ":name". It cannot be undone.', ['name' => $company->name]) }}
                </flux:subheading>
            </div>

            <flux:input
                wire:model="purgeName"
                :label="$this->purgeConfirmLabel"
                required
                data-test="purge-company-name"
            />

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" type="submit" data-test="purge-company-confirm">
                    {{ __('Delete permanently') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</x-pages::admin.layout>
