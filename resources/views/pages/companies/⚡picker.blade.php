<?php

use App\Support\UserCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Select an organization')] class extends Component {
    /**
     * @return Collection<int, UserCompany>
     */
    #[Computed]
    public function companies(): Collection
    {
        return Auth::user()->toUserCompanies(includeCurrent: true);
    }
}; ?>

<section class="mx-auto max-w-2xl p-8">
    <flux:heading size="xl" level="1" class="mb-2">{{ __('Select an organization') }}</flux:heading>
    <flux:subheading class="mb-6">{{ __('Choose which organization you want to work in. You can open different organizations in separate tabs.') }}</flux:subheading>

    <div class="space-y-2">
        @foreach ($this->companies as $company)
            <a
                href="{{ route('dashboard', ['company' => $company->slug]) }}"
                wire:navigate
                class="flex items-center justify-between rounded-lg border border-border bg-card p-4 hover:bg-muted"
                data-test="picker-company"
            >
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-medium">{{ $company->name }}</span>
                        @if ($company->isPersonal)
                            <flux:badge color="zinc" size="sm">{{ __('Personal') }}</flux:badge>
                        @endif
                    </div>
                    <flux:text class="text-sm text-muted-foreground">{{ $company->roleLabel }}</flux:text>
                </div>
                <flux:icon name="chevron-right" class="size-5 text-muted-foreground" />
            </a>
        @endforeach
    </div>

    <div class="mt-6 flex flex-wrap gap-2">
        <flux:button variant="primary" icon="plus" :href="route('companies.setup')" wire:navigate data-test="picker-new-company">{{ __('New organization') }}</flux:button>
        <flux:button variant="ghost" icon="arrow-up-tray" :href="route('companies.restore')" wire:navigate data-test="picker-restore-backup">{{ __('Restore from backup') }}</flux:button>
    </div>
</section>
