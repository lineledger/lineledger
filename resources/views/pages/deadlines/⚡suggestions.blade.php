<?php

use App\Actions\Deadlines\AddSuggestedDeadlines;
use App\Enums\Activity;
use App\Enums\Country;
use App\Enums\OrganizationType;
use App\Enums\PermissionAction;
use App\Livewire\Attributes\Requires;
use App\Models\Company;
use App\Models\User;
use App\Services\Deadlines\DeadlineAssignees;
use App\Services\Deadlines\DeadlineRuleDescriber;
use App\Services\Deadlines\DeadlineScheduleCalculator;
use App\Services\Deadlines\Suggestions\DeadlineSuggester;
use App\Services\Deadlines\Suggestions\SuggestedDeadline;
use App\Services\Deadlines\Suggestions\SuggestionChoices;
use App\Support\Deadlines\DeadlineDates;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Add suggested deadlines')] class extends Component {
    public Company $company;

    public string $gst_frequency = 'quarterly';

    public string $pst_frequency = 'monthly';

    public bool $ccpc_three_month_balance = false;

    public string $instalment_method = 'monthly';

    public string $partnership_members = 'individuals';

    public string $vat_stagger = '1';

    public string $us_corporation_type = 'c_corp';

    public string $state_sales_tax_frequency = 'monthly';

    public ?int $state_sales_tax_day = null;

    public string $confirmation_statement_date = '';

    /** @var list<string> template keys ticked */
    public array $selected = [];

    public ?int $assigned_user_id = null;

    public string $track_from = '';

    /** @var list<string> keys offered so far, so a newly offered one gets its default tick */
    #[Locked]
    public array $offered = [];

    public function mount(Company $company): void
    {
        $this->company = $company;
        $this->track_from = $company->currentDateTime()->toDateString();
        $this->assigned_user_id = DeadlineAssignees::isEligible($company, auth()->id()) ? auth()->id() : null;
        $this->syncSelection();
    }

    /**
     * A choice changed: the offered list may have changed with it.
     */
    public function updated(string $property): void
    {
        if (! in_array($property, ['selected', 'assigned_user_id', 'track_from'], true) && ! str_starts_with($property, 'selected.')) {
            unset($this->suggestions);
            $this->syncSelection();
        }
    }

    #[Requires(Activity::Deadlines, PermissionAction::Create)]
    public function add(AddSuggestedDeadlines $action)
    {
        $this->validate([
            'track_from' => ['required', 'date_format:Y-m-d'],
            'selected' => ['array'],
            'selected.*' => ['string'],
        ]);

        $created = $action->handle(
            $this->company,
            $this->choices(),
            array_values($this->selected),
            $this->assigned_user_id ?: null,
            $this->track_from,
        );

        Flux::toast(variant: 'success', text: trans_choice(':count deadline added.|:count deadlines added.', count($created), ['count' => count($created)]));

        $this->redirectRoute('deadlines.index', ['company' => $this->company, 'view' => 'list'], navigate: true);
    }

    public function choices(): SuggestionChoices
    {
        return SuggestionChoices::fromArray([
            'gst_frequency' => $this->gst_frequency,
            'pst_frequency' => $this->pst_frequency,
            'ccpc_three_month_balance' => $this->ccpc_three_month_balance,
            'instalment_method' => $this->instalment_method,
            'partnership_members' => $this->partnership_members,
            'vat_stagger' => $this->vat_stagger,
            'us_corporation_type' => $this->us_corporation_type,
            'state_sales_tax_frequency' => $this->state_sales_tax_frequency,
            'state_sales_tax_day' => $this->state_sales_tax_day,
            'confirmation_statement_date' => $this->confirmation_statement_date,
        ]);
    }

    /**
     * @return list<SuggestedDeadline>
     */
    #[Computed]
    public function suggestions(): array
    {
        return DeadlineSuggester::for($this->company, $this->choices());
    }

    /**
     * @return Collection<string, list<SuggestedDeadline>>
     */
    #[Computed]
    public function grouped(): Collection
    {
        return collect($this->suggestions)->groupBy(fn (SuggestedDeadline $s): string => $s->agency);
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function added(): array
    {
        return DeadlineSuggester::addedKeys();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function assignees(): Collection
    {
        return DeadlineAssignees::for($this->company);
    }

    #[Computed]
    public function jurisdiction(): Country
    {
        return $this->company->jurisdiction ?? Country::Canada;
    }

    #[Computed]
    public function orgType(): OrganizationType
    {
        return $this->company->organization_type ?? OrganizationType::Other;
    }

    public function describe(SuggestedDeadline $suggestion): string
    {
        return DeadlineRuleDescriber::describe($suggestion->rule());
    }

    public function nextDue(SuggestedDeadline $suggestion): ?CarbonImmutable
    {
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->track_from) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $this->track_from)
            : $this->company->currentDateTime()->startOfDay();

        return app(DeadlineScheduleCalculator::class)->upcoming($suggestion->rule(), $from, 1)[0]->dueDate ?? null;
    }

    private function syncSelection(): void
    {
        $keys = array_map(fn (SuggestedDeadline $s): string => $s->key, $this->suggestions);
        $added = $this->added;

        $kept = array_values(array_intersect($this->selected, $keys));

        foreach ($this->suggestions as $suggestion) {
            if (! in_array($suggestion->key, $this->offered, true) && $suggestion->defaultOn && ! in_array($suggestion->key, $added, true)) {
                $kept[] = $suggestion->key;
            }
        }

        $this->selected = array_values(array_unique(array_diff($kept, $added)));
        $this->offered = array_values(array_unique([...$this->offered, ...$keys]));
    }
}; ?>

