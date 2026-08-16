<?php

use App\Models\Company;
use App\Services\Payroll\Verification\PayrollVerificationRunner;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Payroll calculation verification')] class extends Component {
    public Company $company;

    public function mount(Company $company): void
    {
        abort_unless($company->usesPayroll(), 404);

        $this->company = $company;
    }

    #[Computed]
    public function report(): array
    {
        return app(PayrollVerificationRunner::class)->run();
    }

    public function money(?int $cents): string
    {
        return $cents === null ? '—' : Money::fromCents($cents)->format();
    }
}; ?>

<section class="w-full space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Payroll calculation verification') }}</flux:heading>
        <flux:subheading>{{ __('Live checks of the CPP/EI/income-tax engine against a curated reference matrix — proof the payroll math adds up.') }}</flux:subheading>
    </div>

    @php($s = $this->report['summary'])

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-lg border border-border p-4">
            <div class="text-sm text-muted-foreground">{{ __('Cases verified') }}</div>
            <div class="text-2xl font-semibold {{ $s['failed'] === 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600' }}">{{ $s['verified'] }}/{{ $s['total'] }}</div>
        </div>
        <div class="rounded-lg border border-border p-4">
            <div class="text-sm text-muted-foreground">{{ __('Reference values matched') }}</div>
            <div class="text-2xl font-semibold">{{ $s['verified_components'] }}</div>
        </div>
        <div class="rounded-lg border border-border p-4">
            <div class="text-sm text-muted-foreground">{{ __('Awaiting a reference') }}</div>
            <div class="text-2xl font-semibold text-amber-600 dark:text-amber-400">{{ $s['awaiting_components'] }}</div>
        </div>
        <div class="rounded-lg border border-border p-4">
            <div class="text-sm text-muted-foreground">{{ __('Status') }}</div>
            <div class="text-2xl font-semibold {{ $s['failed'] === 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600' }}">{{ $s['failed'] === 0 ? __('Passing') : __('Failing') }}</div>
        </div>
    </div>

    @if ($s['awaiting_components'] > 0)
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.heading>{{ __(':n income-tax reference values are not yet confirmed against CRA', ['n' => $s['awaiting_components']]) }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('CPP, CPP2 and EI are verified to the exact cent. The income-tax figures below the matched ones are computed by the engine but have not yet been confirmed against the CRA Payroll Deductions Online Calculator (PDOC). Run each case through PDOC and record the figure to lock it. The tax tables themselves are 2025 best-effort values pending official T4127 verification.') }}
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="space-y-4">
        @foreach ($this->report['checks'] as $check)
            <div class="overflow-hidden rounded-lg border border-border">
                <div class="flex items-center justify-between gap-3 border-b border-border bg-muted/50 px-4 py-2">
                    <div class="font-medium">{{ $check['label'] }}</div>
                    <div class="flex items-center gap-2">
                        <flux:badge size="sm" :color="$check['source'] === 'pdoc' ? 'emerald' : ($check['source'] === 'formula' ? 'blue' : 'amber')">{{ strtoupper($check['source']) }}</flux:badge>
                        @if ($check['passed'])
                            <flux:badge size="sm" color="emerald">{{ __('Matches') }}</flux:badge>
                        @endif
                    </div>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground">
                            <th class="px-4 py-1.5 text-left font-normal">{{ __('Component') }}</th>
                            <th class="px-4 py-1.5 text-right font-normal">{{ __('Reference') }}</th>
                            <th class="px-4 py-1.5 text-right font-normal">{{ __('Engine') }}</th>
                            <th class="px-4 py-1.5 text-center font-normal"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($check['components'] as $component)
                            <tr>
                                <td class="px-4 py-1.5">{{ $component['label'] }}</td>
                                <td class="px-4 py-1.5 text-right font-mono">{{ $this->money($component['expected']) }}</td>
                                <td class="px-4 py-1.5 text-right font-mono">{{ $this->money($component['actual']) }}</td>
                                <td class="px-4 py-1.5 text-center">
                                    @if ($component['status'] === 'match')
                                        <span class="text-emerald-600 dark:text-emerald-400">✓</span>
                                    @elseif ($component['status'] === 'mismatch')
                                        <span class="font-semibold text-red-600">✗</span>
                                    @else
                                        <span class="text-amber-500" title="{{ __('Awaiting CRA PDOC reference') }}">·</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>

    <flux:text class="text-sm text-muted-foreground">
        {{ __('Run `php artisan payroll:verify-calculations` for the same check on the command line (it exits non-zero on any mismatch).') }}
    </flux:text>
</section>
