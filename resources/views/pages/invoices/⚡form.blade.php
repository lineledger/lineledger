<?php

use App\Actions\Sales\SaveInvoice;
use App\Actions\Sales\SaveInvoiceTemplate;
use App\Enums\AccountType;
use App\Enums\InvoiceStatus;
use App\Enums\ItemType;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Account;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Contact;
use App\Models\FormStyle;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use App\Models\InvoiceTemplate;
use App\Models\InvoiceTemplateLine;
use App\Models\Item;
use App\Models\Location;
use App\Models\MembershipLevel;
use App\Models\PaymentTerm;
use App\Models\TaxCode;
use App\Rules\MoneyString;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\TaxCalculator;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Invoice')] class extends Component
{
    public Company $company;

    public ?Invoice $invoice = null;

    public ?int $contact_id = null;

    public string $contact_query = '';

    public bool $contact_creating = false;

    public string $new_contact_name = '';

    public string $invoice_no = '';

    public string $invoice_date = '';

    public string $due_date = '';

    public ?int $terms_id = null;

    public ?int $form_style_id = null;

    public ?int $sales_rep_id = null;

    public string $customer_po = '';

    public string $ship_date = '';

    public string $ship_via = '';

    public string $fob = '';

    public string $tracking_no = '';

    public string $memo = '';

    public string $customer_message = '';

    public string $document_discount_pct = '';

    /**
     * Selected template to pull line items from when creating a new invoice.
     * Only shown on create; ignored on save (it is purely a population trigger).
     */
    public ?int $template_id = null;

    /**
     * Name entered in the "Save as template" modal.
     */
    public string $template_name = '';

    /**
     * @var array<int, array{
     *     item_id: ?int,
     *     account_id: ?int,
     *     description: string,
     *     service_date: string,
     *     quantity: string,
     *     unit_price: string,
     *     discount_pct: string,
     *     tax_code_id: ?int,
     *     class_id: ?int,
     *     location_id: ?int,
     *     subtotal: int,
     *     tax: int,
     *     total: int
     * }>
     */
    public array $lines = [];

    /**
     * Which optional header fields and line columns are shown on this form.
     * Persisted per-company on InvoiceSetting so the layout sticks across invoices.
     *
     * @var array<string, bool>
     */
    public array $fieldVisibility = [];

    public function mount(Company $company, ?Invoice $invoice = null): void
    {
        $this->company = $company;

        $this->loadFieldVisibility();

        if ($invoice && $invoice->exists) {
            abort_if($invoice->status === InvoiceStatus::Void, 403, 'Voided invoices cannot be edited.');

            $this->invoice = $invoice->load('lines');
            $this->contact_id = $invoice->contact_id;
            $this->invoice_no = $invoice->invoice_no;
            $this->invoice_date = $invoice->invoice_date->toDateString();
            $this->due_date = $invoice->due_date->toDateString();
            $this->terms_id = $invoice->terms_id;
            $this->form_style_id = $invoice->form_style_id;
            $this->sales_rep_id = $invoice->sales_rep_id;
            $this->customer_po = $invoice->customer_po ?? '';
            $this->ship_date = $invoice->ship_date?->toDateString() ?? '';
            $this->ship_via = $invoice->ship_via ?? '';
            $this->fob = $invoice->fob ?? '';
            $this->tracking_no = $invoice->tracking_no ?? '';
            $this->memo = $invoice->memo ?? '';
            $this->customer_message = $invoice->customer_message ?? '';
            $this->document_discount_pct = $invoice->document_discount_pct !== null ? rtrim(rtrim((string) $invoice->document_discount_pct, '0'), '.') : '';

            $this->lines = $invoice->lines->map(fn ($l) => [
                'item_id' => $l->item_id,
                'account_id' => $l->account_id,
                'description' => $l->description ?? '',
                'service_date' => $l->service_date?->toDateString() ?? '',
                'quantity' => Quantity::format($l->quantity),
                'unit_price' => Money::fromCents((int) $l->unit_price_cents)->toDecimalString(),
                'discount_pct' => $l->line_discount_pct !== null ? rtrim(rtrim((string) $l->line_discount_pct, '0'), '.') : '',
                'markup_pct' => $l->line_markup_pct !== null ? rtrim(rtrim((string) $l->line_markup_pct, '0'), '.') : '',
                'tax_code_id' => $l->tax_code_id,
                'secondary_tax_code_id' => $l->secondary_tax_code_id,
                'tax_code_ids' => array_values(array_filter([$l->tax_code_id, $l->secondary_tax_code_id])),
                'class_id' => $l->class_id,
                'location_id' => $l->location_id,
                'subtotal' => (int) $l->line_subtotal_cents,
                'tax' => (int) $l->line_tax_cents,
                'secondary_tax' => (int) $l->secondary_tax_cents,
                'total' => (int) $l->line_total_cents,
            ])->all();
        } else {
            $this->invoice_date = $this->company->currentDateTime()->toDateString();
            $this->due_date = $this->company->currentDateTime()->addDays(30)->toDateString();
            $this->invoice_no = app(DocumentNumberGenerator::class)->next($company, Invoice::class, 'invoice_no', 'INV');
            $this->form_style_id = FormStyle::query()
                ->where('is_default', true)
                ->where('is_active', true)
                ->value('id');
            $this->lines = [$this->emptyLine()];
        }
    }

    /**
     * The toggleable fields, keyed by the property name used in $fieldVisibility,
     * mapped to the InvoiceSetting column that persists them.
     *
     * @var array<string, string>
     */
    protected const VISIBILITY_MAP = [
        'terms' => 'show_terms',
        'template' => 'show_template',
        'sales_rep' => 'show_sales_rep',
        'customer_po' => 'show_customer_po',
        'ship_date' => 'show_ship_date',
        'ship_via' => 'show_ship_via',
        'fob' => 'show_fob',
        'tracking_no' => 'show_tracking_no',
        'memo' => 'show_memo',
        'customer_message' => 'show_customer_message',
        'item_column' => 'show_item_column',
        'qty_column' => 'show_qty_column',
        'tax_column' => 'show_tax_column',
        'service_date_column' => 'show_service_date_column',
        'account_column' => 'show_account_column',
        'discount_column' => 'show_discount_column',
        'markup_column' => 'show_markup_column',
        'class_column' => 'show_class_column',
        'location_column' => 'show_location_column',
        'document_discount' => 'show_document_discount',
    ];

    protected function loadFieldVisibility(): void
    {
        $settings = $this->company->invoiceSettingsOrNew();

        foreach (self::VISIBILITY_MAP as $key => $column) {
            $this->fieldVisibility[$key] = (bool) $settings->{$column};
        }
    }

    /**
     * Persist a toggle change immediately so the layout sticks for the company.
     */
    public function updatedFieldVisibility(): void
    {
        $payload = [];

        foreach (self::VISIBILITY_MAP as $key => $column) {
            $payload[$column] = (bool) ($this->fieldVisibility[$key] ?? true);
        }

        InvoiceSetting::updateOrCreate(['company_id' => $this->company->id], $payload);
    }

    /**
     * @return array{item_id: ?int, account_id: ?int, description: string, service_date: string, quantity: string, unit_price: string, discount_pct: string, tax_code_id: ?int, class_id: ?int, location_id: ?int, subtotal: int, tax: int, total: int}
     */
    protected function emptyLine(): array
    {
        return [
            'item_id' => null,
            'account_id' => $this->configuredSalesAccountId,
            'description' => '',
            'service_date' => '',
            'quantity' => '1',
            'unit_price' => '0.00',
            'discount_pct' => '',
            'markup_pct' => '',
            'tax_code_id' => null,
            'secondary_tax_code_id' => null,
            'tax_code_ids' => [],
            'class_id' => null,
            'location_id' => null,
            'subtotal' => 0,
            'tax' => 0,
            'secondary_tax' => 0,
            'total' => 0,
        ];
    }

    public function addLine(): void
    {
        $this->lines[] = $this->emptyLine();
    }

    public function removeLine(int $i): void
    {
        if (count($this->lines) <= 1) {
            return;
        }
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    /**
     * Triggered when an item is picked — prefill the line.
     */
    public function updatedLines(mixed $value, ?string $key = null): void
    {
        // Livewire passes a null key when the whole `lines` array is updated
        // (a top-level, dot-less path) rather than a single nested field.
        if ($key === null) {
            return;
        }

        if (! str_ends_with($key, '.item_id')) {
            $i = (int) explode('.', $key)[0];

            // The tax picker is a multi-select bound to tax_code_ids; fan the
            // (max two) chosen codes back out to the primary/secondary columns
            // that drive calculation and posting.
            if (str_ends_with($key, '.tax_code_ids')) {
                $ids = array_slice(array_values(array_unique(array_filter(
                    array_map('intval', (array) $this->lines[$i]['tax_code_ids'])
                ))), 0, 2);
                $this->lines[$i]['tax_code_id'] = $ids[0] ?? null;
                $this->lines[$i]['secondary_tax_code_id'] = $ids[1] ?? null;
            }

            // Picking an account fills a blank tax code from the account's
            // default — never overwriting one already on the line, so item
            // and contact defaults keep their existing precedence.
            if (str_ends_with($key, '.account_id') && $value && empty($this->lines[$i]['tax_code_id'])) {
                $this->lines[$i]['tax_code_id'] = Account::find($value)?->default_tax_code_id;
            }

            $this->recalcLine($i);

            return;
        }

        $i = (int) explode('.', $key)[0];
        $itemId = $value;

        // Membership levels share the item combo (a "level:{id}" sentinel value).
        // They aren't catalog items — prefill the line from the level and bail.
        if (is_string($itemId) && str_starts_with($itemId, 'level:')) {
            $this->applyMembershipLevel($i, (int) mb_substr($itemId, 6));

            return;
        }

        if ($itemId) {
            $item = Item::find($itemId);

            if ($item && $item->type === ItemType::Bundle) {
                $this->expandBundle($i, $item);

                return;
            }

            if ($item) {
                $this->lines[$i]['account_id'] = $item->income_account_id;
                $this->lines[$i]['description'] = $item->description ?? $item->name;
                $this->lines[$i]['unit_price'] = Money::fromCents((int) $item->default_price_cents)->toDecimalString();
                $this->lines[$i]['tax_code_id'] = $item->default_tax_code_id;
                $this->lines[$i]['secondary_tax_code_id'] = $item->default_secondary_tax_code_id;
            }
        }

        $this->recalcLine($i);
    }

    /**
     * Prefill a line from a membership level picked in the item combo. The
     * "level:{id}" sentinel stays in item_id so the combo keeps showing the level
     * name; persist() strips it before save since a level is not a catalog item.
     */
    protected function applyMembershipLevel(int $i, int $levelId): void
    {
        $level = MembershipLevel::find($levelId);

        if ($level) {
            if ($level->revenue_account_id) {
                $this->lines[$i]['account_id'] = $level->revenue_account_id;
            }

            $this->lines[$i]['description'] = __('Membership dues: :level', ['level' => $level->name]);
            $this->lines[$i]['unit_price'] = Money::fromCents((int) $level->default_dues_cents)->toDecimalString();
            $this->lines[$i]['tax_code_id'] = $level->default_tax_code_id;
        }

        $this->recalcLine($i);
    }

    /**
     * Replace the picked bundle line with one prefilled line per component.
     */
    protected function expandBundle(int $i, Item $bundle): void
    {
        $bundle->loadMissing('components.component');

        $newLines = [];

        foreach ($bundle->components as $component) {
            $comp = $component->component;

            if (! $comp) {
                continue;
            }

            $line = $this->emptyLine();
            $line['item_id'] = $comp->id;
            $line['account_id'] = $comp->income_account_id;
            $line['description'] = $comp->description ?? $comp->name;
            $line['quantity'] = Quantity::format($component->quantity);
            $line['unit_price'] = Money::fromCents((int) $comp->default_price_cents)->toDecimalString();
            $line['tax_code_id'] = $comp->default_tax_code_id;
            $line['secondary_tax_code_id'] = $comp->default_secondary_tax_code_id;

            $newLines[] = $line;
        }

        if ($newLines === []) {
            // A bundle with no components: just clear the picked item on the line.
            $this->lines[$i]['item_id'] = null;

            return;
        }

        array_splice($this->lines, $i, 1, $newLines);
        $this->lines = array_values($this->lines);

        for ($idx = $i, $end = $i + count($newLines); $idx < $end; $idx++) {
            $this->recalcLine($idx);
        }
    }

    public function selectContact(int $id): void
    {
        $this->contact_id = $id;
        $this->contact_creating = false;
        $this->new_contact_name = '';
        $this->contact_query = '';
        $this->resetErrorBag(['contact_id', 'new_contact_name']);

        $this->applyContactDefaults();
    }

    public function startNewContact(): void
    {
        $this->new_contact_name = trim($this->contact_query);
        $this->contact_creating = true;
        $this->contact_id = null;
        $this->contact_query = '';
        $this->resetErrorBag(['contact_id', 'new_contact_name']);
    }

    public function clearContact(): void
    {
        $this->contact_id = null;
        $this->contact_creating = false;
        $this->new_contact_name = '';
        $this->contact_query = '';
        $this->resetErrorBag(['contact_id', 'new_contact_name']);
    }

    protected function applyContactDefaults(): void
    {
        if (! $this->contact_id) {
            return;
        }

        $contact = Contact::find($this->contact_id);

        if ($contact?->default_terms_id) {
            $this->terms_id = $contact->default_terms_id;
            $term = PaymentTerm::find($this->terms_id);

            if ($term && $this->invoice_date) {
                $this->due_date = $term->dueDateFrom(CarbonImmutable::parse($this->invoice_date))->toDateString();
            }
        }

        if ($contact?->default_tax_code_id) {
            foreach ($this->lines as $i => $line) {
                if (! $line['tax_code_id']) {
                    $this->lines[$i]['tax_code_id'] = $contact->default_tax_code_id;
                    $this->recalcLine($i);
                }
            }
        }
    }

    public function updatedTermsId(?int $value): void
    {
        if ($value && $this->invoice_date) {
            $term = PaymentTerm::find($value);
            if ($term) {
                $this->due_date = $term->dueDateFrom(CarbonImmutable::parse($this->invoice_date))->toDateString();
            }
        }
    }

    /**
     * Pull a template's line items onto the form when one is picked. Only acts
     * while creating (templates are hidden when editing). Replaces the seeded
     * blank line if it is still pristine, otherwise appends — then recomputes
     * totals through the existing recalcLine() so no tax math is duplicated.
     */
    public function updatedTemplateId(?int $value): void
    {
        if ($this->invoice?->id || ! $value) {
            return;
        }

        $template = InvoiceTemplate::with('lines')->find($value);

        if (! $template) {
            return;
        }

        $mapped = $template->lines->map(fn (InvoiceTemplateLine $tl) => $this->lineFromTemplate($tl))->all();

        if ($mapped === []) {
            return;
        }

        if (count($this->lines) === 1 && $this->isEmptyLine($this->lines[0])) {
            $this->lines = $mapped;
        } else {
            $this->lines = array_merge($this->lines, $mapped);
        }

        $this->lines = array_values($this->lines);

        foreach (array_keys($this->lines) as $i) {
            $this->recalcLine($i);
        }
    }

    /**
     * Map a template line into the form's $lines shape (mirrors emptyLine()).
     * Totals are seeded to 0 and authoritatively recomputed by recalcLine().
     *
     * @return array<string, mixed>
     */
    protected function lineFromTemplate(InvoiceTemplateLine $tl): array
    {
        return [
            'item_id' => $tl->item_id,
            'account_id' => $tl->account_id ?? $this->configuredSalesAccountId,
            'description' => $tl->description ?? '',
            'service_date' => '',
            'quantity' => Quantity::format($tl->quantity),
            'unit_price' => Money::fromCents((int) $tl->unit_price_cents)->toDecimalString(),
            'discount_pct' => $tl->line_discount_pct !== null ? rtrim(rtrim((string) $tl->line_discount_pct, '0'), '.') : '',
            'markup_pct' => $tl->line_markup_pct !== null ? rtrim(rtrim((string) $tl->line_markup_pct, '0'), '.') : '',
            'tax_code_id' => $tl->tax_code_id,
            'secondary_tax_code_id' => $tl->secondary_tax_code_id,
            'tax_code_ids' => array_values(array_filter([$tl->tax_code_id, $tl->secondary_tax_code_id])),
            'class_id' => $tl->class_id,
            'location_id' => $tl->location_id,
            'subtotal' => 0,
            'tax' => 0,
            'secondary_tax' => 0,
            'total' => 0,
        ];
    }

    /**
     * Whether a line is still the pristine seeded blank — used to decide
     * replace-vs-append when applying a template and which lines to skip when
     * saving the current invoice as a template.
     *
     * @param  array<string, mixed>  $line
     */
    protected function isEmptyLine(array $line): bool
    {
        return empty($line['item_id'])
            && trim((string) ($line['description'] ?? '')) === ''
            && empty($line['tax_code_id'])
            && in_array((string) ($line['unit_price'] ?? ''), ['', '0', '0.00'], true)
            && (int) ($line['total'] ?? 0) === 0;
    }

    /**
     * Capture the current line items as a reusable invoice template. Reuses the
     * same SaveInvoiceTemplate action as the dedicated management page.
     */
    public function saveAsTemplate(): void
    {
        $validated = $this->validate(
            ['template_name' => ['required', 'string', 'max:255']],
            attributes: ['template_name' => __('template name')],
        );

        $lines = collect($this->lines)
            ->reject(fn (array $line): bool => $this->isEmptyLine($line))
            ->map(fn (array $line): array => [
                'item_id' => is_numeric($line['item_id'] ?? null) ? $line['item_id'] : null,
                'account_id' => $line['account_id'] ?? null,
                'description' => $line['description'] ?? '',
                'quantity' => ($line['quantity'] ?? '') === '' ? '1' : $line['quantity'],
                'unit_price_cents' => Money::fromString(($line['unit_price'] ?? '') === '' ? '0' : $line['unit_price'])->cents,
                'line_discount_pct' => ($line['discount_pct'] ?? '') !== '' ? $line['discount_pct'] : null,
                'line_markup_pct' => ($line['markup_pct'] ?? '') !== '' ? $line['markup_pct'] : null,
                'tax_code_id' => $line['tax_code_id'] ?? null,
                'secondary_tax_code_id' => $line['secondary_tax_code_id'] ?? null,
                'class_id' => $line['class_id'] ?? null,
                'location_id' => $line['location_id'] ?? null,
            ])
            ->values()
            ->all();

        if ($lines === []) {
            $this->addError('template_name', __('Add at least one line item before saving a template.'));

            return;
        }

        app(SaveInvoiceTemplate::class)->handle([
            'name' => $validated['template_name'],
            'is_active' => true,
            'lines' => $lines,
        ]);

        Flux::modal('save-as-template')->close();
        $this->template_name = '';
        unset($this->templateOptions);
        Flux::toast(variant: 'success', text: __('Template saved.'));
    }

    #[Computed]
    public function templateOptions()
    {
        return InvoiceTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    protected function recalcLine(int $i): void
    {
        $calc = app(TaxCalculator::class);

        $line = $this->lines[$i];

        $qty = $line['quantity'] === '' ? '0' : $line['quantity'];
        $price = $line['unit_price'] === '' ? '0' : $line['unit_price'];

        try {
            $unitCents = Money::fromString($price)->cents;
        } catch (Throwable) {
            $unitCents = 0;
        }

        $taxCode = $line['tax_code_id'] ? TaxCode::find($line['tax_code_id']) : null;
        $secondaryTaxCode = ($line['secondary_tax_code_id'] ?? null) ? TaxCode::find($line['secondary_tax_code_id']) : null;

        // Keep the multi-select picker in step with the columns when a tax code
        // is set indirectly (item/account/contact defaults, bundles, etc.).
        $this->lines[$i]['tax_code_ids'] = array_values(array_filter([$line['tax_code_id'], $line['secondary_tax_code_id'] ?? null]));

        $discountPct = ($line['discount_pct'] ?? '') === '' ? null : $line['discount_pct'];
        $markupPct = ($line['markup_pct'] ?? '') === '' ? null : $line['markup_pct'];

        $totals = $calc->line($qty, $unitCents, $taxCode, 0, $discountPct, 0, $markupPct, $secondaryTaxCode);

        $this->lines[$i]['subtotal'] = $totals['subtotal_cents'];
        $this->lines[$i]['tax'] = $totals['tax_cents'];
        $this->lines[$i]['secondary_tax'] = $totals['secondary_tax_cents'];
        $this->lines[$i]['total'] = $totals['total_cents'];
    }

    public function saveDraft(): void
    {
        // Disallow "save as draft" once the invoice has been posted — that
        // would unpost the GL. Use the in-place Save button below instead.
        if ($this->invoice?->journal_entry_id) {
            $this->addError('lines', __('This invoice is posted. Use Save to update it in place.'));

            return;
        }

        $this->persist();
        Flux::toast(variant: 'success', text: __('Draft saved.'));
        $this->redirectRoute('invoices.edit', ['company' => $this->company->slug, 'invoice' => $this->invoice->id], navigate: true);
    }

    public function postInvoice(InvoicePoster $poster): void
    {
        $wasPosted = $this->invoice?->journal_entry_id !== null;

        $this->persist();

        try {
            $wasPosted ? $poster->repost($this->invoice) : $poster->post($this->invoice);
        } catch (PeriodLockedException|RuntimeException $e) {
            $this->addError('lines', $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: $wasPosted ? __('Invoice updated.') : __('Invoice posted.'));
        $this->redirectRoute('invoices.show', ['company' => $this->company->slug, 'invoice' => $this->invoice->id], navigate: true);
    }

    protected function persist(): void
    {
        $companyId = $this->company->id;

        if ($this->contact_creating) {
            $name = trim($this->new_contact_name);

            $this->validate(
                ['new_contact_name' => ['required', 'string', 'max:255']],
                attributes: ['new_contact_name' => __('customer name')],
            );

            $contact = Contact::create([
                'display_name' => $name,
                'is_customer' => true,
                'is_active' => true,
            ]);

            $this->contact_id = $contact->id;
            $this->contact_creating = false;
            $this->new_contact_name = '';
            $this->applyContactDefaults();
        }

        // Code any line that lacks an account to the company's default sales account
        // (invoice settings) so a blank or hidden Account column never silently blocks
        // the post. Items with their own income account already prefill it. When no
        // default sales account is configured, fall back to the first income account
        // only if the Account column is hidden (the user can't pick one there).
        $accountColumnVisible = $this->fieldVisibility['account_column'] ?? true;
        $defaultAccountId = $this->configuredSalesAccountId
            ?? (! $accountColumnVisible ? $this->defaultIncomeAccountId : null);

        if ($defaultAccountId !== null) {
            foreach ($this->lines as $i => $line) {
                if (empty($line['account_id'])) {
                    $this->lines[$i]['account_id'] = $defaultAccountId;
                }
            }
        }

        if (! $accountColumnVisible && collect($this->lines)->contains(fn (array $l): bool => empty($l['account_id']))) {
            $this->addError('lines', __('Set a default sales account in invoice settings, or show the Account column to code each line.'));

            return;
        }

        // Membership-level picks carry a "level:{id}" sentinel in item_id purely to
        // drive the prefill; they are not catalog items, so drop any non-numeric
        // item_id before validation (account, description and price are already set).
        foreach ($this->lines as $i => $line) {
            if (! is_numeric($line['item_id'] ?? null)) {
                $this->lines[$i]['item_id'] = null;
            }
        }

        $validated = $this->validate([
            'contact_id' => ['required', 'integer', Rule::exists('contacts', 'id')->where('company_id', $companyId)->where('is_customer', true)],
            'invoice_no' => ['required', 'string', 'max:40'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['required', 'date'],
            'terms_id' => ['nullable', 'integer', Rule::exists('payment_terms', 'id')->where('company_id', $companyId)],
            'form_style_id' => ['nullable', 'integer', Rule::exists('form_styles', 'id')->where('company_id', $companyId)],
            'sales_rep_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('company_id', $companyId)->where('is_employee', true)],
            'customer_po' => ['nullable', 'string', 'max:100'],
            'ship_date' => ['nullable', 'date'],
            'ship_via' => ['nullable', 'string', 'max:255'],
            'fob' => ['nullable', 'string', 'max:255'],
            'tracking_no' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string'],
            'customer_message' => ['nullable', 'string'],
            'document_discount_pct' => ['nullable', 'numeric', 'between:0,100'],
            'lines' => ['array', 'min:1'],
            'lines.*.item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'lines.*.description' => ['nullable', 'string', 'max:8000'],
            'lines.*.service_date' => ['nullable', 'date'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['required', 'string', new MoneyString],
            'lines.*.discount_pct' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.markup_pct' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('company_id', $companyId)],
            'lines.*.secondary_tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('company_id', $companyId)],
            'lines.*.class_id' => ['nullable', 'integer', Rule::exists('classifications', 'id')->where('company_id', $companyId)],
            'lines.*.location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('company_id', $companyId)],
        ]);

        $this->invoice = app(SaveInvoice::class)->handle([
            'contact_id' => $validated['contact_id'],
            'sales_rep_id' => $validated['sales_rep_id'] ?: null,
            'invoice_no' => $validated['invoice_no'],
            'invoice_date' => $validated['invoice_date'],
            'due_date' => $validated['due_date'],
            'ship_date' => $validated['ship_date'] ?: null,
            'ship_via' => $validated['ship_via'] ?: null,
            'fob' => $validated['fob'] ?: null,
            'tracking_no' => $validated['tracking_no'] ?: null,
            'customer_po' => $validated['customer_po'] ?: null,
            'terms_id' => $validated['terms_id'] ?: null,
            'form_style_id' => $validated['form_style_id'] ?: null,
            'memo' => $validated['memo'] ?: null,
            'customer_message' => $validated['customer_message'] ?: null,
            'document_discount_pct' => ($validated['document_discount_pct'] ?? '') !== '' ? $validated['document_discount_pct'] : null,
            'lines' => array_map(fn (array $line): array => [
                'item_id' => $line['item_id'] ?? null,
                'account_id' => $line['account_id'],
                'description' => $line['description'] ?? '',
                'service_date' => ($line['service_date'] ?? '') ?: null,
                'quantity' => $line['quantity'],
                'unit_price_cents' => Money::fromString($line['unit_price'])->cents,
                'line_discount_pct' => ($line['discount_pct'] ?? '') !== '' ? $line['discount_pct'] : null,
                'line_markup_pct' => ($line['markup_pct'] ?? '') !== '' ? $line['markup_pct'] : null,
                'tax_code_id' => $line['tax_code_id'] ?? null,
                'secondary_tax_code_id' => $line['secondary_tax_code_id'] ?? null,
                'class_id' => $line['class_id'] ?? null,
                'location_id' => $line['location_id'] ?? null,
            ], $validated['lines']),
        ], $this->invoice);
    }

    #[Computed]
    public function customers()
    {
        $query = Contact::query()->where('is_customer', true)->where('is_active', true);

        if (trim($this->contact_query) !== '') {
            $query->where('display_name', 'like', '%'.trim($this->contact_query).'%');
        }

        return $query->orderBy('display_name')->limit(50)->get(['id', 'display_name']);
    }

    #[Computed]
    public function selectedContactName(): ?string
    {
        return $this->contact_id
            ? Contact::query()->where('id', $this->contact_id)->value('display_name')
            : null;
    }

    #[Computed]
    public function termsOptions()
    {
        return PaymentTerm::query()->where('is_active', true)->orderBy('days')->get();
    }

    #[Computed]
    public function formStyleOptions()
    {
        return FormStyle::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function salesRepOptions()
    {
        return Contact::query()
            ->where('is_employee', true)
            ->where('is_active', true)
            ->orderBy('display_name')
            ->get(['id', 'display_name']);
    }

    #[Computed]
    public function itemOptions()
    {
        $items = Item::query()->with('category')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku', 'item_category_id'])
            ->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'sku' => $i->sku, 'category' => $i->category?->name]);

        // Membership levels are pickable too (a "level:{id}" sentinel id) so dues
        // can be billed straight from an invoice line; selecting one prefills the
        // revenue account, description and dues amount. See applyMembershipLevel().
        if ($this->company->tracksMembership()) {
            $levels = MembershipLevel::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($l) => ['id' => 'level:'.$l->id, 'name' => $l->name, 'sku' => null, 'category' => __('Membership level')]);

            return $items->concat($levels)->values();
        }

        return $items;
    }

    #[Computed]
    public function accountOptions()
    {
        // Keep any account already coded on a line visible, even if it has
        // since been deactivated, so editing never silently drops a selection.
        $lineAccountIds = collect($this->lines)->pluck('account_id')->filter()->all();

        return Account::query()
            ->where(function ($q) use ($lineAccountIds) {
                $q->where(fn ($inner) => $inner->selectableForItemAccount()->where('is_active', true));

                if ($lineAccountIds !== []) {
                    $q->orWhereIn('id', $lineAccountIds);
                }
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function taxCodeOptions()
    {
        return TaxCode::query()->where('is_active', true)->orderBy('code')->get();
    }

    /**
     * The company's configured default sales account (invoice settings), used to
     * prefill new lines and to code any line left without an account. Null when unset.
     */
    #[Computed]
    public function configuredSalesAccountId(): ?int
    {
        return $this->company->invoiceSettingsOrNew()->default_sales_account_id;
    }

    /**
     * Fallback income account used to code lines when the Account column is hidden
     * and no default sales account is configured. First active income account by code.
     */
    #[Computed]
    public function defaultIncomeAccountId(): ?int
    {
        return Account::query()
            ->selectableForItemAccount()
            ->where('type', AccountType::Income->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->value('id');
    }

    #[Computed]
    public function classificationOptions()
    {
        return Classification::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function locationOptions()
    {
        return Location::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function tracksClasses(): bool
    {
        return (bool) $this->company->features_classes;
    }

    #[Computed]
    public function tracksLocations(): bool
    {
        return (bool) $this->company->features_locations;
    }

    /**
     * Sales reps are drawn from employee contacts, which only exist when the
     * Employees feature is enabled. With it off there is no way to manage reps,
     * so the field (and its toggle) is hidden rather than left dangling empty.
     */
    #[Computed]
    public function tracksSalesReps(): bool
    {
        return (bool) $this->company->features_employees;
    }

    /**
     * A dimension column renders only when the company tracks it AND the owner
     * hasn't hidden it from the Fields menu.
     */
    #[Computed]
    public function showClassColumn(): bool
    {
        return $this->tracksClasses && ($this->fieldVisibility['class_column'] ?? true);
    }

    #[Computed]
    public function showLocationColumn(): bool
    {
        return $this->tracksLocations && ($this->fieldVisibility['location_column'] ?? true);
    }

    #[Computed]
    public function dimensionColumns(): int
    {
        return (int) $this->showClassColumn + (int) $this->showLocationColumn;
    }

    /**
     * Columns spanned by the totals labels in the footer: every line column
     * except Amount and the remove button. Always-on columns are Description and
     * Unit price; Item, Qty, Disc %, Markup %, Tax, Service date and Account are
     * optional, plus any tracked dimensions.
     */
    #[Computed]
    public function lineLeadingColspan(): int
    {
        // Always-on leading columns: Description, Unit price.
        return 2
            + (int) ($this->fieldVisibility['item_column'] ?? true)
            + (int) ($this->fieldVisibility['qty_column'] ?? true)
            + (int) ($this->fieldVisibility['discount_column'] ?? false)
            + (int) ($this->fieldVisibility['markup_column'] ?? false)
            + (int) ($this->fieldVisibility['tax_column'] ?? false)
            + (int) ($this->fieldVisibility['service_date_column'] ?? false)
            + (int) ($this->fieldVisibility['account_column'] ?? true)
            + $this->dimensionColumns;
    }

    #[Computed]
    public function totals(): array
    {
        $sub = array_sum(array_column($this->lines, 'subtotal'));
        $tax = array_sum(array_column($this->lines, 'tax')) + array_sum(array_column($this->lines, 'secondary_tax'));

        $discount = $this->document_discount_pct !== '' && is_numeric($this->document_discount_pct)
            ? max(0, min((int) round($sub * (float) $this->document_discount_pct / 100), $sub))
            : 0;

        return ['subtotal' => $sub, 'tax' => $tax, 'discount' => $discount, 'total' => $sub + $tax - $discount];
    }

    /**
     * Per-tax-code breakdown of the live line tax, so the footer can show each tax
     * (e.g. GST and PST) on its own row rather than one combined "Tax" total. Mirrors
     * {@see \App\Support\Tax\LineTaxBreakdown} but reads the unsaved component state.
     *
     * @return array<int, array{label: string, rate: float, tax_cents: int}>
     */
    #[Computed]
    public function taxBreakdown(): array
    {
        $codes = $this->taxCodeOptions->keyBy('id');
        $rows = [];

        foreach ($this->lines as $line) {
            foreach ([
                [$line['tax_code_id'] ?? null, (int) ($line['tax'] ?? 0)],
                [$line['secondary_tax_code_id'] ?? null, (int) ($line['secondary_tax'] ?? 0)],
            ] as [$id, $cents]) {
                if (! $id || $cents === 0) {
                    continue;
                }

                $code = $codes[$id] ?? null;
                $rows[$id] ??= [
                    'label' => $code ? (string) $code->name : '',
                    'rate' => $code ? $code->ratePercent() : 0.0,
                    'tax_cents' => 0,
                ];
                $rows[$id]['tax_cents'] += $cents;
            }
        }

        return array_values($rows);
    }

    /**
     * Credit-limit snapshot for the selected customer, or null when no customer
     * is chosen or they have no credit limit set. "projected" adds this invoice's
     * total to the customer's current open balance (excluding any amount this
     * invoice already contributes when editing a posted one).
     *
     * @return array{balance: int, limit: int, projected: int, over: bool}|null
     */
    #[Computed]
    public function creditStatus(): ?array
    {
        if (! $this->contact_id) {
            return null;
        }

        $contact = Contact::find($this->contact_id);

        if (! $contact || $contact->credit_limit_cents === null) {
            return null;
        }

        $alreadyCounted = ($this->invoice && in_array($this->invoice->status, [InvoiceStatus::Posted, InvoiceStatus::Partial, InvoiceStatus::Paid], true))
            ? (int) $this->invoice->total_cents
            : 0;

        $balance = (int) $contact->ar_balance_cents;
        $projected = $balance - $alreadyCounted + (int) $this->totals['total'];
        $limit = (int) $contact->credit_limit_cents;

        return [
            'balance' => $balance,
            'limit' => $limit,
            'projected' => $projected,
            'over' => $projected > $limit,
        ];
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex items-start justify-between gap-4">
        <flux:heading size="xl" level="1">{{ $invoice?->id ? __('Edit invoice') : __('New invoice') }}</flux:heading>

        <flux:dropdown align="end" data-test="invoice-field-settings">
            <flux:button variant="ghost" size="sm" icon="adjustments-horizontal" icon:trailing="chevron-down">{{ __('Fields') }}</flux:button>

            <flux:menu>
                <flux:menu.group :heading="__('Header fields')">
                    <flux:menu.checkbox wire:model.live="fieldVisibility.terms" keep-open>{{ __('Terms') }}</flux:menu.checkbox>
                    @unless ($invoice?->id)
                        <flux:menu.checkbox wire:model.live="fieldVisibility.template" keep-open>{{ __('Template') }}</flux:menu.checkbox>
                    @endunless
                    @if ($this->tracksSalesReps)
                        <flux:menu.checkbox wire:model.live="fieldVisibility.sales_rep" keep-open>{{ __('Sales rep') }}</flux:menu.checkbox>
                    @endif
                    <flux:menu.checkbox wire:model.live="fieldVisibility.customer_po" keep-open>{{ __('Customer PO #') }}</flux:menu.checkbox>
                    <flux:menu.checkbox wire:model.live="fieldVisibility.ship_date" keep-open>{{ __('Ship date') }}</flux:menu.checkbox>
                    <flux:menu.checkbox wire:model.live="fieldVisibility.ship_via" keep-open>{{ __('Ship via') }}</flux:menu.checkbox>
                    <flux:menu.checkbox wire:model.live="fieldVisibility.fob" keep-open>{{ __('FOB') }}</flux:menu.checkbox>
                    <flux:menu.checkbox wire:model.live="fieldVisibility.tracking_no" keep-open>{{ __('Tracking #') }}</flux:menu.checkbox>
                    <flux:menu.checkbox wire:model.live="fieldVisibility.memo" keep-open>{{ __('Memo') }}</flux:menu.checkbox>
                    <flux:menu.checkbox wire:model.live="fieldVisibility.customer_message" keep-open>{{ __('Message displayed on invoice') }}</flux:menu.checkbox>
                </flux:menu.group>

                <flux:menu.separator />

                <flux:menu.group :heading="__('Line columns')">
                    <flux:menu.checkbox wire:model.live="fieldVisibility.item_column" keep-open>{{ __('Item') }}</flux:menu.checkbox>
                    <flux:menu.checkbox wire:model.live="fieldVisibility.qty_column" keep-open>{{ __('Qty') }}</flux:menu.checkbox>
                    <flux:menu.checkbox wire:model.live="fieldVisibility.service_date_column" keep-open>{{ __('Service date') }}</flux:menu.checkbox>
                    <flux:menu.checkbox wire:model.live="fieldVisibility.discount_column" keep-open>{{ __('Discount') }}</flux:menu.checkbox>
                    <flux:menu.checkbox wire:model.live="fieldVisibility.markup_column" keep-open>{{ __('Markup') }}</flux:menu.checkbox>
                    <flux:menu.checkbox wire:model.live="fieldVisibility.tax_column" keep-open>{{ __('Tax') }}</flux:menu.checkbox>
                    <flux:menu.checkbox wire:model.live="fieldVisibility.account_column" keep-open>{{ __('Account') }}</flux:menu.checkbox>
                    @if ($this->tracksClasses)
                        <flux:menu.checkbox wire:model.live="fieldVisibility.class_column" keep-open>{{ __('Class') }}</flux:menu.checkbox>
                    @endif
                    @if ($this->tracksLocations)
                        <flux:menu.checkbox wire:model.live="fieldVisibility.location_column" keep-open>{{ __('Location') }}</flux:menu.checkbox>
                    @endif
                    <flux:menu.checkbox wire:model.live="fieldVisibility.document_discount" keep-open>{{ __('Document discount') }}</flux:menu.checkbox>
                </flux:menu.group>
            </flux:menu>
        </flux:dropdown>
    </div>

    <form wire:submit="postInvoice" class="space-y-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-contact-combo
                :label="__('Customer')"
                :placeholder="__('Search or type to add a new customer…')"
                :add-label="__('customer')"
                :options="$this->customers"
                :selected-id="$contact_id"
                :selected-name="$this->selectedContactName"
                :query="$contact_query"
                :creating="$contact_creating"
                :new-name="$new_contact_name"
                data-test="invoice-customer-combo"
            />

            <flux:input wire:model="invoice_no" :label="__('Invoice #')" required data-test="invoice-no-input" />
            <flux:input type="date" wire:model.live="invoice_date" :label="__('Date')" required data-test="invoice-date-input" />
            <flux:input type="date" wire:model="due_date" :label="__('Due date')" required data-test="invoice-due-date-input" />

            @if ($fieldVisibility['terms'])
                <flux:select wire:model.live="terms_id" :label="__('Terms')">
                    <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                    @foreach ($this->termsOptions as $t)
                        <flux:select.option :value="$t->id">{{ $t->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            @if ($this->formStyleOptions->isNotEmpty())
                <flux:select wire:model="form_style_id" :label="__('Form style')" data-test="invoice-form-style">
                    <flux:select.option value="">{{ __('— Default settings —') }}</flux:select.option>
                    @foreach ($this->formStyleOptions as $style)
                        <flux:select.option :value="$style->id">{{ $style->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            @unless ($invoice?->id)
                @if ($fieldVisibility['template'] && $this->templateOptions->isNotEmpty())
                    <flux:select wire:model.live="template_id" :label="__('Template')" data-test="invoice-template-picker">
                        <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                        @foreach ($this->templateOptions as $tpl)
                            <flux:select.option :value="$tpl->id">{{ $tpl->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            @endunless

            @if ($this->tracksSalesReps && $fieldVisibility['sales_rep'])
                <flux:select wire:model="sales_rep_id" :label="__('Sales rep')" data-test="invoice-sales-rep">
                    <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                    @foreach ($this->salesRepOptions as $rep)
                        <flux:select.option :value="$rep->id">{{ $rep->display_name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            @if ($fieldVisibility['customer_po'])
                <flux:input wire:model="customer_po" :label="__('Customer PO #')" data-test="invoice-customer-po" />
            @endif
            @if ($fieldVisibility['ship_date'])
                <flux:input type="date" wire:model="ship_date" :label="__('Ship date')" data-test="invoice-ship-date" />
            @endif
            @if ($fieldVisibility['ship_via'])
                <flux:input wire:model="ship_via" :label="__('Ship via')" data-test="invoice-ship-via" />
            @endif
            @if ($fieldVisibility['fob'])
                <flux:input wire:model="fob" :label="__('FOB')" data-test="invoice-fob" />
            @endif
            @if ($fieldVisibility['tracking_no'])
                <flux:input wire:model="tracking_no" :label="__('Tracking #')" data-test="invoice-tracking-no" />
            @endif
        </div>

        @if ($this->creditStatus)
            @php($credit = $this->creditStatus)
            <div
                @class([
                    'rounded-lg border p-4',
                    'border-amber-300 bg-amber-50 dark:border-amber-700/60 dark:bg-amber-950/40' => $credit['over'],
                    'border-border bg-muted' => ! $credit['over'],
                ])
                data-test="invoice-credit-status"
            >
                <div class="flex items-start gap-3">
                    <flux:icon :name="$credit['over'] ? 'exclamation-triangle' : 'credit-card'" @class(['mt-0.5 size-5 shrink-0', 'text-amber-600 dark:text-amber-400' => $credit['over'], 'text-muted-foreground' => ! $credit['over']]) />
                    <div class="flex-1">
                        @if ($credit['over'])
                            <flux:text class="font-medium text-amber-800 dark:text-amber-200" data-test="invoice-credit-over">
                                {{ __('This invoice puts the customer over their credit limit.') }}
                            </flux:text>
                        @else
                            <flux:text class="font-medium">{{ __('Customer credit') }}</flux:text>
                        @endif
                        <div class="mt-2 grid grid-cols-3 gap-3 text-sm">
                            <div>
                                <div class="text-muted-foreground">{{ __('Amount owing') }}</div>
                                <div class="font-mono" data-test="invoice-credit-balance">{{ number_format($credit['balance'] / 100, 2) }}</div>
                            </div>
                            <div>
                                <div class="text-muted-foreground">{{ __('Credit limit') }}</div>
                                <div class="font-mono" data-test="invoice-credit-limit">{{ number_format($credit['limit'] / 100, 2) }}</div>
                            </div>
                            <div>
                                <div class="text-muted-foreground">{{ __('With this invoice') }}</div>
                                <div @class(['font-mono', 'font-semibold text-amber-700 dark:text-amber-300' => $credit['over']]) data-test="invoice-credit-projected">{{ number_format($credit['projected'] / 100, 2) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if ($fieldVisibility['memo'])
            <flux:input wire:model="memo" :label="__('Memo')" />
        @endif
        @if ($fieldVisibility['customer_message'])
            <flux:textarea wire:model="customer_message" :label="__('Message displayed on invoice')" rows="2" data-test="invoice-customer-message" />
        @endif

        <div class="overflow-x-auto rounded-lg border border-border">
            <table
                class="w-full text-sm"
                x-on:keydown.tab.capture="tabAddRow($event)"
                x-data="{
                    addRowAndFocus(next) {
                        this.$wire.addLine().then(() => {
                            this.$nextTick(() => this.$root.querySelector('[data-item-input=&quot;' + next + '&quot;], [data-line-first=&quot;' + next + '&quot;]')?.focus());
                        });
                    },
                    tabAddRow(event) {
                        if (event.shiftKey) return;
                        const row = event.target.closest('tr');
                        if (! row || ! row.parentElement) return;
                        const fields = [...row.querySelectorAll('input:not([type=hidden]), select, textarea, [data-test=&quot;line-tax&quot;]')].filter((el) => ! el.disabled && el.offsetParent !== null);
                        if (! fields.length || event.target !== fields[fields.length - 1]) return;
                        const rows = [...row.parentElement.querySelectorAll(':scope > tr')];
                        if (row !== rows[rows.length - 1]) return;
                        event.preventDefault();
                        this.addRowAndFocus(rows.length);
                    },
                }"
            >
                <thead class="hidden bg-muted lg:table-header-group">
                    <tr>
                        @if ($fieldVisibility['item_column'])
                            <th class="px-2 py-2 text-left w-44">{{ __('Item') }}</th>
                        @endif
                        <th class="px-2 py-2 text-left">{{ __('Description') }}</th>
                        @if ($fieldVisibility['service_date_column'])
                            <th class="px-2 py-2 text-left w-36">{{ __('Service date') }}</th>
                        @endif
                        @if ($fieldVisibility['account_column'])
                            <th class="px-2 py-2 text-left w-44">{{ __('Account') }}</th>
                        @endif
                        @if ($fieldVisibility['qty_column'])
                            <th class="px-2 py-2 text-right w-20">{{ __('Qty') }}</th>
                        @endif
                        <th class="px-2 py-2 text-right w-28">{{ __('Unit price') }}</th>
                        @if ($fieldVisibility['discount_column'])
                            <th class="px-2 py-2 text-right w-20">{{ __('Disc %') }}</th>
                        @endif
                        @if ($fieldVisibility['markup_column'])
                            <th class="px-2 py-2 text-right w-20">{{ __('Markup %') }}</th>
                        @endif
                        @if ($fieldVisibility['tax_column'])
                            <th class="px-2 py-2 text-left w-32">{{ __('Tax') }}</th>
                        @endif
                        @if ($this->showClassColumn)
                            <th class="px-2 py-2 text-left w-32">{{ __('Class') }}</th>
                        @endif
                        @if ($this->showLocationColumn)
                            <th class="px-2 py-2 text-left w-32">{{ __('Location') }}</th>
                        @endif
                        <th class="px-2 py-2 text-right w-28">{{ __('Amount') }}</th>
                        <th class="px-2 py-2 w-10"></th>
                    </tr>
                </thead>
                <tbody class="lg:divide-y lg:divide-border">
                    @foreach ($lines as $i => $line)
                        <tr wire:key="line-{{ $i }}" data-test="invoice-line-row" class="block border-b border-border p-3 lg:table-row lg:border-0 lg:p-0">
                            @if ($fieldVisibility['item_column'])
                                <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                    <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Item') }}</span>
                                    <x-line-item-combo :index="$i" :items="$this->itemOptions" />
                                </td>
                            @endif
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Description') }}</span>
                                <flux:textarea wire:model="lines.{{ $i }}.description" rows="1" :placeholder="__('Description — new lines and -, *, • bullets render on the invoice')" />
                            </td>
                            @if ($fieldVisibility['service_date_column'])
                                <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                    <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Service date') }}</span>
                                    <flux:input type="date" wire:model="lines.{{ $i }}.service_date" data-test="line-service-date" />
                                </td>
                            @endif
                            @if ($fieldVisibility['account_column'])
                                <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                    <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Account') }}</span>
                                    <flux:select wire:model.live="lines.{{ $i }}.account_id" data-test="line-account">
                                        <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                        @foreach ($this->accountOptions as $opt)
                                            <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    @error('lines.'.$i.'.account_id') <flux:text class="mt-1 text-xs text-red-600">{{ __('Account is required.') }}</flux:text> @enderror
                                </td>
                            @endif
                            @if ($fieldVisibility['qty_column'])
                                <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                    <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Qty') }}</span>
                                    <flux:input wire:model.live="lines.{{ $i }}.quantity" class="lg:text-right" data-test="line-qty" />
                                </td>
                            @endif
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Unit price') }}</span>
                                <x-amount-input model="lines.{{ $i }}.unit_price" class="lg:text-right" data-test="line-unit-price" />
                            </td>
                            @if ($fieldVisibility['discount_column'])
                                <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                    <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Disc %') }}</span>
                                    <flux:input wire:model.live="lines.{{ $i }}.discount_pct" class="lg:text-right" placeholder="0" data-test="line-discount-pct" />
                                </td>
                            @endif
                            @if ($fieldVisibility['markup_column'])
                                <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                    <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Markup %') }}</span>
                                    <flux:input wire:model.live="lines.{{ $i }}.markup_pct" class="lg:text-right" placeholder="0" data-test="line-markup-pct" />
                                </td>
                            @endif
                            @if ($fieldVisibility['tax_column'])
                                @php($selectedTaxIds = $line['tax_code_ids'] ?? [])
                                <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                    <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Tax') }}</span>
                                    <flux:dropdown>
                                        <flux:button
                                            variant="outline"
                                            size="sm"
                                            icon:trailing="chevron-down"
                                            class="w-full justify-between font-normal"
                                            data-test="line-tax"
                                        >
                                            <span class="truncate">{{ $this->taxCodeOptions->whereIn('id', $selectedTaxIds)->pluck('code')->implode(', ') ?: __('Select tax') }}</span>
                                        </flux:button>
                                        <flux:menu>
                                            <flux:menu.checkbox.group wire:model.live="lines.{{ $i }}.tax_code_ids">
                                                @foreach ($this->taxCodeOptions as $opt)
                                                    <flux:menu.checkbox value="{{ $opt->id }}" :disabled="count($selectedTaxIds) === 2 && ! in_array($opt->id, $selectedTaxIds)" keep-open>{{ $opt->code }}</flux:menu.checkbox>
                                                @endforeach
                                            </flux:menu.checkbox.group>
                                        </flux:menu>
                                    </flux:dropdown>
                                </td>
                            @endif
                            @if ($this->showClassColumn)
                                <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                    <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Class') }}</span>
                                    <flux:select wire:model="lines.{{ $i }}.class_id" data-test="line-class">
                                        <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                        @foreach ($this->classificationOptions as $opt)
                                            <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </td>
                            @endif
                            @if ($this->showLocationColumn)
                                <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                    <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Location') }}</span>
                                    <flux:select wire:model="lines.{{ $i }}.location_id" data-test="line-location">
                                        <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                        @foreach ($this->locationOptions as $opt)
                                            <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </td>
                            @endif
                            <td class="flex items-center justify-between px-2 py-1 font-mono lg:table-cell lg:py-2 lg:text-right" data-test="line-total">
                                <span class="text-xs font-medium text-muted-foreground lg:hidden">{{ __('Amount') }}</span>
                                <span>{{ number_format($line['total'] / 100, 2) }}</span>
                            </td>
                            <td class="block px-2 pt-2 text-right lg:table-cell lg:p-2">
                                <flux:button variant="ghost" size="sm" icon="x-mark" type="button" tabindex="-1" wire:click="removeLine({{ $i }})">
                                    <span class="lg:hidden">{{ __('Remove line') }}</span>
                                </flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="hidden bg-muted lg:table-footer-group">
                    <tr>
                        <td colspan="{{ $this->lineLeadingColspan }}" class="px-2 py-2 text-right font-medium">{{ __('Subtotal') }}</td>
                        <td class="px-2 py-2 text-right font-mono" data-test="invoice-subtotal">{{ number_format($this->totals['subtotal'] / 100, 2) }}</td>
                        <td></td>
                    </tr>
                    @forelse ($this->taxBreakdown as $taxRow)
                        <tr data-test="invoice-tax-row">
                            <td colspan="{{ $this->lineLeadingColspan }}" class="px-2 py-2 text-right font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</td>
                            <td class="px-2 py-2 text-right font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</td>
                            <td></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $this->lineLeadingColspan }}" class="px-2 py-2 text-right font-medium">{{ __('Tax') }}</td>
                            <td class="px-2 py-2 text-right font-mono" data-test="invoice-tax">{{ number_format($this->totals['tax'] / 100, 2) }}</td>
                            <td></td>
                        </tr>
                    @endforelse
                    @if ($fieldVisibility['document_discount'])
                        <tr>
                            <td colspan="{{ $this->lineLeadingColspan }}" class="px-2 py-2 text-right font-medium">
                                <span class="inline-flex items-center justify-end gap-2">
                                    {{ __('Document discount') }}
                                    <flux:input wire:model.live="document_discount_pct" class="w-20 text-right" placeholder="0" data-test="invoice-document-discount-pct" />
                                    <span>%</span>
                                </span>
                            </td>
                            <td class="px-2 py-2 text-right font-mono" data-test="invoice-document-discount">@if ($this->totals['discount'] > 0)({{ number_format($this->totals['discount'] / 100, 2) }})@else{{ number_format(0, 2) }}@endif</td>
                            <td></td>
                        </tr>
                    @endif
                    <tr class="text-base">
                        <td colspan="{{ $this->lineLeadingColspan }}" class="px-2 py-2 text-right font-semibold">{{ __('Total') }}</td>
                        <td class="px-2 py-2 text-right font-mono font-semibold" data-test="invoice-total">{{ number_format($this->totals['total'] / 100, 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

            {{-- Mobile totals (tfoot is desktop-only) --}}
            <div class="space-y-1 border-t border-border bg-muted px-3 py-3 text-sm lg:hidden">
                <div class="flex justify-between"><span class="font-medium">{{ __('Subtotal') }}</span><span class="font-mono">{{ number_format($this->totals['subtotal'] / 100, 2) }}</span></div>
                @forelse ($this->taxBreakdown as $taxRow)
                    <div class="flex justify-between"><span class="font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</span><span class="font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</span></div>
                @empty
                    <div class="flex justify-between"><span class="font-medium">{{ __('Tax') }}</span><span class="font-mono">{{ number_format($this->totals['tax'] / 100, 2) }}</span></div>
                @endforelse
                <div class="flex items-center justify-between gap-2">
                    <span class="font-medium">{{ __('Document discount %') }}</span>
                    <flux:input wire:model.live="document_discount_pct" class="w-24 text-right" placeholder="0" />
                </div>
                <div class="flex justify-between text-base"><span class="font-semibold">{{ __('Total') }}</span><span class="font-mono font-semibold">{{ number_format($this->totals['total'] / 100, 2) }}</span></div>
            </div>
        </div>

        @error('lines') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror

        <div class="flex items-center justify-between">
            <flux:button variant="filled" type="button" icon="plus" wire:click="addLine">{{ __('Add line') }}</flux:button>

            <div class="flex gap-2">
                <flux:button variant="ghost" type="button" icon="document-duplicate" x-on:click="$flux.modal('save-as-template').show()" data-test="save-as-template-button">{{ __('Save as template') }}</flux:button>
                @if ($invoice?->journal_entry_id)
                    <flux:button variant="primary" type="submit" data-test="post-invoice-button">{{ __('Save changes') }}</flux:button>
                @else
                    <flux:button variant="filled" type="button" wire:click="saveDraft" data-test="save-draft-button">{{ __('Save draft') }}</flux:button>
                    <flux:button variant="primary" type="submit" data-test="post-invoice-button">{{ __('Post invoice') }}</flux:button>
                @endif
            </div>
        </div>
    </form>

    <flux:modal name="save-as-template" class="max-w-md">
        <form wire:submit="saveAsTemplate" class="space-y-6">
            <flux:heading size="lg">{{ __('Save as template') }}</flux:heading>
            <flux:text>{{ __('Save the current line items as a reusable template for future invoices.') }}</flux:text>

            <flux:input wire:model="template_name" :label="__('Template name')" placeholder="{{ __('e.g. Standard service package') }}" required data-test="save-as-template-name" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="save-as-template-confirm">{{ __('Save template') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
