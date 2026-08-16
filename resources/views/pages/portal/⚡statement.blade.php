<?php

use App\Enums\AccountSubtype;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Reporting\ContactStatementBuilder;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.portal')] #[Title('Statement')] class extends Component
{
    public Company $company;

    public Contact $customer;

    #[Url(as: 'start')]
    public string $startDate = '';

    #[Url(as: 'end')]
    public string $endDate = '';

    public function mount(Company $company): void
    {
        $this->company = $company;
        $this->customer = auth('customer')->user();

        if ($this->startDate === '') {
            $this->startDate = $this->company->currentDateTime()->startOfYear()->toDateString();
        }

        if ($this->endDate === '') {
            $this->endDate = $this->company->currentDateTime()->toDateString();
        }
    }

    #[Computed]
    public function report(): array
    {
        return app(ContactStatementBuilder::class)->build(
            $this->company,
            $this->customer,
            AccountSubtype::AccountsReceivable,
            CarbonImmutable::parse($this->startDate),
            CarbonImmutable::parse($this->endDate),
        );
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Account statement') }}</flux:heading>
            <flux:subheading>{{ $customer->display_name }}</flux:subheading>
        </div>

        <div class="flex items-end gap-2">
            <flux:button
                size="sm"
                variant="ghost"
                icon="arrow-left"
                :href="route('portal.dashboard', ['company' => $company->slug])"
                wire:navigate
            >
                {{ __('Back') }}
            </flux:button>
            <flux:button
                size="sm"
                variant="primary"
                icon="arrow-down-tray"
                :href="route('portal.statement.pdf', ['company' => $company->slug, 'start' => $startDate, 'end' => $endDate])"
                data-test="portal-statement-pdf"
            >
                {{ __('Download PDF') }}
            </flux:button>
        </div>
    </div>

    <div class="flex flex-wrap items-end gap-2">
        <flux:input type="date" wire:model.live="startDate" :label="__('Start')" class="max-w-[180px]" />
        <flux:input type="date" wire:model.live="endDate" :label="__('End')" class="max-w-[180px]" />
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Type') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Doc #') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Debit') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Credit') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Balance') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                <tr class="bg-muted/50">
                    <td class="px-4 py-2 text-muted-foreground italic" colspan="5">{{ __('Opening balance') }}</td>
                    <td class="px-4 py-2 text-right font-mono" data-test="portal-statement-opening">{{ number_format($this->report['opening'] / 100, 2) }}</td>
                </tr>
                @forelse ($this->report['lines'] as $line)
                    <tr data-test="portal-statement-row">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $line['date'] }}</td>
                        <td class="px-4 py-2">{{ $line['type'] }}</td>
                        <td class="px-4 py-2 font-mono">{{ $line['doc_no'] }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $line['debit'] ? number_format($line['debit'] / 100, 2) : '' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $line['credit'] ? number_format($line['credit'] / 100, 2) : '' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($line['running'] / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ __('No transactions in this range.') }}</td></tr>
                @endforelse
                <tr class="bg-muted">
                    <td class="px-4 py-2 text-right font-semibold" colspan="5">{{ __('Closing balance') }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold" data-test="portal-statement-closing">{{ number_format($this->report['closing'] / 100, 2) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
