<?php

use App\Enums\TaxReturnStatus;
use App\Models\Company;
use App\Models\TaxAgency;
use App\Models\TaxReturn;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Tax\TaxReturnBuilder;
use App\Services\Tax\TaxReturnFiler;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Tax return')] class extends Component {
    public Company $company;

    public ?TaxReturn $taxReturn = null;

    public ?int $tax_agency_id = null;

    public string $tax_return_no = '';

    public string $period_start = '';

    public string $period_end = '';

    public string $filing_reference = '';

    public string $notes = '';

    /**
     * Journal line IDs the user has unchecked — omitted from totals and the
     * filed snapshot. Useful when imported opening balances surface lines that
     * don't belong to the current filing period.
     *
     * @var int[]
     */
    public array $excludedLineIds = [];

    public function mount(Company $company, ?TaxReturn $tax_return = null): void
    {
        $this->company = $company;

        if ($tax_return && $tax_return->exists) {
            abort_if($tax_return->status !== TaxReturnStatus::Draft, 403, 'Only draft returns can be edited.');

            $this->taxReturn = $tax_return;
            $this->tax_agency_id = $tax_return->tax_agency_id;
            $this->tax_return_no = $tax_return->tax_return_no;
            $this->period_start = $tax_return->period_start->toDateString();
            $this->period_end = $tax_return->period_end->toDateString();
            $this->filing_reference = $tax_return->filing_reference ?? '';
            $this->notes = $tax_return->notes ?? '';
            $this->excludedLineIds = array_map('intval', $tax_return->excluded_journal_line_ids ?? []);
        } else {
            $defaultStart = $this->company->currentDateTime()->startOfQuarter()->subQuarter();
            $this->period_start = $defaultStart->toDateString();
            $this->period_end = $defaultStart->copy()->endOfQuarter()->toDateString();
            $this->tax_return_no = app(DocumentNumberGenerator::class)
                ->next($company, TaxReturn::class, 'tax_return_no', 'TR');
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, TaxAgency>
     */
    #[Computed]
    public function agencies()
    {
        return TaxAgency::query()->where('is_active', true)->orderBy('name')->get();
    }

    /**
     * @return array{lines: \Illuminate\Support\Collection<int, array<string, mixed>>, collected: int, paid: int, net: int}
     */
    #[Computed]
    public function preview(): array
    {
        if (! $this->tax_agency_id || ! $this->period_start || ! $this->period_end) {
            return ['lines' => collect(), 'collected' => 0, 'paid' => 0, 'net' => 0];
        }

        $agency = TaxAgency::query()->find($this->tax_agency_id);

        if (! $agency) {
            return ['lines' => collect(), 'collected' => 0, 'paid' => 0, 'net' => 0];
        }

        $builder = app(TaxReturnBuilder::class);
        $start = CarbonImmutable::parse($this->period_start);
        $end = CarbonImmutable::parse($this->period_end);

        $lines = $builder->build($agency, $start, $end);

        $excluded = $this->excludedLineIds;
        $included = $lines->reject(fn ($line) => in_array((int) $line['journal_line_id'], $excluded, true));

        $collected = (int) $included->where('bucket', 'collected')->sum('amount_cents');
        $paid = (int) $included->where('bucket', 'paid')->sum('amount_cents');

        return [
            'lines' => $lines,
            'collected' => $collected,
            'paid' => $paid,
            'net' => $collected - $paid,
        ];
    }

    public function toggleLine(int $journalLineId): void
    {
        if (in_array($journalLineId, $this->excludedLineIds, true)) {
            $this->excludedLineIds = array_values(array_diff($this->excludedLineIds, [$journalLineId]));
        } else {
            $this->excludedLineIds[] = $journalLineId;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedAttributes(): array
    {
        return $this->validate([
            'tax_agency_id' => ['required', Rule::exists('tax_agencies', 'id')->where('company_id', $this->company->id)],
            'tax_return_no' => [
                'required', 'string', 'max:40',
                Rule::unique('tax_returns', 'tax_return_no')
                    ->where('company_id', $this->company->id)
                    ->ignore($this->taxReturn?->id),
            ],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'filing_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    protected function persistDraft(): TaxReturn
    {
        $data = $this->validatedAttributes();

        // Only keep exclusions that match a line currently in the preview, so a
        // changed period or agency never leaves orphaned IDs on the record.
        $visibleIds = $this->preview['lines']->pluck('journal_line_id')->map('intval')->all();
        $data['excluded_journal_line_ids'] = array_values(array_intersect($this->excludedLineIds, $visibleIds));

        return $this->taxReturn = app(\App\Actions\Tax\SaveTaxReturn::class)->handle($data, $this->taxReturn);
    }

    public function saveDraft(): void
    {
        $return = $this->persistDraft();

        Flux::toast(variant: 'success', text: __('Draft saved.'));
        $this->redirectRoute('tax-returns.show', ['company' => $this->company->slug, 'tax_return' => $return->id], navigate: true);
    }

    public function fileReturn(TaxReturnFiler $filer): void
    {
        $return = $this->persistDraft();

        try {
            $filed = $filer->file($return);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Tax return filed.'));
        $this->redirectRoute('tax-returns.show', ['company' => $this->company->slug, 'tax_return' => $filed->id], navigate: true);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $taxReturn ? __('Edit tax return') : __('File a tax return') }}</flux:heading>
            <flux:subheading>{{ __('Pick a tax agency and period. Preview the contributing transactions before filing — once filed, the snapshot is permanent and the period is locked for that agency.') }}</flux:subheading>
        </div>
        <div class="flex gap-2">
            <flux:button variant="ghost" :href="route('tax-returns.index', ['company' => $company->slug])" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
        <flux:select wire:model.live="tax_agency_id" :label="__('Tax agency')" data-test="tax-agency-select">
            <flux:select.option value="">{{ __('Choose an agency…') }}</flux:select.option>
            @foreach ($this->agencies as $agency)
                <flux:select.option :value="$agency->id">{{ $agency->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input :label="__('Return #')" wire:model="tax_return_no" data-test="tax-return-no-input" />
        <flux:input :label="__('Period start')" type="date" wire:model.live="period_start" data-test="period-start-input" />
        <flux:input :label="__('Period end')" type="date" wire:model.live="period_end" data-test="period-end-input" />
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
        <flux:input :label="__('Filing reference (optional)')" wire:model="filing_reference" placeholder="{{ __('Government confirmation #') }}" />
        <flux:textarea :label="__('Notes')" wire:model="notes" rows="2" />
    </div>

    <div class="mt-8">
        <flux:heading size="lg">{{ __('Preview') }}</flux:heading>
        <flux:subheading>{{ __('Live list of every journal line that will be snapshotted on filing. Uncheck a line to leave it out — handy for imported balances that don’t belong to this period.') }}</flux:subheading>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-lg border border-border p-4">
                <div class="text-xs uppercase text-muted-foreground">{{ __('Collected') }}</div>
                <div class="text-2xl font-mono font-semibold" data-test="preview-collected">{{ number_format($this->preview['collected'] / 100, 2) }}</div>
            </div>
            <div class="rounded-lg border border-border p-4">
                <div class="text-xs uppercase text-muted-foreground">{{ __('Paid (ITCs)') }}</div>
                <div class="text-2xl font-mono font-semibold" data-test="preview-paid">{{ number_format($this->preview['paid'] / 100, 2) }}</div>
            </div>
            <div class="rounded-lg border border-border p-4">
                <div class="text-xs uppercase text-muted-foreground">{{ __('Net owing') }}</div>
                <div class="text-2xl font-mono font-semibold" data-test="preview-net">{{ number_format($this->preview['net'] / 100, 2) }}</div>
            </div>
        </div>

        <div class="mt-4 overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left w-10">{{ __('Include') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Entry #') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Document') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Bucket') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->preview['lines'] as $line)
                        @php($excluded = in_array((int) $line['journal_line_id'], $excludedLineIds, true))
                        <tr wire:key="preview-line-{{ $line['journal_line_id'] }}" @class(['opacity-40' => $excluded])>
                            <td class="px-4 py-2">
                                <flux:checkbox
                                    :checked="! $excluded"
                                    wire:click="toggleLine({{ $line['journal_line_id'] }})"
                                    data-test="include-line-{{ $line['journal_line_id'] }}"
                                />
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">{{ $line['entry_date']->toDateString() }}</td>
                            <td class="px-4 py-2 font-mono">{{ $line['entry_no'] }}</td>
                            <td class="px-4 py-2">{{ $line['doc_label'] }}</td>
                            <td class="px-4 py-2">
                                @if ($line['bucket'] === 'collected')
                                    <flux:badge color="emerald">{{ __('Collected') }}</flux:badge>
                                @else
                                    <flux:badge color="amber">{{ __('Paid') }}</flux:badge>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($line['amount_cents'] / 100, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ __('No transactions in this period for this agency.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6 flex justify-end gap-2">
        <flux:button variant="filled" wire:click="saveDraft" data-test="save-draft-button">{{ __('Save draft') }}</flux:button>
        <flux:button variant="primary" wire:click="fileReturn" wire:confirm="{{ __('File this return? The snapshot is permanent and the period will be locked for this agency.') }}" data-test="file-return-button">{{ __('File return') }}</flux:button>
    </div>
</section>
