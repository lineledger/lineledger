<?php

use App\Actions\Sales\SendCustomerStatement;
use App\Enums\CustomerStatementType;
use App\Models\Company;
use App\Models\Contact;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The customer-facing statement modal (View PDF / Download / Email), shared by
 * the Customers list and the AR statement report. Any parent opens it with
 * `$dispatch('open-customer-statement', { id, start?, end? })`.
 */
new class extends Component {
    public Company $company;

    public ?int $statementCustomerId = null;

    public string $statementType = 'open-invoices';

    public string $statementAsOf = '';

    public string $statementStart = '';

    public string $statementEnd = '';

    public string $statementToEmail = '';

    public string $statementCc = '';

    public bool $statementCcSelf = false;

    public string $statementMessage = '';

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    /**
     * `start`/`end` (Y-m-d) pre-fill the activity range and the as-of date so a
     * report can hand over the period it is showing; anything else falls back
     * to year-to-date.
     */
    #[On('open-customer-statement')]
    public function open(int $id, ?string $start = null, ?string $end = null): void
    {
        $customer = Contact::where('is_customer', true)->findOrFail($id);
        abort_unless($customer->company_id === $this->company->id, 403);

        $this->reset(['statementType', 'statementToEmail', 'statementCc', 'statementCcSelf', 'statementMessage']);
        $this->resetErrorBag(['statementToEmail', 'statementCc', 'statementMessage']);
        $this->statementCustomerId = $customer->id;
        unset($this->statementCustomer);

        $today = $this->company->currentDateTime();
        $end = self::isoDate($end) ?? $today->toDateString();

        $this->statementAsOf = $end;
        $this->statementEnd = $end;
        $this->statementStart = self::isoDate($start) ?? $today->startOfYear()->toDateString();
        $this->statementToEmail = $customer->email ?? '';
        $this->statementMessage = __('Please find your statement attached.');

        Flux::modal('customer-statement')->show();
    }

    /** The event payload comes from the browser: accept a real Y-m-d only. */
    private static function isoDate(?string $value): ?string
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return CarbonImmutable::canBeCreatedFromFormat($value, 'Y-m-d') ? $value : null;
    }

    #[Computed]
    public function statementCustomer(): ?Contact
    {
        return $this->statementCustomerId ? Contact::find($this->statementCustomerId) : null;
    }

    public function emailStatement(SendCustomerStatement $action): void
    {
        $customer = Contact::where('is_customer', true)->findOrFail((int) $this->statementCustomerId);
        abort_unless($customer->company_id === $this->company->id, 403);

        $this->validate([
            'statementType' => ['required', Rule::enum(CustomerStatementType::class)],
            'statementAsOf' => ['required', 'date'],
            'statementStart' => ['required', 'date'],
            'statementEnd' => ['required', 'date'],
            'statementToEmail' => ['required', 'string'],
            'statementCc' => ['nullable', 'string'],
            'statementMessage' => ['nullable', 'string', 'max:2000'],
        ]);

        $to = $this->parseEmails($this->statementToEmail, 'statementToEmail');
        $cc = $this->parseEmails($this->statementCc, 'statementCc');

        if ($to === []) {
            throw ValidationException::withMessages(['statementToEmail' => __('Enter at least one recipient.')]);
        }

        // "CC my business email" copies the signed-in user, unless they're already
        // a recipient, so the sender keeps a record of exactly what went out.
        if ($this->statementCcSelf && ($me = Auth::user()?->email) && ! in_array($me, [...$to, ...$cc], true)) {
            $cc[] = $me;
        }

        $type = CustomerStatementType::from($this->statementType);

        $action->handle(
            $this->company,
            $customer,
            $type,
            $type === CustomerStatementType::Activity ? CarbonImmutable::parse($this->statementStart) : null,
            CarbonImmutable::parse($type === CustomerStatementType::OpenInvoices ? $this->statementAsOf : $this->statementEnd),
            $to,
            (string) $this->statementMessage,
            $cc,
        );

        Flux::modal('customer-statement')->close();
        Flux::toast(variant: 'success', text: __('Statement sent to :email.', ['email' => implode(', ', $to)]));
    }

    /**
     * Split a comma-separated address field into a validated, de-duplicated list,
     * failing the given field if any address is malformed.
     *
     * @return list<string>
     */
    private function parseEmails(?string $raw, string $field): array
    {
        $emails = collect(explode(',', (string) $raw))
            ->map(fn (string $email): string => trim($email))
            ->filter()
            ->unique()
            ->values();

        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([$field => __(':email is not a valid email address.', ['email' => $email])]);
            }
        }

        return $emails->all();
    }
}; ?>

