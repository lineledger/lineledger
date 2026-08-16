<?php

use App\Actions\Sales\SendInvoiceReminder;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\ReminderTier;
use App\Services\Reminders\DueReminderResolver;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Payment Reminders')] class extends Component {
    public Company $company;

    /** Reveal customers who have reminder emails switched off, to chase one by hand. */
    public bool $showOptedOut = false;

    public function mount(Company $company): void
    {
        $this->company = $company;
        ReminderTier::ensureDefaultsFor($company);
    }

    /**
     * Invoices currently due for a reminder, each with the tier that would fire
     * and a little display context (days overdue, balance). By default this is
     * exactly what the morning run will send; flipping $showOptedOut also lists
     * customers it will skip.
     *
     * @return Collection<int, array{invoice: Invoice, tier: \App\Models\ReminderTier, days: int}>
     */
    #[Computed]
    public function dueItems(): Collection
    {
        $today = $this->company->currentDateTime()->startOfDay();

        return app(DueReminderResolver::class)->due($this->company, $today, includeOptedOut: $this->showOptedOut)
            ->map(function (array $row) use ($today): array {
                $due = CarbonImmutable::parse($row['invoice']->due_date->toDateString());

                return [...$row, 'days' => (int) $due->startOfDay()->diffInDays($today, false)];
            });
    }

    public function sendNow(int $invoiceId, SendInvoiceReminder $sender): void
    {
        $item = $this->dueItems->firstWhere('invoice.id', $invoiceId);

        if ($item === null) {
            return;
        }

        // A human asked for this one, so it goes out even if the customer has
        // automated reminders turned off. Their setting is left alone.
        $sender->handle($this->company, $item['invoice'], $item['tier'], bypassOptIn: true)
            ? Flux::toast(variant: 'success', text: __('Reminder sent.'))
            : Flux::toast(variant: 'warning', text: __('Nothing to send — already reminded or no email.'));

        unset($this->dueItems);
    }

    public function disableReminders(int $contactId): void
    {
        Contact::query()->whereKey($contactId)->update(['reminder_emails_enabled' => false]);
        unset($this->dueItems);
        Flux::toast(variant: 'success', text: __('Automated reminders turned off for this customer.'));
    }

    public function snoozeInvoice(int $invoiceId): void
    {
        Invoice::query()->whereKey($invoiceId)->update(['reminders_enabled' => false]);
        unset($this->dueItems);
        Flux::toast(variant: 'success', text: __('Reminders turned off for this invoice.'));
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Payment Reminders') }}</flux:heading>
            <flux:subheading>{{ __('Invoices due for an automated reminder today. Reminders also send on their own each morning.') }}</flux:subheading>
        </div>

        <flux:switch wire:model.live="showOptedOut" :label="__('Show reminders-off customers')" data-test="reminders-show-opted-out" />
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Invoice') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Customer') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Due') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Balance') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Reminder') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->dueItems as $item)
                    @php($invoice = $item['invoice'])
                    <tr data-test="reminder-row">
                        <td class="px-4 py-2 font-mono">
                            <a href="{{ route('invoices.show', ['company' => $company->slug, 'invoice' => $invoice->id]) }}" wire:navigate class="hover:underline">{{ $invoice->invoice_no }}</a>
                        </td>
                        <td class="px-4 py-2">
                            {{ $invoice->contact?->display_name }}
                            @unless ($invoice->contact?->reminder_emails_enabled)
                                <flux:badge size="sm" color="zinc" data-test="reminder-opted-out">{{ __('Reminders off') }}</flux:badge>
                            @endunless
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{ $invoice->due_date?->toDateString() }}
                            <span class="text-muted-foreground">
                                @if ($item['days'] > 0)
                                    ({{ __(':n days overdue', ['n' => $item['days']]) }})
                                @elseif ($item['days'] < 0)
                                    ({{ __('in :n days', ['n' => abs($item['days'])]) }})
                                @else
                                    ({{ __('today') }})
                                @endif
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($invoice->balanceCents() / 100, 2) }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $item['tier']->renderSubject($invoice) }}</td>
                        <td class="px-4 py-2">
                            <div class="flex justify-end gap-2">
                                <flux:button size="xs" variant="primary" icon="paper-airplane" wire:click="sendNow({{ $invoice->id }})" data-test="reminder-send">{{ __('Send now') }}</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="snoozeInvoice({{ $invoice->id }})" data-test="reminder-snooze">{{ __('Skip') }}</flux:button>
                                @if ($invoice->contact?->reminder_emails_enabled)
                                    <flux:button size="xs" variant="ghost" wire:click="disableReminders({{ $invoice->contact_id }})" data-test="reminder-mute">{{ __('Turn off reminders') }}</flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ __('No invoices need a reminder right now.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
