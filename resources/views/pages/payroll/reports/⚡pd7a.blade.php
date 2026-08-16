<?php

use App\Actions\Payroll\RecordPayrollRemittance;
use App\Enums\AccountSubtype;
use App\Enums\RemittanceAgency;
use App\Enums\RemittanceFrequency;
use App\Enums\RemittanceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\PayrollRemittance;
use App\Services\Payroll\RemittancePeriodResolver;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PayrollRemittanceCalculator;
use App\Services\Reporting\PdfExporter;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('PD7A — Payroll remittance')] class extends Component {
    public Company $company;

    #[Url(as: 'period')]
    public string $periodKey = '';

    // Record-remittance modal.
    public ?int $f_bank_account_id = null;

    public string $f_payment_date = '';

    public string $f_reference = '';

    public function mount(Company $company): void
    {
        abort_unless($company->supports(\App\Enums\JurisdictionCapability::Pd7aRemittance), 404);

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

    /** The most recent period that has already closed (end before today). */
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

        return app(PayrollRemittanceCalculator::class)->summary($this->company, $p['start'], $p['end']);
    }

    #[Computed]
    public function rows(): array
    {
        $p = $this->selectedPeriod();

        return app(PayrollRemittanceCalculator::class)->rows($this->company, $p['start'], $p['end']);
    }

    /** The recorded (paid) remittance for this agency + period, if any. */
    #[Computed]
    public function recorded(): ?PayrollRemittance
    {
        return PayrollRemittance::query()
            ->where('agency', RemittanceAgency::Cra->value)
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
                'agency' => RemittanceAgency::Cra->value,
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

    private function fileStem(): string
    {
        return 'pd7a-'.$this->selectedPeriod()['key'];
    }

    public function exportCsv()
    {
        $rows = collect($this->rows)->map(fn ($r) => [
            $r['run_no'], $r['pay_date'], $r['employees'],
            CsvExporter::cents($r['gross_cents']), CsvExporter::cents($r['cpp_cents']),
            CsvExporter::cents($r['ei_cents']), CsvExporter::cents($r['tax_cents']),
            CsvExporter::cents($r['remittance_cents']),
        ]);

        $s = $this->summary;
        $rows->push(['TOTAL', '', $s['employee_count'], CsvExporter::cents($s['gross_payroll_cents']),
            CsvExporter::cents($s['total_cpp_cents']), CsvExporter::cents($s['total_ei_cents']),
            CsvExporter::cents($s['tax_cents']), CsvExporter::cents($s['remittance_due_cents'])]);

        return app(CsvExporter::class)->stream(
            $this->fileStem().'.csv',
            ['Run #', 'Pay date', 'Employees', 'Gross', 'CPP', 'EI', 'Income tax', 'Remittance'],
            $rows,
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.payroll-remittance-pd7a', [
            'company' => $this->company,
            'summary' => $this->summary,
            'rows' => $this->rows,
            'periodLabel' => $this->selectedPeriod()['label'],
        ], $this->fileStem().'.pdf');
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('PD7A — Payroll remittance') }}</flux:heading>
            <flux:subheading>{{ __('Source deductions to remit to the CRA for a remitting period.') }}</flux:subheading>
            @if ($company->payroll_business_number || $company->payroll_rp_account)
                <flux:text class="mt-1 text-sm text-muted-foreground">
                    {{ __('Payroll account') }}: {{ $company->payroll_business_number }}{{ $company->payroll_rp_account ? ' '.$company->payroll_rp_account : '' }}
                </flux:text>
            @endif
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <flux:select wire:model.live="periodKey" :label="__('Remitting period')" class="min-w-[200px]">
                @foreach ($this->periods as $period)
                    <flux:select.option value="{{ $period['key'] }}">{{ $period['label'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:dropdown align="end">
                <flux:button variant="ghost" icon="arrow-down-tray" icon:trailing="chevron-down">{{ __('Download') }}</flux:button>
                <flux:menu>
                    <flux:menu.item icon="document-text" wire:click="exportCsv">{{ __('CSV') }}</flux:menu.item>
                    <flux:menu.item icon="document" wire:click="exportPdf">{{ __('PDF') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    @php($s = $this->summary)
    @php($period = $this->selectedPeriod())
    @php($recorded = $this->recorded)

    {{-- Due date + paid/due status --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <flux:badge :color="$recorded ? 'green' : 'amber'" size="lg">
            {{ $recorded ? __('Remitted') : __('Not remitted') }}
        </flux:badge>
        <flux:text class="text-sm text-muted-foreground">
            {{ __('Due :date', ['date' => $period['due']->format('M j, Y')]) }}
        </flux:text>
        @if ($recorded)
            <flux:text class="text-sm text-muted-foreground">
                · {{ __('Paid :date', ['date' => $recorded->payment_date->format('M j, Y')]) }}
                @if ($recorded->reference) · {{ $recorded->reference }} @endif
            </flux:text>
        @endif
    </div>

    {{-- PD7A headline figures --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-lg border border-border p-5">
            <div class="text-sm text-muted-foreground">{{ __('Total CPP (employee + employer)') }}</div>
            <div class="font-mono text-2xl font-semibold">{{ $this->money($s['total_cpp_cents']) }}</div>
        </div>
        <div class="rounded-lg border border-border p-5">
            <div class="text-sm text-muted-foreground">{{ __('Total EI (employee + employer)') }}</div>
            <div class="font-mono text-2xl font-semibold">{{ $this->money($s['total_ei_cents']) }}</div>
        </div>
        <div class="rounded-lg border border-border p-5">
            <div class="text-sm text-muted-foreground">{{ __('Income tax withheld') }}</div>
            <div class="font-mono text-2xl font-semibold">{{ $this->money($s['tax_cents']) }}</div>
        </div>
    </div>

    <div class="mt-4 rounded-lg border border-primary bg-primary/5 p-5">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="text-sm text-muted-foreground">{{ __('Amount of current payment (total remittance due)') }}</div>
                <div class="font-mono text-3xl font-bold text-primary">{{ $this->money($s['remittance_due_cents']) }}</div>
            </div>
            <div class="flex items-center gap-4">
                <div class="text-right text-sm text-muted-foreground">
                    <div>{{ __('Gross payroll: :amount', ['amount' => $this->money($s['gross_payroll_cents'])]) }}</div>
                    <div>{{ __('Employees in period: :n', ['n' => $s['employee_count']]) }}</div>
                    <div>{{ __('Employees in last pay period: :n', ['n' => $s['last_period_employee_count']]) }}</div>
                </div>
                @if (! $recorded && $s['remittance_due_cents'] > 0)
                    <flux:button variant="primary" icon="check-circle" wire:click="openRecord">{{ __('Record remittance') }}</flux:button>
                @endif
            </div>
        </div>
    </div>

    {{-- Per-run breakdown --}}
    <div class="mt-8 overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Run #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Pay date') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Employees') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Gross') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('CPP') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('EI') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Income tax') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Remittance') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->rows as $row)
                    <tr>
                        <td class="px-4 py-2 font-mono">{{ $row['run_no'] }}</td>
                        <td class="px-4 py-2">{{ $row['pay_date'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $row['employees'] }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $this->money($row['gross_cents']) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $this->money($row['cpp_cents']) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $this->money($row['ei_cents']) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $this->money($row['tax_cents']) }}</td>
                        <td class="px-4 py-2 text-right font-mono font-semibold">{{ $this->money($row['remittance_cents']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-muted-foreground">{{ __('No posted pay runs in this period.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 text-sm text-muted-foreground">
        <p>{{ __('Enter these totals on your PD7A statement in CRA My Business Account. CPP and EI include both employee and employer portions; income tax is the federal + provincial amount withheld.') }}</p>
    </div>

    {{-- Record remittance modal --}}
    <flux:modal name="record-remittance" class="max-w-lg">
        <form wire:submit="record" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Record remittance') }}</flux:heading>
                <flux:subheading>{{ __(':period — :amount to the CRA', ['period' => $this->selectedPeriod()['label'], 'amount' => $this->money($this->summary['remittance_due_cents'])]) }}</flux:subheading>
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
