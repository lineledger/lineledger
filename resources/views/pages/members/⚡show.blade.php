<?php

use App\Actions\Membership\BillMemberDues;
use App\Enums\RecurrenceFrequency;
use App\Models\Company;
use App\Models\Member;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Member')] class extends Component {
    public Company $company;

    public Member $member;

    public function mount(Company $company, Member $member): void
    {
        $this->company = $company;

        abort_unless($company->tracksMembership(), 403);
        abort_unless($member->company_id === $company->id, 404);

        $this->member = $member->load('contact', 'level', 'recurringDocument');
    }

    #[Computed]
    public function invoices()
    {
        return $this->member->invoices()->orderByDesc('invoice_date')->orderByDesc('id')->get();
    }

    public function billDues()
    {
        try {
            $invoice = app(BillMemberDues::class)->handle($this->member);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Draft dues invoice created.'));

        // Drop the user straight into the editable draft so they can adjust lines
        // before posting, rather than the read-only show page.
        $this->redirectRoute('invoices.edit', ['company' => $this->company, 'invoice' => $invoice], navigate: true);
    }

    public function renew(): void
    {
        $frequency = $this->member->level?->billing_frequency ?? RecurrenceFrequency::Annual;

        $base = $this->member->expires_on
            ? CarbonImmutable::parse($this->member->expires_on)
            : $this->company->currentDateTime()->startOfDay();

        $next = $frequency->isWeekly()
            ? $base->addWeek()
            : $base->addMonths($frequency->monthsToAdd() ?? 12);

        $this->member->forceFill([
            'started_on' => $base->toDateString(),
            'expires_on' => $next->toDateString(),
            'cancelled_at' => null,
        ])->save();

        $this->member->refresh()->load('contact', 'level');

        Flux::toast(variant: 'success', text: __('Membership renewed through :date.', ['date' => $next->format('M j, Y')]));
    }
}; ?>

<section class="mx-auto w-full max-w-3xl">
    @php($status = $member->effectiveStatus())
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <flux:heading size="xl" level="1" data-test="page-title">{{ $member->contact?->display_name }}</flux:heading>
                <flux:badge :color="$status->color()" data-test="member-status">{{ $status->label() }}</flux:badge>
            </div>
            <flux:subheading>{{ $member->member_no }} · {{ $member->level?->name ?? __('No level') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button variant="ghost" icon="pencil" :href="route('members.edit', ['company' => $company, 'member' => $member])" wire:navigate data-test="member-edit-button">{{ __('Edit') }}</flux:button>
            <flux:button variant="ghost" icon="arrow-path" wire:click="renew" data-test="member-renew-button">{{ __('Renew') }}</flux:button>
            <flux:button variant="primary" icon="document-plus" wire:click="billDues" data-test="member-bill-dues-button">{{ __('Bill dues now') }}</flux:button>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4 rounded-lg border border-border p-6 text-sm md:grid-cols-4">
        <div>
            <div class="text-muted-foreground">{{ __('Joined') }}</div>
            <div>{{ $member->joined_on?->format('M j, Y') ?? '—' }}</div>
        </div>
        <div>
            <div class="text-muted-foreground">{{ __('Term start') }}</div>
            <div>{{ $member->started_on?->format('M j, Y') ?? '—' }}</div>
        </div>
        <div>
            <div class="text-muted-foreground">{{ __('Expires') }}</div>
            <div>{{ $member->expires_on?->format('M j, Y') ?? '—' }}</div>
        </div>
        <div>
            <div class="text-muted-foreground">{{ __('Dues') }}</div>
            <div class="font-mono">{{ Money::fromCents($member->effectiveDuesCents(), $company->currency_code) }}</div>
        </div>
    </div>

    @if ($member->auto_renew)
        <flux:text class="mt-3 text-muted-foreground" data-test="member-auto-renew-note">
            @if ($member->recurringDocument?->is_active && $member->recurringDocument->next_run_date)
                {{ __('Auto-renew is on. The next dues invoice generates automatically on :date.', ['date' => $member->recurringDocument->next_run_date->format('M j, Y')]) }}
            @else
                {{ __('Auto-renew is on; dues invoices generate automatically each billing period once a level with a revenue account and dues is set.') }}
            @endif
        </flux:text>
    @endif

    <flux:heading size="lg" class="mt-8 mb-3">{{ __('Dues invoices') }}</flux:heading>
    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Invoice #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Balance') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->invoices as $invoice)
                    <tr data-test="member-invoice-row">
                        <td class="px-4 py-2">
                            <a href="{{ route('invoices.show', ['company' => $company, 'invoice' => $invoice]) }}" wire:navigate class="text-primary hover:underline">{{ $invoice->invoice_no }}</a>
                        </td>
                        <td class="px-4 py-2">{{ $invoice->invoice_date?->format('M j, Y') }}</td>
                        <td class="px-4 py-2">{{ ucfirst($invoice->status->value) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ Money::fromCents($invoice->total_cents, $company->currency_code) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ Money::fromCents($invoice->balanceCents(), $company->currency_code) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-muted-foreground">{{ __('No dues invoices yet.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
