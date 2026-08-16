<?php

use App\Enums\JurisdictionCapability;
use App\Models\Company;
use App\Support\Tax\FilingProfile;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Tax & filing')] class extends Component {
    public Company $company;

    public function mount(Company $company): void
    {
        // CRA filing guidance is Canada-only.
        abort_unless($company->supports(JurisdictionCapability::CraTaxFiling), 404);

        $this->company = $company;
    }

    public function with(): array
    {
        $profile = FilingProfile::for($this->company);

        return [
            'profile' => $profile,
            'forms' => $profile->forms(),
        ];
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout
        :heading="__('Tax & filing')"
        :subheading="__('The CRA returns that apply to your organization, based on your entity type and legal tier.')"
        contentClass="max-w-3xl"
    >
        <div class="space-y-8">
            {{-- Entity summary --}}
            <div class="rounded-lg border border-border p-4">
                <flux:heading size="sm">{{ __('Your organization') }}</flux:heading>
                <dl class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">{{ __('Type') }}</dt>
                        <dd class="text-sm">{{ $company->organization_type?->label() ?? __('Not set') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">{{ __('Legal tier') }}</dt>
                        <dd class="text-sm">{{ $company->resolvedLegalStructure()?->label() ?? __('—') }}</dd>
                    </div>
                </dl>
                <flux:text class="mt-3 text-sm text-muted-foreground">
                    @if ($company->organization_type === null)
                        {{ __('Set your organization type on the') }}
                        <flux:link :href="route('companies.edit', ['company' => $company->slug])" wire:navigate>{{ __('company profile') }}</flux:link>.
                    @elseif ($company->organization_type->isNonProfit())
                        {{ __('Change your legal tier on the') }}
                        <flux:link :href="route('companies.edit', ['company' => $company->slug])" wire:navigate>{{ __('company profile') }}</flux:link>.
                    @else
                        {{ __('The organization type is set once and cannot be changed.') }}
                    @endif
                </flux:text>
            </div>

            {{-- Applicable forms --}}
            <div>
                <flux:heading size="sm" class="mb-3">{{ __('Forms you file') }}</flux:heading>

                @forelse ($forms as $entry)
                    @php($form = $entry['form'])
                    <div class="mb-3 rounded-lg border border-border p-4" data-test="filing-form" wire:key="form-{{ $form->value }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <flux:heading size="sm">{{ $form->code() }}</flux:heading>
                                    @if ($entry['primary'])
                                        <flux:badge color="sky" size="sm">{{ __('Primary') }}</flux:badge>
                                    @else
                                        <flux:badge color="zinc" size="sm">{{ __('Information return') }}</flux:badge>
                                    @endif
                                </div>
                                <flux:text class="mt-0.5 font-medium">{{ $form->label() }}</flux:text>
                                <flux:text class="mt-1 text-sm text-muted-foreground">{{ $form->description() }}</flux:text>
                                <flux:text class="mt-1 text-sm text-muted-foreground">{{ $entry['note'] }}</flux:text>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-2">
                                @if ($form->reportRoute())
                                    <flux:button
                                        size="sm"
                                        variant="primary"
                                        icon="document-chart-bar"
                                        :href="route($form->reportRoute(), ['company' => $company->slug])"
                                        wire:navigate
                                        data-test="filing-form-report"
                                    >{{ __('Open report') }}</flux:button>
                                @endif
                                <flux:link :href="$form->craReference()" external class="text-xs">{{ __('CRA form page') }}</flux:link>
                            </div>
                        </div>
                    </div>
                @empty
                    <flux:callout icon="information-circle">
                        <flux:callout.text>{{ __('No CRA income-tax return is generated for this organization type. You may still owe sales tax — see the Sales Tax report.') }}</flux:callout.text>
                    </flux:callout>
                @endforelse
            </div>

            <flux:callout icon="information-circle" variant="secondary">
                <flux:callout.text>{{ __('This is general guidance, not tax advice. Filing thresholds and obligations vary — confirm your requirements with the CRA or your accountant.') }}</flux:callout.text>
            </flux:callout>
        </div>
    </x-pages::settings.layout>
</section>
