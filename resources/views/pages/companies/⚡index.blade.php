<?php

use App\Actions\Companies\CreateCompany;
use App\Enums\Country;
use App\Rules\CompanyName;
use App\Support\UserCompany;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Organizations')] class extends Component {
    public string $name = '';

    public string $country = 'CA';

    public string $region = '';

    public string $currency = 'CAD';

    public string $timezone = '';

    public function mount(): void
    {
        $this->timezone = (Country::tryFrom($this->country) ?? Country::Canada)->defaultTimezone();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function regions(): array
    {
        return $this->countryEnum()?->regions() ?? [];
    }

    public function countryEnum(): ?Country
    {
        return Country::tryFrom($this->country);
    }

    public function updatedCountry(): void
    {
        $country = $this->countryEnum();

        if ($country) {
            $this->currency = $country->defaultCurrencyCode();
            $this->region = '';
            $this->timezone = $country->defaultTimezone();
        }
    }

    public function createCompany(CreateCompany $createCompany): void
    {
        $country = $this->countryEnum() ?? Country::Canada;
        $regions = $country->regions();

        $rules = [
            'name' => ['required', 'string', 'max:255', new CompanyName],
            'country' => ['required', Rule::in(array_map(fn ($c) => $c->value, Country::cases()))],
            'currency' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
        ];

        $rules['region'] = $regions !== []
            ? ['nullable', Rule::in(array_keys($regions))]
            : ['nullable', 'string', 'max:100'];

        $validated = $this->validate($rules);

        $company = $createCompany->handle(
            user: Auth::user(),
            name: $validated['name'],
            country: $country,
            regionCode: $validated['region'] ?: null,
            currencyCode: mb_strtoupper($validated['currency']),
            timezone: $validated['timezone'],
        );

        $this->dispatch('close-modal', name: 'create-company');

        $this->reset(['name', 'region']);

        Flux::toast(variant: 'success', text: __('Company created.'));

        $this->redirectRoute('companies.edit', ['company' => $company->slug], navigate: true);
    }

    /**
     * @return Collection<int, UserCompany>
     */
    #[Computed]
    public function companies(): Collection
    {
        return Auth::user()->toUserCompanies(includeCurrent: true);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Organizations') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Organizations')" :subheading="__('Manage your organizations and memberships')">
        <div class="flex items-center justify-end">
            <flux:modal.trigger name="create-company">
                <flux:button variant="primary" icon="plus" x-data="" x-on:click.prevent="$dispatch('open-modal', 'create-company')" data-test="companies-new-company-button">
                    {{ __('New organization') }}
                </flux:button>
            </flux:modal.trigger>
        </div>

        <div class="mt-6 space-y-3">
            @forelse ($this->companies as $company)
                <div class="flex items-center justify-between rounded-lg border border-border bg-card p-4" data-test="company-row">
                    <div class="flex items-center gap-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-medium">{{ $company->name }}</span>
                                @if ($company->isPersonal)
                                    <flux:badge color="zinc">{{ __('Personal') }}</flux:badge>
                                @endif
                            </div>
                            <flux:text class="text-sm text-muted-foreground">{{ $company->roleLabel }}</flux:text>
                        </div>
                    </div>

                    @php
                        $canEditCompany = in_array($company->role, ['owner', 'admin'], true);
                    @endphp
                    <div class="flex items-center gap-2">
                        <flux:tooltip :content="$canEditCompany ? __('Edit organization') : __('View organization')">
                            <flux:button
                                variant="ghost"
                                size="sm"
                                :icon="$canEditCompany ? 'pencil' : 'eye'"
                                :href="route('companies.edit', $company->slug)"
                                wire:navigate
                                :data-test="$canEditCompany ? 'company-edit-button' : 'company-view-button'"
                            />
                        </flux:tooltip>
                    </div>
                </div>
            @empty
                <flux:text class="py-8 text-center text-muted-foreground">
                    {{ __('You don\'t belong to any organizations yet.') }}
                </flux:text>
            @endforelse
        </div>
    </x-pages::settings.layout>

    <flux:modal name="create-company" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form wire:submit="createCompany" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Create a new organization') }}</flux:heading>
                <flux:subheading>{{ __('Pick a country and base currency to seed the chart of accounts and tax setup.') }}</flux:subheading>
            </div>

            <flux:input wire:model="name" :label="__('Organization name')" type="text" autofocus data-test="create-company-name" />

            <flux:select wire:model.live="country" :label="__('Country')" data-test="create-company-country">
                @foreach (Country::options() as $option)
                    <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($this->regions !== [])
                <flux:select wire:model="region" :label="$this->countryEnum()?->regionLabel() ?? __('Region')" data-test="create-company-region">
                    <flux:select.option value="">{{ __('Select…') }}</flux:select.option>
                    @foreach ($this->regions as $code => $name)
                        <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @else
                <flux:input wire:model="region" :label="$this->countryEnum()?->regionLabel() ?? __('Region')" data-test="create-company-region" />
            @endif

            <flux:select wire:model="currency" :label="__('Base currency')" data-test="create-company-currency">
                <flux:select.option value="CAD">CAD &mdash; Canadian Dollar</flux:select.option>
                <flux:select.option value="USD">USD &mdash; United States Dollar</flux:select.option>
            </flux:select>

            <flux:select wire:model="timezone" :label="__('Timezone')" :description="__('Transaction dates default to today in this timezone.')" data-test="create-company-timezone">
                @foreach (\App\Models\Company::timezoneOptions() as $tzLabel => $tzId)
                    <flux:select.option value="{{ $tzId }}">{{ $tzLabel }}</flux:select.option>
                @endforeach
                @unless (in_array($timezone, \App\Models\Company::timezoneOptions(), true))
                    <flux:select.option value="{{ $timezone }}">{{ $timezone }}</flux:select.option>
                @endunless
            </flux:select>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="primary" type="submit" data-test="create-company-submit">
                    {{ __('Create organization') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
