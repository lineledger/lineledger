<?php

use App\Actions\Payroll\RecordPayrollRemittance;
use App\Enums\AccountSubtype;
use App\Enums\JurisdictionCapability;
use App\Enums\RemittanceAgency;
use App\Enums\RemittanceFrequency;
use App\Enums\RemittanceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\PayrollRemittance;
use App\Services\Payroll\RemittancePeriodResolver;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\WorkersCompRemittanceCalculator;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title("Workers' compensation remittance")] class extends Component {
    public Company $company;

    #[Url(as: 'period')]
    public string $periodKey = '';

    public ?int $f_bank_account_id = null;

    public string $f_payment_date = '';

    public string $f_reference = '';

    public function mount(Company $company): void
    {
        abort_unless($company->supports(JurisdictionCapability::WorkersComp), 404);

        $this->company = $company;

        if ($this->periodKey === '') {
            $this->periodKey = $this->defaultPeriodKey();
        }
    }

    private function frequency(): RemittanceFrequency
    {
        return $this->company->payroll_remittance_frequency ?? RemittanceFrequency::Monthly;
    }

    /** @return array<int, array{start: CarbonImmutable, end: CarbonImmutable, due: CarbonImmutable, label: string, key: string}> */
    #[Computed]
    public function periods(): array
    {
        return app(RemittancePeriodResolver::class)->periods($this->frequency(), $this->company->currentDateTime(), 12);
    }

    private function defaultPeriodKey(): string
    {
        $today = $this->company->currentDateTime();

        foreach ($this->periods() as $period) {
            if ($period['end']->lt($today)) {
                return $period['key'];
            }
        }

        return $this->periods()[0]['key'] ?? $today->startOfMonth()->toDateString();
    }

    /** @return array{start: CarbonImmutable, end: CarbonImmutable, due: CarbonImmutable, label: string, key: string} */
    #[Computed]
    public function selectedPeriod(): array
    {
        foreach ($this->periods() as $period) {
            if ($period['key'] === $this->periodKey) {
                return $period;
            }
        }

        return $this->periods()[0];
    }

    #[Computed]
    public function summary(): array
    {
        $p = $this->selectedPeriod();

        return app(WorkersCompRemittanceCalculator::class)->summary($this->company, $p['start'], $p['end']);
    }

    #[Computed]
    public function rows(): array
    {
        $p = $this->selectedPeriod();

        return app(WorkersCompRemittanceCalculator::class)->rows($this->company, $p['start'], $p['end']);
    }

    #[Computed]
    public function recorded(): ?PayrollRemittance
    {
        return PayrollRemittance::query()
            ->where('agency', RemittanceAgency::WorkersComp->value)
            ->where('status', RemittanceStatus::Paid->value)
            ->whereDate('period_start', $this->selectedPeriod()['key'])
            ->first();
    }

    #[Computed]
    public function bankAccounts()
    {
        return Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->get();
    }

    public function openRecord(): void
    {
        $this->f_payment_date = $this->company->currentDateTime()->toDateString();
        $this->f_bank_account_id = $this->bankAccounts->first()?->id;
        $this->f_reference = '';

        Flux::modal('record-remittance')->show();
    }

    public function record(RecordPayrollRemittance $action): void
    {
        $validated = $this->validate([
            'f_bank_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'f_payment_date' => ['required', 'date'],
            'f_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $p = $this->selectedPeriod();

        try {
            $action->handle([
                'agency' => RemittanceAgency::WorkersComp->value,
                'period_start' => $p['start']->toDateString(),
                'period_end' => $p['end']->toDateString(),
                'due_date' => $p['due']->toDateString(),
                'bank_account_id' => $validated['f_bank_account_id'],
                'payment_date' => $validated['f_payment_date'],
                'reference' => $validated['f_reference'] ?: null,
            ]);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::modal('record-remittance')->close();
        unset($this->recorded);

        Flux::toast(variant: 'success', text: __('Remittance recorded.'));
    }

    public function money(int $cents): string
    {
        return Money::fromCents($cents)->format();
    }

    public function exportCsv()
    {
        $rows = collect($this->rows)->map(fn ($r) => [
            $r['run_no'], $r['pay_date'], $r['employees'],
            CsvExporter::cents($r['assessable_cents']), CsvExporter::cents($r['wc_cents']),
        ]);

        $s = $this->summary;
        $rows->push(['TOTAL', '', $s['employee_count'], CsvExporter::cents($s['gross_assessable_cents']), CsvExporter::cents($s['wc_cents'])]);

        return app(CsvExporter::class)->stream(
            'workers-comp-'.$this->selectedPeriod()['key'].'.csv',
            ['Run #', 'Pay date', 'Employees', 'Assessable', "Workers' comp"],
            $rows,
        );
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __("Workers' compensation remittance") }}</flux:heading>
            <flux:subheading>{{ __('WSIB/WCB employer assessment to remit for a period (rest of Canada; Quebec uses CNESST).') }}</flux:subheading>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <flux:select wire:model.live="periodKey" :label="__('Remitting period')" class="min-w-[200px]">
                @foreach ($this->periods as $period)
                    <flux:select.option value="{{ $period['key'] }}">{{ $period['label'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button variant="ghost" icon="document-text" wire:click="exportCsv">{{ __('CSV') }}</flux:button>
        </div>
    </div>

    @php($s = $this->summary)
    @php($period = $this->selectedPeriod())
    @php($recorded = $this->recorded)

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <flux:badge :color="$recorded ? 'green' : 'amber'" size="lg">
            {{ $recorded ? __('Remitted') : __('Not remitted') }}
        </flux:badge>
        <flux:text class="text-sm text-muted-foreground">{{ __('Due :date', ['date' => $period['due']->format('M j, Y')]) }}</flux:text>
        @if ($recorded)
            <flux:text class="text-sm text-muted-foreground">· {{ __('Paid :date', ['date' => $recorded->payment_date->format('M j, Y')]) }}</flux:text>
        @endif
    </div>

    <div class="rounded-lg border border-primary bg-primary/5 p-5">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="text-sm text-muted-foreground">{{ __('Workers’ comp assessment due') }}</div>
                <div class="font-mono text-3xl font-bold text-primary">{{ $this->money($s['remittance_due_cents']) }}</div>
            </div>
            <div class="flex items-center gap-4">
                <div class="text-right text-sm text-muted-foreground">
                    <div>{{ __('Assessable payroll: :amount', ['amount' => $this->money($s['gross_assessable_cents'])]) }}</div>
                    <div>{{ __('Covered employees: :n', ['n' => $s['employee_count']]) }}</div>
                </div>
                @if (! $recorded && $s['remittance_due_cents'] > 0)
                    <flux:button variant="primary" icon="check-circle" wire:click="openRecord">{{ __('Record remittance') }}</flux:button>
                @endif
            </div>
        </div>
    </div>

    <div class="mt-8 overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Run #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Pay date') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Employees') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Assessable') }}</th>
                    <th class="px-4 py-2 text-right">{{ __("Workers' comp") }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->rows as $row)
                    <tr>
                        <td class="px-4 py-2 font-mono">{{ $row['run_no'] }}</td>
                        <td class="px-4 py-2">{{ $row['pay_date'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $row['employees'] }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $this->money($row['assessable_cents']) }}</td>
                        <td class="px-4 py-2 text-right font-mono font-semibold">{{ $this->money($row['wc_cents']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ __('No workers’-comp-assessable pay runs in this period.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 text-sm text-muted-foreground">
        <p>{{ __('Set each province’s WSIB/WCB rate in Payroll settings. The assessment is your assessable payroll × the province rate, capped at each board’s annual maximum per worker.') }}</p>
    </div>

    {{-- Record remittance modal --}}
    <flux:modal name="record-remittance" class="max-w-lg">
        <form wire:submit="record" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Record remittance') }}</flux:heading>
                <flux:subheading>{{ __(':period — :amount workers’ comp', ['period' => $this->selectedPeriod()['label'], 'amount' => $this->money($this->summary['remittance_due_cents'])]) }}</flux:subheading>
            </div>

            <flux:select wire:model="f_bank_account_id" :label="__('Paid from')">
                @foreach ($this->bankAccounts as $account)
                    <flux:select.option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input type="date" wire:model="f_payment_date" :label="__('Payment date')" required />
            <flux:input wire:model="f_reference" :label="__('Reference')" :placeholder="__('Confirmation # (optional)')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Record payment') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