<section class="mx-auto w-full max-w-4xl">
    <a href="{{ route('deadlines.index', ['company' => $company]) }}" wire:navigate class="text-sm text-muted-foreground hover:underline">← {{ __('Deadlines') }}</a>
    <flux:heading size="xl" level="1" class="mt-1" data-test="page-title">{{ __('Add suggested deadlines') }}</flux:heading>
    <flux:subheading class="mb-6">
        {{ __('Worked out from this organization’s country, province or state, type, fiscal year and payroll. Check each one against your own filing requirements; you can change any of them afterwards.') }}
    </flux:subheading>

    <div class="mb-6 rounded-lg border border-border p-4" data-test="suggestion-choices">
        <p class="mb-3 text-sm font-medium">{{ __('A few things LineLedger doesn’t know') }}</p>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @if ($this->jurisdiction === Country::Canada)
                <flux:select wire:model.live="gst_frequency" :label="__('GST/HST filing period')" data-test="choice-gst-frequency">
                    <flux:select.option value="monthly">{{ __('Monthly') }}</flux:select.option>
                    <flux:select.option value="quarterly">{{ __('Quarterly') }}</flux:select.option>
                    <flux:select.option value="annual">{{ __('Yearly') }}</flux:select.option>
                </flux:select>

                @if ($company->provincialSalesTax() !== null && $company->provincialSalesTax() !== \App\Support\Tax\ProvincialSalesTax::Quebec)
                    <flux:select wire:model.live="pst_frequency" :label="__(':tax filing period', ['tax' => $company->provincialSalesTax()->taxLabel()])" data-test="choice-pst-frequency">
                        <flux:select.option value="monthly">{{ __('Monthly') }}</flux:select.option>
                        <flux:select.option value="quarterly">{{ __('Quarterly') }}</flux:select.option>
                        <flux:select.option value="semi_annual">{{ __('Twice a year') }}</flux:select.option>
                        <flux:select.option value="annual">{{ __('Yearly') }}</flux:select.option>
                    </flux:select>
                @endif

                @if ($this->orgType === OrganizationType::Corporation)
                    <flux:select wire:model.live="instalment_method" :label="__('Corporate instalments')" data-test="choice-instalments">
                        <flux:select.option value="monthly">{{ __('Monthly') }}</flux:select.option>
                        <flux:select.option value="quarterly">{{ __('Quarterly (eligible CCPCs)') }}</flux:select.option>
                    </flux:select>
                    <flux:checkbox wire:model.live="ccpc_three_month_balance" :label="__('CCPC with three months to pay the balance')" data-test="choice-ccpc" />
                @endif

                @if ($this->orgType === OrganizationType::Partnership)
                    <flux:select wire:model.live="partnership_members" :label="__('Partners are')" data-test="choice-partners">
                        <flux:select.option value="individuals">{{ __('Individuals') }}</flux:select.option>
                        <flux:select.option value="corporations">{{ __('Corporations') }}</flux:select.option>
                    </flux:select>
                @endif
            @endif

            @if ($this->jurisdiction === Country::UnitedKingdom)
                <flux:select wire:model.live="vat_stagger" :label="__('VAT quarters end in')" data-test="choice-vat-stagger">
                    <flux:select.option value="1">{{ __('March, June, September, December') }}</flux:select.option>
                    <flux:select.option value="2">{{ __('April, July, October, January') }}</flux:select.option>
                    <flux:select.option value="3">{{ __('May, August, November, February') }}</flux:select.option>
                </flux:select>
                @if ($this->orgType !== OrganizationType::SoleProprietorship && $this->orgType !== OrganizationType::Partnership)
                    <flux:input type="date" wire:model.live="confirmation_statement_date" :label="__('Confirmation statement due date')" :description="__('Optional. From Companies House.')" data-test="choice-confirmation-date" />
                @endif
            @endif

            @if ($this->jurisdiction === Country::UnitedStates)
                @if ($this->orgType === OrganizationType::Corporation)
                    <flux:select wire:model.live="us_corporation_type" :label="__('Corporation type')" data-test="choice-us-corp">
                        <flux:select.option value="c_corp">{{ __('C corporation') }}</flux:select.option>
                        <flux:select.option value="s_corp">{{ __('S corporation') }}</flux:select.option>
                    </flux:select>
                @endif
                <flux:select wire:model.live="state_sales_tax_frequency" :label="__('State sales tax filing period')" data-test="choice-sales-tax-frequency">
                    <flux:select.option value="monthly">{{ __('Monthly') }}</flux:select.option>
                    <flux:select.option value="quarterly">{{ __('Quarterly') }}</flux:select.option>
                    <flux:select.option value="annual">{{ __('Yearly') }}</flux:select.option>
                </flux:select>
                <flux:input type="number" min="1" max="31" wire:model.live.debounce.400ms="state_sales_tax_day" :label="__('State sales tax due day')" :description="__('Day of the month after each period.')" data-test="choice-sales-tax-day" />
                <flux:error name="state_sales_tax_day" />
            @endif
        </div>
    </div>

    <div class="space-y-6" data-test="suggestion-list">
        @forelse ($this->grouped as $agency => $items)
            <div wire:key="agency-{{ md5($agency) }}">
                <flux:heading size="sm" class="mb-2">{{ $agency }}</flux:heading>
                <ul class="divide-y divide-border rounded-lg border border-border">
                    @foreach ($items as $suggestion)
                        @php($isAdded = in_array($suggestion->key, $this->added, true))
                        @php($next = $this->nextDue($suggestion))
                        <li class="flex gap-3 p-3 {{ $isAdded ? 'opacity-60' : '' }}" wire:key="sugg-{{ $suggestion->key }}" data-test="suggestion" data-key="{{ $suggestion->key }}">
                            <div class="pt-0.5">
                                @if ($isAdded)
                                    <flux:icon.check-circle class="size-5 text-green-600" />
                                @else
                                    <flux:checkbox wire:model.live="selected" value="{{ $suggestion->key }}" :aria-label="$suggestion->title" data-test="suggestion-checkbox" />
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">{{ $suggestion->title }}</span>
                                    <flux:badge size="sm" :color="$suggestion->category->color()">{{ $suggestion->category->label() }}</flux:badge>
                                    @if ($isAdded)
                                        <flux:badge size="sm" color="green">{{ __('Already added') }}</flux:badge>
                                    @endif
                                </div>
                                <p class="text-sm text-muted-foreground">{{ $suggestion->why }}</p>
                                <p class="mt-1 text-sm">{{ $this->describe($suggestion) }}</p>
                                @if ($next)
                                    <p class="text-xs text-muted-foreground">{{ __('Next due :date', ['date' => DeadlineDates::mediumWithWeekday($next)]) }}</p>
                                @endif
                                @if ($suggestion->note)
                                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">{{ $suggestion->note }}</p>
                                @endif
                                @if ($suggestion->sourceUrl)
                                    <a href="{{ $suggestion->sourceUrl }}" target="_blank" rel="noopener noreferrer" class="mt-1 inline-block text-xs text-accent hover:underline">{{ __('Official source') }}</a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @empty
            <div class="rounded-lg border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                {{ __('Nothing to suggest from this organization’s setup. Add deadlines by hand instead.') }}
            </div>
        @endforelse
    </div>

    <div class="sticky bottom-0 mt-6 flex flex-col gap-4 border-t border-border bg-background py-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <flux:select wire:model="assigned_user_id" :label="__('Assign them to')" data-test="suggestion-assignee">
                <flux:select.option value="">{{ __('Unassigned') }}</flux:select.option>
                @foreach ($this->assignees as $member)
                    <flux:select.option value="{{ $member->id }}">{{ $member->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input type="date" wire:model.live="track_from" :label="__('Track from')" :description="__('Earlier due dates aren’t added.')" data-test="suggestion-track-from" />
        </div>
        <div class="flex flex-col items-end gap-1">
            <flux:error name="selected" />
            <flux:button variant="primary" wire:click="add" :disabled="count($selected) === 0" data-test="suggestion-add">
                {{ trans_choice('Add :count deadline|Add :count deadlines', count($selected), ['count' => count($selected)]) }}
            </flux:button>
        </div>
    </div>
</section>