<flux:modal name="customer-statement" class="md:w-[36rem]" data-test="customer-statement-modal">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Customer statement') }}</flux:heading>
            @if ($this->statementCustomer)
                <flux:subheading>{{ $this->statementCustomer->qualifiedName() }}</flux:subheading>
            @endif
        </div>

        <flux:radio.group wire:model.live="statementType" :label="__('Statement type')">
            <flux:radio
                value="open-invoices"
                :label="__('Open invoices')"
                :description="__('What\'s owed as of a date — each unpaid invoice with its due date, plus an aging summary.')"
                data-test="statement-type-open-invoices"
            />
            <flux:radio
                value="activity"
                :label="__('Account activity')"
                :description="__('Every charge and payment over a period, with a running balance.')"
                data-test="statement-type-activity"
            />
        </flux:radio.group>

        @if ($statementType === 'open-invoices')
            <flux:input type="date" wire:model.live="statementAsOf" :label="__('As of')" data-test="statement-as-of" />
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:input type="date" wire:model.live="statementStart" :label="__('From')" data-test="statement-start" />
                <flux:input type="date" wire:model.live="statementEnd" :label="__('To')" data-test="statement-end" />
            </div>
        @endif

        @if ($statementCustomerId)
            @php($statementParams = [
                'company' => $company->slug,
                'contact' => $statementCustomerId,
                'type' => $statementType,
                'as_of' => $statementAsOf,
                'start' => $statementStart,
                'end' => $statementEnd,
            ])
            <div class="flex gap-2">
                <flux:button :href="route('customers.statement.print', $statementParams)" target="_blank" icon="printer" data-test="statement-view-pdf">
                    {{ __('View PDF') }}
                </flux:button>
                <flux:button :href="route('customers.statement.download', $statementParams)" icon="arrow-down-tray" data-test="statement-download-pdf">
                    {{ __('Download') }}
                </flux:button>
            </div>
        @endif

        <flux:separator />

        <form wire:submit="emailStatement" class="space-y-6">
            <div>
                <flux:heading size="sm">{{ __('Email to customer') }}</flux:heading>
                <flux:subheading>{{ __('They get a one-click link to view their statement online, with the PDF attached.') }}</flux:subheading>
            </div>

            @if ($this->statementCustomer && ! $this->statementCustomer->invoice_emails_enabled)
                <flux:callout icon="information-circle" data-test="statement-opted-out">
                    {{ __('This customer has automated invoice emails turned off. Sending now will still reach them, and will not change that setting.') }}
                </flux:callout>
            @endif

            @php($emailSettings = $company->invoiceSettingsOrNew())
            <flux:field>
                <flux:label>{{ __('Replies go to') }}</flux:label>
                <flux:input
                    type="text"
                    :value="$emailSettings->email_from_address ?: __('System default')"
                    readonly
                    variant="filled"
                    data-test="statement-email-from"
                />
                <flux:description>
                    {{ __('Change this under') }}
                    <flux:link :href="route('settings.invoices', ['company' => $company])" wire:navigate>{{ __('Invoice settings') }}</flux:link>.
                </flux:description>
            </flux:field>

            <flux:input wire:model="statementToEmail" :label="__('To')" required :description="__('Separate multiple addresses with commas.')" data-test="statement-email-to" />

            <flux:input wire:model="statementCc" :label="__('CC')" data-test="statement-email-cc" />

            <flux:checkbox wire:model="statementCcSelf" :label="__('CC my business email')" data-test="statement-cc-self" />

            <flux:textarea wire:model="statementMessage" :label="__('Message')" rows="4" data-test="statement-message" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Close') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" icon="paper-airplane" data-test="statement-send">
                    {{ __('Send') }}
                </flux:button>
            </div>
        </form>
    </div>
</flux:modal>
