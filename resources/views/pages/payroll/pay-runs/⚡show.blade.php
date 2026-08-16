<?php

use App\Enums\AccountSubtype;
use App\Enums\PayrollChequeStatus;
use App\Enums\PayRunStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\PayRun;
use App\Models\PayrollCheque;
use App\Models\PayRunLine;
use App\Rules\MoneyString;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\PayrollChequePoster;
use App\Services\Posting\PayRunPoster;
use App\Support\Money;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pay run')] class extends Component {
    public Company $company;

    public PayRun $payRun;

    // Adjustment modal state.
    public ?int $adjustLineId = null;

    public string $adj_cpp = '';

    public string $adj_cpp2 = '';

    public string $adj_ei = '';

    public string $adj_federal = '';

    public string $adj_provincial = '';

    public string $adj_additional = '';

    public string $startingChequeNumber = '';

    public ?int $bankAccountId = null;

    public function mount(Company $company, PayRun $payRun): void
    {
        $this->company = $company;

        abort_unless($company->usesPayroll(), 404);

        $payRun->load(['lines.contact', 'schedule', 'bankAccount', 'cheques.payee']);
        $this->payRun = $payRun;
        $this->bankAccountId = $payRun->bank_account_id;
    }

    #[Computed]
    public function bankAccounts()
    {
        return Account::query()
            ->where('subtype', AccountSubtype::Bank->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();
    }

    #[Computed]
    public function cheques()
    {
        return $this->payRun->cheques()->with('payee')->orderBy('cheque_no')->get();
    }

    public function post(PayRunPoster $poster): void
    {
        try {
            $poster->post($this->payRun);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->payRun->refresh()->load(['lines.contact', 'cheques.payee']);
        Flux::toast(variant: 'success', text: __('Pay run posted.'));
    }

    public function writeCheques(\App\Actions\Payroll\IssuePayrollCheques $issuer): void
    {
        $this->validate([
            'startingChequeNumber' => ['required', 'integer', 'min:1'],
            'bankAccountId' => ['required', 'integer', 'exists:accounts,id'],
        ]);

        try {
            $issuer->handle($this->payRun, (int) $this->startingChequeNumber, $this->bankAccountId);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->payRun->refresh()->load(['lines.contact', 'cheques.payee']);
        unset($this->cheques);
        Flux::modal('write-cheques')->close();
        Flux::toast(variant: 'success', text: __('Cheques written.'));
    }

    public function voidRun(PayRunPoster $poster): void
    {
        try {
            $poster->void($this->payRun);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->payRun->refresh()->load(['lines.contact', 'cheques.payee']);
        Flux::toast(variant: 'success', text: __('Pay run voided.'));
    }

    public function recalculate(CalculatePayRun $calculate): void
    {
        abort_unless($this->payRun->status->isEditable(), 403);

        $calculate->calculate($this->payRun);
        $this->payRun->refresh()->load('lines.contact');

        Flux::toast(variant: 'success', text: __('Pay run recalculated.'));
    }

    public function openAdjust(int $lineId): void
    {
        $line = $this->payRun->lines->firstWhere('id', $lineId);
        abort_if($line === null, 404);

        $this->adjustLineId = $lineId;
        $this->adj_cpp = Money::fromCents($line->cppEmployeeCents())->toDecimalString();
        $this->adj_cpp2 = Money::fromCents($line->cpp2EmployeeCents())->toDecimalString();
        $this->adj_ei = Money::fromCents($line->eiEmployeeCents())->toDecimalString();
        $this->adj_federal = Money::fromCents($line->federalTaxCents())->toDecimalString();
        $this->adj_provincial = Money::fromCents($line->provincialTaxCents())->toDecimalString();
        $this->adj_additional = Money::fromCents($line->additionalTaxCents())->toDecimalString();

        Flux::modal('adjust-line')->show();
    }

    public function saveAdjust(): void
    {
        abort_unless($this->payRun->status->isEditable(), 403);

        $this->validate([
            'adj_cpp' => ['required', 'string', new MoneyString],
            'adj_cpp2' => ['required', 'string', new MoneyString],
            'adj_ei' => ['required', 'string', new MoneyString],
            'adj_federal' => ['required', 'string', new MoneyString],
            'adj_provincial' => ['required', 'string', new MoneyString],
            'adj_additional' => ['required', 'string', new MoneyString],
        ]);

        $line = PayRunLine::findOrFail($this->adjustLineId);
        abort_unless($line->pay_run_id === $this->payRun->id, 403);

        // Store an override only when it differs from the computed value; equal
        // values fall back to null so the line tracks future recalculations.
        $this->setOverride($line, 'cpp_employee', $this->adj_cpp);
        $this->setOverride($line, 'cpp2_employee', $this->adj_cpp2);
        $this->setOverride($line, 'ei_employee', $this->adj_ei);
        $this->setOverride($line, 'federal_tax', $this->adj_federal);
        $this->setOverride($line, 'provincial_tax', $this->adj_provincial);
        $this->setOverride($line, 'additional_tax', $this->adj_additional);
        $line->save();
        $line->refresh();
        $line->recalculateTotals();

        $this->payRun->refresh()->load('lines.contact');
        $this->payRun->recalculateTotals();

        Flux::modal('adjust-line')->close();
        Flux::toast(variant: 'success', text: __('Adjustment saved.'));
    }

    private function setOverride(PayRunLine $line, string $component, string $value): void
    {
        $cents = Money::fromString($value)->cents;
        $line->{$component.'_override_cents'} = $cents === (int) $line->{$component.'_computed_cents'} ? null : $cents;
    }

    public function resetAdjust(): void
    {
        abort_unless($this->payRun->status->isEditable(), 403);

        $line = PayRunLine::findOrFail($this->adjustLineId);
        abort_unless($line->pay_run_id === $this->payRun->id, 403);

        foreach (PayRunLine::COMPONENTS as $component) {
            $line->{$component.'_override_cents'} = null;
        }
        $line->save();
        $line->refresh();
        $line->recalculateTotals();

        $this->payRun->refresh()->load('lines.contact');
        $this->payRun->recalculateTotals();

        Flux::modal('adjust-line')->close();
        Flux::toast(variant: 'success', text: __('Reset to calculated amounts.'));
    }

    public function voidCheque(int $chequeId, PayrollChequePoster $poster): void
    {
        $cheque = PayrollCheque::findOrFail($chequeId);
        abort_unless((int) $cheque->pay_run_id === $this->payRun->id, 403);

        try {
            $poster->void($cheque);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->payRun->refresh()->load(['lines.contact', 'cheques.payee']);
        unset($this->cheques);
        Flux::toast(variant: 'success', text: __('Cheque voided.'));
    }

    public function money(int $cents): string
    {
        return Money::fromCents($cents)->format();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('pay-runs.index')" wire:navigate>
                {{ __('Back to pay runs') }}
            </flux:button>
            <div class="mt-3 flex items-center gap-3">
                <flux:heading size="xl" level="1">{{ $payRun->run_no }}</flux:heading>
                <flux:badge :color="match($payRun->status) { \App\Enums\PayRunStatus::Draft => 'zinc', \App\Enums\PayRunStatus::Calculated => 'amber', \App\Enums\PayRunStatus::Posted => 'blue', \App\Enums\PayRunStatus::Paid => 'emerald', \App\Enums\PayRunStatus::Void => 'red' }">{{ $payRun->status->label() }}</flux:badge>
            </div>
            <flux:subheading>
                {{ __('Pay date :date · period :start – :end', ['date' => $payRun->pay_date->toDateString(), 'start' => $payRun->period_start_date->toDateString(), 'end' => $payRun->period_end_date->toDateString()]) }}
            </flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($payRun->status->isEditable())
                <flux:button variant="ghost" icon="pencil" :href="route('pay-runs.edit', $payRun)" wire:navigate>{{ __('Edit') }}</flux:button>
                <flux:button variant="ghost" icon="calculator" wire:click="recalculate">{{ __('Recalculate') }}</flux:button>
            @endif
            @if ($payRun->status === \App\Enums\PayRunStatus::Calculated)
                <flux:button variant="primary" icon="check" wire:click="post" wire:confirm="{{ __('Post this pay run to the general ledger?') }}">{{ __('Post pay run') }}</flux:button>
            @endif
            @if ($payRun->status === \App\Enums\PayRunStatus::Posted)
                <flux:button variant="primary" icon="banknotes" wire:click="$set('startingChequeNumber', ''); $flux.modal('write-cheques').show()">{{ __('Write cheques') }}</flux:button>
            @endif
            @if (in_array($payRun->status, [\App\Enums\PayRunStatus::Posted, \App\Enums\PayRunStatus::Paid], true))
                <flux:button variant="ghost" icon="x-mark" wire:click="voidRun" wire:confirm="{{ __('Void this pay run? Its journal entry will be reversed.') }}">{{ __('Void') }}</flux:button>
            @endif
        </div>
    </div>

    {{-- Totals --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-lg border border-border p-4">
            <div class="text-sm text-muted-foreground">{{ __('Gross') }}</div>
            <div class="font-mono text-lg font-semibold">{{ $this->money($payRun->gross_cents) }}</div>
        </div>
        <div class="rounded-lg border border-border p-4">
            <div class="text-sm text-muted-foreground">{{ __('Deductions') }}</div>
            <div class="font-mono text-lg font-semibold">{{ $this->money($payRun->total_deductions_cents) }}</div>
        </div>
        <div class="rounded-lg border border-border p-4">
            <div class="text-sm text-muted-foreground">{{ __('Net pay') }}</div>
            <div class="font-mono text-lg font-semibold">{{ $this->money($payRun->net_cents) }}</div>
        </div>
        <div class="rounded-lg border border-border p-4">
            <div class="text-sm text-muted-foreground">{{ __('Employer cost') }}</div>
            <div class="font-mono text-lg font-semibold">{{ $this->money($payRun->total_employer_cost_cents) }}</div>
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-3 py-2 text-left">{{ __('Employee') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Gross') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('CPP') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('EI') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Fed tax') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Prov tax') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Net') }}</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($payRun->lines as $line)
                    <tr>
                        <td class="px-3 py-2 font-medium">{{ $line->contact->display_name }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($line->gross_cents) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($line->cppEmployeeCents() + $line->cpp2EmployeeCents()) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($line->eiEmployeeCents()) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($line->federalTaxCents() + $line->additionalTaxCents()) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ $this->money($line->provincialTaxCents()) }}</td>
                        <td class="px-3 py-2 text-right font-mono font-semibold">{{ $this->money($line->net_cents) }}</td>
                        <td class="px-3 py-2 text-right">
                            <div class="flex items-center justify-end gap-1">
                                @if ($payRun->status->isEditable())
                                    <flux:button size="xs" variant="ghost" icon="adjustments-horizontal" wire:click="openAdjust({{ $line->id }})">{{ __('Adjust') }}</flux:button>
                                @endif
                                @if ($payRun->status !== PayRunStatus::Draft)
                                    <flux:button size="xs" variant="ghost" icon="document-text" :href="route('payroll.pay-stub.preview', $line)" target="_blank">{{ __('Stub') }}</flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-3 py-8 text-center text-muted-foreground">{{ __('This pay run has no employees.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($payRun->status === PayRunStatus::Calculated)
        <flux:callout class="mt-6" icon="information-circle">
            {{ __('Review the amounts above, then post the pay run to record it in the general ledger.') }}
        </flux:callout>
    @endif

    {{-- Cheques --}}
    @if ($this->cheques->isNotEmpty())
        <div class="mt-8">
            <flux:heading size="lg" class="mb-3">{{ __('Cheques') }}</flux:heading>
            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted">
                        <tr>
                            <th class="px-4 py-2 text-left">{{ __('Cheque #') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('Payee') }}</th>
                            <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($this->cheques as $cheque)
                            <tr class="@if ($cheque->status->value === 'void') opacity-50 @endif">
                                <td class="px-4 py-2 font-mono">{{ $cheque->cheque_no }}</td>
                                <td class="px-4 py-2">{{ $cheque->payee_name }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ $this->money($cheque->amount_cents) }}</td>
                                <td class="px-4 py-2"><flux:badge size="sm" :color="$cheque->status->value === 'posted' ? 'emerald' : ($cheque->status->value === 'void' ? 'red' : 'zinc')">{{ $cheque->status->label() }}</flux:badge></td>
                                <td class="px-4 py-2 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <flux:button size="xs" variant="ghost" icon="printer" :href="route('payroll-cheques.print', $cheque)" target="_blank">{{ __('Print') }}</flux:button>
                                        @if ($cheque->status === PayrollChequeStatus::Posted)
                                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="voidCheque({{ $cheque->id }})" wire:confirm="{{ __('Void cheque #:no for :payee? This reverses the bank entry.', ['no' => $cheque->cheque_no, 'payee' => $cheque->payee_name]) }}">{{ __('Void') }}</flux:button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Write cheques modal --}}
    <flux:modal name="write-cheques" class="max-w-sm">
        <form wire:submit="writeCheques" class="space-y-6">
            <flux:heading size="lg">{{ __('Write cheques') }}</flux:heading>
            <flux:text class="text-sm text-muted-foreground">{{ __('One cheque is written per employee with positive net pay, numbered sequentially.') }}</flux:text>
            <flux:select wire:model="bankAccountId" :label="__('Bank account')">
                @foreach ($this->bankAccounts as $account)
                    <flux:select.option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input type="number" wire:model="startingChequeNumber" :label="__('Starting cheque number')" min="1" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Write cheques') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Adjust modal --}}
    <flux:modal name="adjust-line" class="max-w-md">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Adjust deductions') }}</flux:heading>
            <flux:text class="text-sm text-muted-foreground">{{ __('Override any calculated amount. Leave equal to the calculated value to keep it automatic.') }}</flux:text>

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="adj_cpp" :label="__('CPP')" inputmode="decimal" />
                <flux:input wire:model="adj_cpp2" :label="__('CPP2')" inputmode="decimal" />
                <flux:input wire:model="adj_ei" :label="__('EI')" inputmode="decimal" />
                <flux:input wire:model="adj_federal" :label="__('Federal tax')" inputmode="decimal" />
                <flux:input wire:model="adj_provincial" :label="__('Provincial tax')" inputmode="decimal" />
                <flux:input wire:model="adj_additional" :label="__('Additional tax')" inputmode="decimal" />
            </div>

            <div class="flex justify-between gap-2">
                <flux:button variant="ghost" wire:click="resetAdjust">{{ __('Reset to calculated') }}</flux:button>
                <div class="flex gap-2">
                    <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="saveAdjust">{{ __('Save') }}</flux:button>
                </div>
            </div>
        </div>
    </flux:modal>
</section>
