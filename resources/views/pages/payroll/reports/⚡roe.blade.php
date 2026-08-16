<?php

use App\Enums\JurisdictionCapability;
use App\Enums\RoeReason;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\RoeCalculator;
use App\Services\Reporting\RoeXmlGenerator;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Record of Employment')] class extends Component {
    public Company $company;

    #[Url(as: 'contact')]
    public ?int $contactId = null;

    #[Url(as: 'reason')]
    public string $reason = 'A';

    #[Url(as: 'last')]
    public string $lastDay = '';

    public function mount(Company $company): void
    {
        abort_unless($company->supports(JurisdictionCapability::RecordOfEmployment), 404);

        $this->company = $company;

        // The termination wizard deep-links ?contact&reason&last; fall back to
        // sensible defaults when opened directly.
        if ($this->lastDay === '') {
            $this->lastDay = $company->currentDateTime()->toDateString();
        }

        if ($this->contactId === null) {
            $this->contactId = $this->employees()->first()?->id;
        }
    }

    #[Computed]
    public function employees()
    {
        return Contact::query()
            ->where('is_employee', true)
            ->whereHas('payrollProfile')
            ->orderBy('display_name')
            ->get();
    }

    #[Computed]
    public function roe(): ?array
    {
        if ($this->contactId === null) {
            return null;
        }

        $employee = Contact::query()->whereKey($this->contactId)->whereHas('payrollProfile')->first();

        if ($employee === null) {
            return null;
        }

        return app(RoeCalculator::class)->build($this->company, $employee, RoeReason::from($this->reason), $this->lastDay);
    }

    public function money(int $cents): string
    {
        return Money::fromCents($cents)->format();
    }

    public function downloadPdf()
    {
        $roe = $this->roe;

        abort_if($roe === null, 404);

        return app(PdfExporter::class)->download('pdf.reports.roe', [
            'company' => $this->company,
            'roe' => $roe,
        ], 'roe-'.$this->contactId.'.pdf');
    }

    public function downloadXml()
    {
        abort_if($this->contactId === null, 404);

        $employee = Contact::query()->whereKey($this->contactId)->whereHas('payrollProfile')->firstOrFail();
        $roe = app(RoeCalculator::class)->build($this->company, $employee, RoeReason::from($this->reason), $this->lastDay);
        $xml = app(RoeXmlGenerator::class)->generate($this->company, $employee, $roe);

        return response()->streamDownload(
            fn () => print($xml),
            'roe-'.$this->contactId.'.xml',
            ['Content-Type' => 'application/xml'],
        );
    }
}; ?>

<section class="w-full">
    <div class="mb-6">
        <flux:heading size="xl" level="1">{{ __('Record of Employment (ROE)') }}</flux:heading>
        <flux:subheading>{{ __('Insurable hours and earnings for the ROE Web form when an employee leaves.') }}</flux:subheading>
    </div>

    <div class="mb-6 grid grid-cols-1 gap-4 rounded-lg border border-border p-5 sm:grid-cols-3">
        <flux:select wire:model.live="contactId" :label="__('Employee')">
            @foreach ($this->employees as $employee)
                <flux:select.option value="{{ $employee->id }}">{{ $employee->display_name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="reason" :label="__('Reason for issuing (Block 16)')">
            @foreach (RoeReason::cases() as $r)
                <flux:select.option value="{{ $r->value }}">{{ $r->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input type="date" wire:model.live="lastDay" :label="__('Last day paid (Block 11)')" />
    </div>

    @if ($this->roe)
        @php($roe = $this->roe)
        <div class="mb-4 flex justify-end gap-2">
            <flux:button variant="ghost" icon="code-bracket" wire:click="downloadXml">{{ __('ROE Web XML') }}</flux:button>
            <flux:button variant="primary" icon="arrow-down-tray" wire:click="downloadPdf">{{ __('Download worksheet') }}</flux:button>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-lg border border-border p-4">
                <div class="text-sm text-muted-foreground">{{ __('First day worked (Block 10)') }}</div>
                <div class="font-medium">{{ $roe['first_day'] ?? '—' }}</div>
            </div>
            <div class="rounded-lg border border-border p-4">
                <div class="text-sm text-muted-foreground">{{ __('Total insurable hours (15A)') }}</div>
                <div class="font-mono font-medium">{{ $roe['total_insurable_hours'] }}</div>
            </div>
            <div class="rounded-lg border border-border p-4">
                <div class="text-sm text-muted-foreground">{{ __('Total insurable earnings (15B)') }}</div>
                <div class="font-mono font-medium">{{ $this->money($roe['total_insurable_earnings_cents']) }}</div>
            </div>
        </div>

        <div class="mt-6 overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Pay period ending') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Insurable hours') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Insurable earnings (15C)') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($roe['periods'] as $p)
                        <tr>
                            <td class="px-4 py-2">{{ $p['period_end'] }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ $p['insurable_hours'] }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ $this->money($p['insurable_earnings_cents']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-8 text-center text-muted-foreground">{{ __('No posted pay periods for this employee.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <flux:callout icon="information-circle">{{ __('Enrol an employee in payroll to produce an ROE.') }}</flux:callout>
    @endif

    <div class="mt-4 text-sm text-muted-foreground">
        <p>{{ __('Transcribe these figures into Service Canada’s ROE Web form, or import the ROE Web XML. Electronic submission is not available here.') }}</p>
        <p class="mt-1">{{ __('Paper ROEs are serial-numbered government stock and must never be reproduced — Service Canada assigns the serial number when the ROE is filed through ROE Web.') }}</p>
    </div>
</section>
