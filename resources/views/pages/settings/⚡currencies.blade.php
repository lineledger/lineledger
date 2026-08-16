<?php

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Actions\Accounting\RunHomeCurrencyAdjustment;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CurrencyRevaluation;
use App\Models\ExchangeRate;
use App\Services\Currency\ExchangeRateService;
use App\Services\Currency\MissingExchangeRateException;
use App\Support\Currency;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Currencies')] class extends Component {
    public Company $company;

    public string $f_currency_code = '';

    // Manual rate override form.
    public string $r_currency_code = '';

    public string $r_rate = '';

    public string $r_date = '';

    // Period-end revaluation.
    public string $reval_date = '';

    public function mount(Company $company): void
    {
        $this->company = $company;
        $this->reval_date = $company->currentDateTime()->endOfMonth()->toDateString();
        $this->r_date = $company->currentDateTime()->toDateString();
    }

    public function enable(EnableCompanyCurrency $action): void
    {
        $validated = $this->validate([
            'f_currency_code' => ['required', 'string', Rule::in(array_keys($this->availableToAdd()))],
        ]);

        $action->handle($this->company, $validated['f_currency_code']);

        $this->f_currency_code = '';
        unset($this->foreignCurrencies, $this->availableOptions);

        Flux::toast(variant: 'success', text: __(':code enabled.', ['code' => $validated['f_currency_code']]));
    }

    public function deactivate(int $companyCurrencyId): void
    {
        $currency = CompanyCurrency::query()->where('id', $companyCurrencyId)->where('is_home', false)->firstOrFail();
        $currency->update(['is_active' => false]);

        unset($this->foreignCurrencies, $this->availableOptions);

        Flux::toast(variant: 'success', text: __(':code deactivated.', ['code' => $currency->currency_code]));
    }

    public function saveRate(ExchangeRateService $service): void
    {
        $validated = $this->validate([
            'r_currency_code' => ['required', 'string', Rule::in($this->foreignCodes())],
            'r_rate' => ['required', 'numeric', 'gt:0'],
            'r_date' => ['required', 'date'],
        ]);

        $service->setManualRate(
            $this->company,
            $validated['r_currency_code'],
            $validated['r_rate'],
            CarbonImmutable::parse($validated['r_date']),
        );

        $this->r_rate = '';
        unset($this->recentRates);

        Flux::toast(variant: 'success', text: __('Exchange rate saved.'));
    }

    public function runRevaluation(RunHomeCurrencyAdjustment $action): void
    {
        $validated = $this->validate(['reval_date' => ['required', 'date']]);

        try {
            $revaluation = $action->handle($this->company, CarbonImmutable::parse($validated['reval_date']));
        } catch (MissingExchangeRateException $e) {
            Flux::toast(variant: 'danger', text: __('Missing a closing rate — enter a manual rate for that date first.'));

            return;
        } catch (\DomainException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        unset($this->recentRevaluations);

        Flux::toast(
            variant: 'success',
            text: $revaluation === null
                ? __('No revaluation needed — balances already match the closing rates.')
                : __('Home currency adjustment posted for :date.', ['date' => $validated['reval_date']]),
        );
    }

    #[Computed]
    public function recentRevaluations()
    {
        return CurrencyRevaluation::query()
            ->where('company_id', $this->company->id)
            ->orderByDesc('as_of_date')
            ->limit(12)
            ->get();
    }

    /**
     * @return array<string, string>
     */
    public function availableToAdd(): array
    {
        $enabled = $this->company->currencies()->pluck('currency_code')->map(fn ($c) => mb_strtoupper($c))->all();
        $enabled[] = mb_strtoupper((string) $this->company->currency_code);

        return array_filter(
            Currency::selectable(),
            fn (string $label, string $code) => ! in_array($code, $enabled, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return list<string>
     */
    public function foreignCodes(): array
    {
        return $this->foreignCurrencies->pluck('currency_code')->all();
    }

    #[Computed]
    public function foreignCurrencies()
    {
        return $this->company->currencies()
            ->where('is_home', false)
            ->orderBy('currency_code')
            ->get();
    }

    #[Computed]
    public function availableOptions(): array
    {
        return $this->availableToAdd();
    }

    #[Computed]
    public function recentRates()
    {
        return ExchangeRate::query()
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $this->company->id))
            ->where('quote_code', mb_strtoupper((string) $this->company->currency_code))
            ->orderByDesc('rate_date')
            ->orderByDesc('id')
            ->limit(25)
            ->get();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Currencies')" :subheading="__('Transact with customers and vendors in foreign currencies. Your books stay in :home.', ['home' => $company->currency_code])" contentClass="max-w-3xl">
        <div class="space-y-8">
            <div>
                <flux:heading size="sm">{{ __('Home currency') }}</flux:heading>
                <flux:text class="mt-1">{{ $company->currency_code }} — {{ \App\Support\Currency::name($company->currency_code) }}</flux:text>
                <flux:text class="mt-1 text-xs text-muted-foreground">{{ __('The home (functional) currency is fixed and cannot be changed.') }}</flux:text>
            </div>

            <flux:separator />

            <div>
                <flux:heading size="sm">{{ __('Foreign currencies') }}</flux:heading>

                @if ($this->foreignCurrencies->isEmpty())
                    <flux:text class="mt-1 text-muted-foreground">{{ __('No foreign currencies enabled yet.') }}</flux:text>
                @else
                    <flux:table class="mt-3">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Currency') }}</flux:table.column>
                            <flux:table.column>{{ __('AR control') }}</flux:table.column>
                            <flux:table.column>{{ __('AP control') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->foreignCurrencies as $currency)
                                <flux:table.row :key="$currency->id">
                                    <flux:table.cell variant="strong">{{ $currency->currency_code }}</flux:table.cell>
                                    <flux:table.cell>{{ $currency->arAccount?->code }}</flux:table.cell>
                                    <flux:table.cell>{{ $currency->apAccount?->code }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($currency->is_active)
                                            <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if ($currency->is_active)
                                            <flux:button size="xs" variant="ghost" wire:click="deactivate({{ $currency->id }})" wire:confirm="{{ __('Deactivate :code? Existing transactions are unaffected.', ['code' => $currency->currency_code]) }}">{{ __('Deactivate') }}</flux:button>
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif

                @if (! empty($this->availableOptions))
                    <form wire:submit="enable" class="mt-4 flex items-end gap-3">
                        <flux:select wire:model="f_currency_code" :label="__('Add a currency')" class="max-w-xs">
                            <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                            @foreach ($this->availableOptions as $code => $label)
                                <flux:select.option :value="$code">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:button type="submit" variant="primary">{{ __('Enable') }}</flux:button>
                    </form>
                @endif
            </div>

            @if ($this->foreignCurrencies->isNotEmpty())
                <flux:separator />

                <div>
                    <flux:heading size="sm">{{ __('Exchange rates') }}</flux:heading>
                    <flux:text class="mt-1 text-xs text-muted-foreground">{{ __('Rates are :home per 1 foreign unit. Daily rates are fetched automatically; enter a manual rate to override.', ['home' => $company->currency_code]) }}</flux:text>

                    <form wire:submit="saveRate" class="mt-3 flex items-end gap-3">
                        <flux:select wire:model="r_currency_code" :label="__('Currency')" class="max-w-[10rem]">
                            <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                            @foreach ($this->foreignCurrencies as $currency)
                                <flux:select.option :value="$currency->currency_code">{{ $currency->currency_code }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model="r_rate" :label="__('Rate')" type="text" inputmode="decimal" class="max-w-[8rem]" />
                        <flux:input wire:model="r_date" :label="__('Date')" type="date" class="max-w-[12rem]" />
                        <flux:button type="submit">{{ __('Save rate') }}</flux:button>
                    </form>

                    @if ($this->recentRates->isNotEmpty())
                        <flux:table class="mt-4">
                            <flux:table.columns>
                                <flux:table.column>{{ __('Date') }}</flux:table.column>
                                <flux:table.column>{{ __('Pair') }}</flux:table.column>
                                <flux:table.column>{{ __('Rate') }}</flux:table.column>
                                <flux:table.column>{{ __('Source') }}</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach ($this->recentRates as $rate)
                                    <flux:table.row :key="$rate->id">
                                        <flux:table.cell>{{ $rate->rate_date->toDateString() }}</flux:table.cell>
                                        <flux:table.cell>{{ $rate->base_code }} → {{ $rate->quote_code }}</flux:table.cell>
                                        <flux:table.cell variant="strong">{{ rtrim(rtrim($rate->rate, '0'), '.') }}</flux:table.cell>
                                        <flux:table.cell>
                                            <flux:badge size="sm" :color="$rate->source === 'manual' ? 'blue' : 'zinc'">{{ ucfirst($rate->source) }}</flux:badge>
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    @endif
                </div>

                <flux:separator />

                <div>
                    <flux:heading size="sm">{{ __('Period-end revaluation') }}</flux:heading>
                    <flux:text class="mt-1 text-xs text-muted-foreground">{{ __('Restate open foreign balances (AR, AP, bank) at the closing rate as of a date. Posts an unrealized gain/loss entry and an automatic reversal the next day.') }}</flux:text>

                    <form wire:submit="runRevaluation" class="mt-3 flex items-end gap-3">
                        <flux:input wire:model="reval_date" :label="__('As of')" type="date" class="max-w-[12rem]" />
                        <flux:button type="submit">{{ __('Run revaluation') }}</flux:button>
                    </form>

                    @if ($this->recentRevaluations->isNotEmpty())
                        <flux:table class="mt-4">
                            <flux:table.columns>
                                <flux:table.column>{{ __('As of') }}</flux:table.column>
                                <flux:table.column>{{ __('Rates used') }}</flux:table.column>
                                <flux:table.column>{{ __('Entry') }}</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach ($this->recentRevaluations as $reval)
                                    <flux:table.row :key="$reval->id">
                                        <flux:table.cell>{{ $reval->as_of_date->toDateString() }}</flux:table.cell>
                                        <flux:table.cell>
                                            @foreach ($reval->rate_snapshot as $code => $rate)
                                                <flux:badge size="sm" color="zinc">{{ $code }} {{ rtrim(rtrim($rate, '0'), '.') }}</flux:badge>
                                            @endforeach
                                        </flux:table.cell>
                                        <flux:table.cell>{{ $reval->journalEntry?->entry_no }}</flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    @endif
                </div>
            @endif
        </div>
    </x-pages::settings.layout>
</section>
