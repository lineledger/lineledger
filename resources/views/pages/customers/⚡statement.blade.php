<?php

use App\Actions\Sales\SendCustomerStatement;
use App\Concerns\HasReportDateRange;
use App\Enums\CustomerStatementType;
use App\Enums\Section;
use App\Models\Company;
use App\Models\Contact;
use App\Models\InvoiceSetting;
use App\Services\Reporting\CustomerStatementBuilder;
use App\Support\Reporting\ReportDatePresets;
use App\Support\Reporting\StatementColumns;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The customer-facing statement, previewed like any report and exported to PDF
 * (customers.statement.print / .download) or emailed exactly as shown. The
 * preview and the PDF read the same builder data and the same StatementColumns
 * registry, so what you see is what the customer gets.
 *
 * Open invoices: those dated Start–End still open as of End, led by a "Balance
 * forward" row for older open invoices, so Total Due ties to AR Aging. Under
 * the "All" period there is no lower bound. Account activity: the GL-backed
 * running balance over Start–End.
 */
new #[Title('Customer statement')] class extends Component {
    use HasReportDateRange;

    public Company $company;

    public Contact $contact;

    #[Url(as: 'type')]
    public string $type = 'open-invoices';

    /**
     * `?cols=` — this run's optional columns (StatementColumns::encode), or
     * null for the company's saved default.
     */
    #[Url(as: 'cols')]
    public ?string $cols = null;

    /**
     * The Columns menu's checked optional columns, kept in step with $cols.
     *
     * @var list<string>
     */
    public array $columns = [];

    public string $toEmail = '';

    public string $ccEmail = '';

    public bool $ccSelf = false;

    public string $emailMessage = '';

    public function mount(Company $company, Contact $contact): void
    {
        abort_unless($contact->company_id === $company->id && $contact->is_customer, 404);

        $this->company = $company;
        $this->contact = $contact;

        $type = $this->statementType();
        $this->type = $type->value;

        // Honour a ?range= preset from a link; otherwise each type's own default.
        $preset = $this->preset !== 'custom' && array_key_exists($this->preset, ReportDatePresets::options())
            ? $this->preset
            : self::defaultPreset($type);
        $this->initReportDateRange($preset);

        $requested = StatementColumns::parse($this->cols);
        $this->columns = StatementColumns::chosen($type, $requested, $this->savedColumns());
        $this->cols = $requested === null ? null : StatementColumns::encode($this->columns);

        $this->toEmail = (string) $contact->email;
        $this->emailMessage = __('Please find your statement attached.');
    }

    /**
     * Open invoices default to every open invoice ("All" — no lower bound);
     * account activity to the fiscal year to date.
     */
    private static function defaultPreset(CustomerStatementType $type): string
    {
        return $type === CustomerStatementType::OpenInvoices ? 'all' : 'this_fiscal_year_to_date';
    }

    public function statementType(): CustomerStatementType
    {
        return CustomerStatementType::tryFrom($this->type) ?? CustomerStatementType::OpenInvoices;
    }

    public function isOpenInvoices(): bool
    {
        return $this->statementType() === CustomerStatementType::OpenInvoices;
    }

    /**
     * Switching type swaps in that type's columns, and its default period when
     * the range is still the other type's default — a range the user picked
     * carries over.
     */
    public function updatedType(): void
    {
        $type = $this->statementType();
        $this->type = $type->value;

        $other = $type === CustomerStatementType::OpenInvoices ? CustomerStatementType::Activity : CustomerStatementType::OpenInvoices;

        if ($this->preset === self::defaultPreset($other)) {
            $this->preset = self::defaultPreset($type);
            $this->updatedPreset();
        }

        $this->cols = null;
        $this->columns = StatementColumns::chosen($type, null, $this->savedColumns());
        unset($this->defaultColumns);
    }

    public function updatedColumns(): void
    {
        $this->columns = StatementColumns::sanitize($this->statementType(), $this->columns);
        $this->cols = $this->columns === $this->defaultColumns ? null : StatementColumns::encode($this->columns);
    }

    /**
     * The company's saved optional columns for the current type, or null.
     *
     * @return list<string>|null
     */
    private function savedColumns(): ?array
    {
        return $this->company->invoiceSettingsOrNew()->statementColumnsFor($this->statementType());
    }

    /**
     * What the statement shows when no columns are requested: the saved
     * company default, else the built-in defaults.
     *
     * @return list<string>
     */
    #[Computed]
    public function defaultColumns(): array
    {
        return StatementColumns::chosen($this->statementType(), null, $this->savedColumns());
    }

    /**
     * @return array<string, string>
     */
    public function optionalColumns(): array
    {
        return StatementColumns::optional($this->statementType());
    }

    /**
     * Every column to render, fixed and optional, in display order.
     *
     * @return list<string>
     */
    public function visibleColumns(): array
    {
        return StatementColumns::visible($this->statementType(), $this->columns, null);
    }

    /**
     * The company default lives in Settings → Invoices, so saving it takes the
     * same access as that page: the Settings section and its save gate.
     */
    #[Computed]
    public function canSaveDefault(): bool
    {
        $user = Auth::user();

        return $user !== null
            && $user->canAccessSection($this->company, Section::Settings)
            && Gate::forUser($user)->allows('update', $this->company);
    }

    public function columnsDifferFromDefault(): bool
    {
        return $this->columns !== $this->defaultColumns;
    }

    public function saveDefaultColumns(): void
    {
        abort_unless($this->canSaveDefault, 403);

        $type = $this->statementType();
        $this->columns = StatementColumns::sanitize($type, $this->columns);

        $settings = InvoiceSetting::query()->where('company_id', $this->company->id)->first()
            ?? new InvoiceSetting(['company_id' => $this->company->id, ...InvoiceSetting::defaults()]);

        $settings->saveStatementColumns($type, $this->columns);

        $this->company->unsetRelation('invoiceSettings');
        unset($this->defaultColumns);
        $this->cols = null;

        Flux::toast(variant: 'success', text: __('These columns are now the default for :type statements.', ['type' => strtolower($type->label())]));
    }

    /** A real Y-m-d from the date inputs, or null. */
    private static function date(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ! CarbonImmutable::canBeCreatedFromFormat($value, 'Y-m-d')) {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

    public function endDateValue(): CarbonImmutable
    {
        return self::date($this->endDate) ?? $this->company->currentDateTime()->startOfDay();
    }

    public function startDateValue(): CarbonImmutable
    {
        return self::date($this->startDate) ?? $this->endDateValue()->startOfYear();
    }

    /**
     * The open-invoices lower bound: none under "All", else the start date.
     */
    public function openInvoicesStart(): ?CarbonImmutable
    {
        return $this->preset === 'all' ? null : $this->startDateValue();
    }

    /**
     * The statement's first day when it covers a period — always for account
     * activity, for open invoices only with a lower bound — else null (as-of).
     */
    public function periodStart(): ?CarbonImmutable
    {
        return $this->isOpenInvoices() ? $this->openInvoicesStart() : $this->startDateValue();
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function statement(): array
    {
        $builder = app(CustomerStatementBuilder::class);

        return $this->isOpenInvoices()
            ? $builder->openInvoices($this->company, $this->contact, $this->endDateValue(), $this->openInvoicesStart())
            : $builder->activity($this->company, $this->contact, $this->startDateValue(), $this->endDateValue());
    }

    public function totalDue(): int
    {
        return $this->isOpenInvoices()
            ? (int) $this->statement['total_due']
            : (int) $this->statement['statement']['closing'];
    }

    /**
     * The "Statement for" block, as the PDF prints it.
     *
     * @return list<string>
     */
    public function statementForLines(): array
    {
        return collect([
            $this->contact->qualifiedName(),
            $this->contact->billing_line1,
            $this->contact->billing_line2,
            collect([$this->contact->billing_city, $this->contact->billing_region, $this->contact->billing_postal_code])->filter()->implode(', '),
        ])->filter()->values()->all();
    }

    public function formatDate(?CarbonImmutable $date): string
    {
        return $date?->format('n/j/Y') ?? '—';
    }

    /**
     * The print/download query, exactly what the preview shows: explicit
     * columns, and an open-invoices start only when there is a lower bound.
     *
     * @return array<string, mixed>
     */
    public function pdfParams(): array
    {
        $params = [
            'company' => $this->company->slug,
            'contact' => $this->contact->id,
            'type' => $this->statementType()->value,
            'end' => $this->endDateValue()->toDateString(),
            'cols' => StatementColumns::encode($this->columns),
        ];

        if ($this->isOpenInvoices()) {
            $params['as_of'] = $params['end'];
        }

        if (($start = $this->periodStart()) !== null) {
            $params['start'] = $start->toDateString();
        }

        return $params;
    }

    #[Computed]
    public function canViewReports(): bool
    {
        return (bool) Auth::user()?->canAccessSection($this->company, Section::Reports);
    }

    #[Computed]
    public function replyToAddress(): ?string
    {
        return $this->company->invoiceSettingsOrNew()->email_from_address;
    }

    public function emailStatement(SendCustomerStatement $action): void
    {
        $this->validate([
            'toEmail' => ['required', 'string'],
            'ccEmail' => ['nullable', 'string'],
            'emailMessage' => ['nullable', 'string', 'max:2000'],
        ]);

        $to = $this->parseEmails($this->toEmail, 'toEmail');
        $cc = $this->parseEmails($this->ccEmail, 'ccEmail');

        if ($to === []) {
            throw ValidationException::withMessages(['toEmail' => __('Enter at least one recipient.')]);
        }

        // "CC my business email" copies the signed-in user, unless they're already
        // a recipient, so the sender keeps a record of exactly what went out.
        if ($this->ccSelf && ($me = Auth::user()?->email) && ! in_array($me, [...$to, ...$cc], true)) {
            $cc[] = $me;
        }

        $action->handle(
            $this->company,
            $this->contact,
            $this->statementType(),
            $this->periodStart(),
            $this->endDateValue(),
            $to,
            $this->emailMessage,
            $cc,
            StatementColumns::sanitize($this->statementType(), $this->columns),
        );

        Flux::modal('statement-email')->close();
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

<section class="w-full">
    <x-reports.control-bar
        :title="__('Customer statement')"
        :subtitle="$contact->qualifiedName().' · '.($this->periodStart() ? $this->formatDate($this->periodStart()).' – '.$this->formatDate($this->endDateValue()) : __('As of :date', ['date' => $this->formatDate($this->endDateValue())]))"
        mode="range"
        :exports="[]"
    >
        <flux:radio.group wire:model.live="type" :label="__('Statement')" variant="segmented" data-test="statement-type">
            <flux:radio value="open-invoices" :label="__('Open invoices')" data-test="statement-type-open-invoices" />
            <flux:radio value="activity" :label="__('Account activity')" data-test="statement-type-activity" />
        </flux:radio.group>

        <flux:dropdown align="end">
            <flux:button variant="ghost" icon="view-columns" icon:trailing="chevron-down" data-test="statement-columns-button">{{ __('Columns') }}</flux:button>
            <flux:menu>
                <flux:menu.checkbox.group wire:model.live="columns">
                    @foreach ($this->optionalColumns() as $key => $label)
                        <flux:menu.checkbox value="{{ $key }}" keep-open data-test="statement-column-{{ $key }}">{{ $label }}</flux:menu.checkbox>
                    @endforeach
                </flux:menu.checkbox.group>
            </flux:menu>
        </flux:dropdown>
    </x-reports.control-bar>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        @if ($this->canViewReports)
            <flux:button
                size="sm"
                variant="ghost"
                icon="arrow-left"
                :href="route('reports.contact-statement', ['company' => $company->slug, 'contact' => $contact->id, 'kind' => 'ar', 'start' => $this->startDateValue()->toDateString(), 'end' => $this->endDateValue()->toDateString()])"
                wire:navigate
                data-test="statement-back-to-report"
            >{{ __('AR statement') }}</flux:button>
        @endif
        <flux:button
            size="sm"
            variant="ghost"
            icon="users"
            :href="route('customers.index', ['company' => $company->slug])"
            wire:navigate
            data-test="statement-back-to-customers"
        >{{ __('Customers') }}</flux:button>

        <div class="grow"></div>

        @if ($this->canSaveDefault && $this->columnsDifferFromDefault())
            <flux:button size="sm" variant="ghost" icon="bookmark" wire:click="saveDefaultColumns" data-test="statement-save-default-columns">
                {{ __('Save as default columns') }}
            </flux:button>
        @endif

        <flux:button
            size="sm"
            icon="printer"
            :href="route('customers.statement.print', $this->pdfParams())"
            target="_blank"
            data-test="statement-view-pdf"
        >{{ __('View PDF') }}</flux:button>
        <flux:button
            size="sm"
            icon="arrow-down-tray"
            :href="route('customers.statement.download', $this->pdfParams())"
            data-test="statement-download-pdf"
        >{{ __('Download') }}</flux:button>
        <flux:modal.trigger name="statement-email">
            <flux:button size="sm" variant="primary" icon="envelope" data-test="statement-email-open">{{ __('Email…') }}</flux:button>
        </flux:modal.trigger>
    </div>

    <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="rounded-lg border border-border p-4" data-test="statement-for">
            <div class="mb-1 text-xs uppercase tracking-wide text-muted-foreground">{{ __('Statement for') }}</div>
            @foreach ($this->statementForLines() as $line)
                <div class="text-sm">{{ $line }}</div>
            @endforeach
        </div>

        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 rounded-lg border border-border p-4 text-sm" data-test="statement-meta">
            <dt class="text-muted-foreground">{{ __('Statement date') }}</dt>
            <dd class="text-right">{{ $this->formatDate($this->endDateValue()) }}</dd>
            @if ($this->periodStart())
                <dt class="text-muted-foreground">{{ __('Period') }}</dt>
                <dd class="text-right" data-test="statement-period">{{ $this->formatDate($this->periodStart()) }} – {{ $this->formatDate($this->endDateValue()) }}</dd>
            @else
                <dt class="text-muted-foreground">{{ __('As of') }}</dt>
                <dd class="text-right" data-test="statement-as-of">{{ $this->formatDate($this->endDateValue()) }}</dd>
            @endif
            <dt class="font-semibold">{{ $this->totalDue() < 0 ? __('Credit balance') : ($this->isOpenInvoices() ? __('Total due') : __('Balance due')) }}</dt>
            <dd class="text-right font-mono font-semibold" data-test="statement-total-due">${{ StatementColumns::money($this->totalDue()) }}</dd>
        </dl>
    </div>

    @php($statementColumns = $this->visibleColumns())
    @php($labelSpan = count($statementColumns) - 1)

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm" data-test="statement-preview">
            <thead class="bg-muted">
                <tr>
                    @foreach ($statementColumns as $key)
                        <th class="px-4 py-2 {{ StatementColumns::isNumeric($key) ? 'text-right' : 'text-left' }}" data-test="statement-column-header-{{ $key }}">{{ StatementColumns::label($this->statementType(), $key) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @if ($this->isOpenInvoices())
                    @forelse ($this->statement['rows'] as $row)
                        @if ($row['kind'] === 'invoice')
                            <tr data-test="statement-row">
                                @foreach ($statementColumns as $key)
                                    <td class="px-4 py-2 {{ StatementColumns::isNumeric($key) ? 'text-right font-mono' : ($key === 'memo' ? 'text-muted-foreground' : 'whitespace-nowrap') }}">{{ StatementColumns::cell($key, $row) }}</td>
                                @endforeach
                            </tr>
                        @else
                            <tr class="bg-muted/50" data-test="statement-{{ $row['kind'] }}-row">
                                <td class="px-4 py-2 italic text-muted-foreground" colspan="{{ $labelSpan }}">{{ $row['label'] }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ StatementColumns::money($row['balance']) }}</td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="{{ count($statementColumns) }}" class="px-4 py-8 text-center text-muted-foreground" data-test="statement-empty">
                                {{ __('No open invoices — thank you, your account is up to date.') }}
                            </td>
                        </tr>
                    @endforelse
                @else
                    <tr class="bg-muted/50">
                        <td class="px-4 py-2 italic text-muted-foreground" colspan="{{ $labelSpan }}">{{ __('Opening balance') }}</td>
                        <td class="px-4 py-2 text-right font-mono" data-test="statement-opening">{{ StatementColumns::money($this->statement['statement']['opening']) }}</td>
                    </tr>
                    @forelse ($this->statement['statement']['lines'] as $line)
                        <tr data-test="statement-row">
                            @foreach ($statementColumns as $key)
                                <td class="px-4 py-2 {{ StatementColumns::isNumeric($key) ? 'text-right font-mono' : ($key === 'memo' ? 'text-muted-foreground' : 'whitespace-nowrap') }}">{{ StatementColumns::cell($key, $line) }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($statementColumns) }}" class="px-4 py-8 text-center text-muted-foreground" data-test="statement-empty">
                                {{ __('No transactions in this period.') }}
                            </td>
                        </tr>
                    @endforelse
                @endif
            </tbody>
            <tfoot class="bg-muted">
                <tr>
                    <td class="px-4 py-3 text-right font-semibold" colspan="{{ $labelSpan }}">
                        {{ $this->totalDue() < 0 ? __('Credit balance') : ($this->isOpenInvoices() ? __('Total due') : __('Balance due')) }}
                    </td>
                    <td class="px-4 py-3 text-right font-mono font-semibold" data-test="statement-total">${{ StatementColumns::money($this->totalDue()) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="mt-4 overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm" data-test="statement-aging">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-right">{{ __('Current') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('1–30 days') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('31–60 days') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('61–90 days') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('90+ days') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Total due') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    @foreach (['current', 'b1_30', 'b31_60', 'b61_90', 'b90_plus'] as $bucket)
                        <td class="px-4 py-2 text-right font-mono">{{ StatementColumns::money($this->statement['aging'][$bucket]) }}</td>
                    @endforeach
                    <td class="px-4 py-2 text-right font-mono font-semibold" data-test="statement-aging-total">{{ StatementColumns::money($this->statement['aging']['total']) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <flux:modal name="statement-email" class="md:w-[36rem]" data-test="statement-email-modal">
        <form wire:submit="emailStatement" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Email statement') }}</flux:heading>
                <flux:subheading>{{ __('They get a one-click link to view their statement online, with the PDF attached exactly as previewed.') }}</flux:subheading>
            </div>

            @if (! $contact->invoice_emails_enabled)
                <flux:callout icon="information-circle" data-test="statement-opted-out">
                    {{ __('This customer has automated invoice emails turned off. Sending now will still reach them, and will not change that setting.') }}
                </flux:callout>
            @endif

            <flux:field>
                <flux:label>{{ __('Replies go to') }}</flux:label>
                <flux:input
                    type="text"
                    :value="$this->replyToAddress ?: __('System default')"
                    readonly
                    variant="filled"
                    data-test="statement-email-from"
                />
                <flux:description>
                    {{ __('Change this under') }}
                    <flux:link :href="route('settings.invoices', ['company' => $company])" wire:navigate>{{ __('Invoice settings') }}</flux:link>.
                </flux:description>
            </flux:field>

            <flux:input wire:model="toEmail" :label="__('To')" required :description="__('Separate multiple addresses with commas.')" data-test="statement-email-to" />

            <flux:input wire:model="ccEmail" :label="__('CC')" data-test="statement-email-cc" />

            <flux:checkbox wire:model="ccSelf" :label="__('CC my business email')" data-test="statement-cc-self" />

            <flux:textarea wire:model="emailMessage" :label="__('Message')" rows="4" data-test="statement-message" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Close') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" icon="paper-airplane" data-test="statement-send">
                    {{ __('Send') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
