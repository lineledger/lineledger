<?php

use App\Actions\Documents\MatchInboxItemToStatementLine;
use App\Actions\Documents\PromoteInboxItem;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Contact;
use App\Models\InboxItem;
use App\Rules\MoneyString;
use App\Services\Classification\CategorySuggester;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Review document')] class extends Component {
    public Company $company;

    public InboxItem $item;

    /** bill | expense | reimbursement | bank */
    public string $documentType = 'bill';

    public string $vendor = '';

    public string $date = '';

    public ?int $contactId = null;

    public ?int $paymentAccountId = null;

    public ?int $bankLineId = null;

    /**
     * @var array<int, array{account_id: ?int, description: string, amount: string, tax_code_id: ?int, secondary_tax_code_id: ?int, tax_code_ids: array<int, int>, tax_override: string, auto_tax_cents: int, tax_cents: int, secondary_tax_cents: int, total: int}>
     */
    public array $lines = [];

    /** The per-company receipt-scanning toggle (Settings → Inbox email). */
    public bool $companyOcrEnabled = false;

    /** The operator gate: master switch + driver + a resolvable Anthropic key. */
    public bool $operatorOcrEnabled = false;

    /** Whether automatic receipt scanning is fully switched on (both gates). */
    public bool $ocrAvailable = false;

    public function mount(Company $company, InboxItem $item): void
    {
        $this->company = $company;
        $this->item = $item->load('attachment', 'suggestedContact');

        $extracted = (array) ($item->extracted ?? []);

        $this->vendor = (string) ($extracted['vendor'] ?? '');
        $this->date = (string) ($extracted['date'] ?? $company->currentDateTime()->toDateString());
        $this->contactId = $item->suggested_contact_id;
        $this->documentType = $item->suggested_document_type ?? 'bill';

        $this->lines = [$this->lineFromExtraction($extracted)];
        foreach (array_keys($this->lines) as $i) {
            $this->recalcLine($i);
        }

        // OCR is doubly opt-in. Track each half separately so the hint below can
        // name the gate that is actually off (mirrors InboxServiceProvider).
        $this->companyOcrEnabled = $company->inboxOcrEnabled();
        $this->operatorOcrEnabled = (bool) config('inbox.ai.enabled')
            && config('inbox.ai.driver') !== 'null'
            && filled(config('services.anthropic.key'));
        $this->ocrAvailable = $this->companyOcrEnabled && $this->operatorOcrEnabled;
    }

    /**
     * Build the opening grid line from OCR output. The grand total minus the
     * recoverable tax (GST/HST, mapped by the OCR layer) becomes the pre-tax line
     * amount, with that tax broken out exactly as an ITC; any non-recoverable or
     * unrecognised charge stays folded into the base so the total reconciles.
     *
     * @param  array<string, mixed>  $extracted
     * @return array<string, mixed>
     */
    protected function lineFromExtraction(array $extracted): array
    {
        $line = $this->emptyLine();
        $line['account_id'] = $this->defaultAccountId();
        $line['description'] = (string) ($extracted['vendor'] ?? '');

        $total = (int) ($extracted['amount_cents'] ?? 0);

        // First extracted tax the OCR mapper tied to a (recoverable) tax code.
        $primaryTax = null;
        foreach ((array) ($extracted['taxes'] ?? []) as $t) {
            if (is_array($t) && ($t['tax_code_id'] ?? null) !== null) {
                $primaryTax = $t;
                break;
            }
        }

        $taxCents = $primaryTax !== null ? (int) ($primaryTax['amount_cents'] ?? 0) : 0;
        $baseCents = $total > 0 ? max(0, $total - $taxCents) : 0;

        $line['amount'] = Money::fromCents($baseCents)->toDecimalString();

        if ($primaryTax !== null) {
            $codeId = (int) $primaryTax['tax_code_id'];
            $line['tax_code_id'] = $codeId;
            $line['tax_code_ids'] = [$codeId];
            $line['tax_override'] = Money::fromCents($taxCents)->toDecimalString();
        }

        return $line;
    }

    /**
     * The opening category for the line. Prefer the suggestion resolved when the
     * document was processed (vendor history, or an AI guess), then a live
     * deterministic history lookup (no AI on the request cycle), then the first
     * expense account as a last resort.
     */
    protected function defaultAccountId(): ?int
    {
        $extracted = (array) ($this->item->extracted ?? []);
        $stored = (int) ($extracted['suggested_account_id'] ?? 0);

        if ($stored > 0 && $this->isSelectableAccount($stored)) {
            return $stored;
        }

        $suggestion = app(CategorySuggester::class)->suggest(
            $this->company->id,
            $this->contactId,
            $this->vendor !== '' ? $this->vendor : null,
        );

        if ($suggestion !== null && $this->isSelectableAccount($suggestion->accountId)) {
            return $suggestion->accountId;
        }

        return Account::query()
            ->selectableForItemAccount()
            ->where('type', AccountType::Expense->value)
            ->orderBy('code')
            ->value('id');
    }

    protected function isSelectableAccount(int $accountId): bool
    {
        return Account::query()
            ->selectableForItemAccount()
            ->where('is_active', true)
            ->whereKey($accountId)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyLine(): array
    {
        return [
            'account_id' => null, 'description' => '', 'amount' => '0.00',
            'tax_code_id' => null, 'secondary_tax_code_id' => null, 'tax_code_ids' => [],
            'tax_override' => '', 'auto_tax_cents' => 0, 'tax_cents' => 0, 'secondary_tax_cents' => 0, 'total' => 0,
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

    public function updatedDocumentType(): void
    {
        unset($this->contactOptions);

        if ($this->documentType !== 'bank') {
            $this->bankLineId = null;
        }

        // A vendor selected for a bill is not a valid employee for a reimbursement.
        if ($this->documentType === 'reimbursement' && $this->contactId !== null) {
            $stillValid = Contact::query()->whereKey($this->contactId)->where('is_employee', true)->exists();

            if (! $stillValid) {
                $this->contactId = null;
            }
        }
    }

    public function updatedLines(mixed $value, ?string $key = null): void
    {
        // Livewire passes a null key when the whole `lines` array is replaced.
        if ($key === null) {
            return;
        }

        $i = (int) explode('.', $key)[0];

        // The tax picker is a multi-select bound to tax_code_ids; fan the (max two)
        // chosen codes back out to the primary/secondary columns.
        if (str_ends_with($key, '.tax_code_ids')) {
            $ids = array_slice(array_values(array_unique(array_filter(
                array_map('intval', (array) $this->lines[$i]['tax_code_ids'])
            ))), 0, 2);
            $this->lines[$i]['tax_code_id'] = $ids[0] ?? null;
            $this->lines[$i]['secondary_tax_code_id'] = $ids[1] ?? null;
        }

        $this->recalcLine($i);
    }

    protected function recalcLine(int $i): void
    {
        $line = $this->lines[$i];

        try {
            $amount = Money::fromString($line['amount'] === '' ? '0' : $line['amount'])->cents;
        } catch (Throwable) {
            $amount = 0;
        }

        $taxCode = $line['tax_code_id'] ? \App\Models\TaxCode::find($line['tax_code_id']) : null;
        $autoTax = $taxCode ? $taxCode->taxFor($amount) : 0;

        $secondaryTaxCode = ($line['secondary_tax_code_id'] ?? null) ? \App\Models\TaxCode::find($line['secondary_tax_code_id']) : null;
        $secondaryTax = $secondaryTaxCode ? $secondaryTaxCode->taxFor($amount) : 0;

        $this->lines[$i]['tax_code_ids'] = array_values(array_filter([$line['tax_code_id'], $line['secondary_tax_code_id'] ?? null]));

        $override = trim((string) ($line['tax_override'] ?? ''));
        $taxCents = $override === '' ? $autoTax : (Money::tryFromString($override)?->cents ?? $autoTax);

        $this->lines[$i]['auto_tax_cents'] = $autoTax;
        $this->lines[$i]['tax_cents'] = $taxCents;
        $this->lines[$i]['secondary_tax_cents'] = $secondaryTax;
        $this->lines[$i]['total'] = $amount + $taxCents + $secondaryTax;
    }

    public function promote(PromoteInboxItem $action, MatchInboxItemToStatementLine $matcher): void
    {
        $companyId = $this->company->id;

        $validated = $this->validate([
            'documentType' => ['required', 'in:bill,expense,reimbursement,bank'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'date' => [Rule::requiredIf($this->documentType !== 'bank'), 'nullable', 'date'],
            'contactId' => [Rule::requiredIf(in_array($this->documentType, ['bill', 'reimbursement'], true)), 'nullable', 'integer', Rule::exists('contacts', 'id')->where('company_id', $companyId)],
            'paymentAccountId' => [Rule::requiredIf($this->documentType === 'expense'), 'nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'bankLineId' => [Rule::requiredIf($this->documentType === 'bank'), 'nullable', 'integer', Rule::exists('bank_statement_lines', 'id')->where('company_id', $companyId)],
            'lines' => ['array', 'min:1'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'lines.*.amount' => ['required', 'string', new MoneyString],
            'lines.*.tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('company_id', $companyId)],
            'lines.*.secondary_tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('company_id', $companyId)],
            'lines.*.tax_override' => ['nullable', 'string', new MoneyString],
        ]);

        $overrides = [
            'document_type' => $this->documentType,
            'vendor' => $this->vendor !== '' ? $this->vendor : null,
            'date' => $this->date !== '' ? $this->date : null,
            'contact_id' => $this->contactId,
            'payment_account_id' => $this->paymentAccountId,
            'lines' => array_map(fn (array $l): array => [
                'account_id' => (int) $l['account_id'],
                'description' => $l['description'] ?? '',
                'amount_cents' => Money::fromString($l['amount'])->cents,
                'tax_code_id' => $l['tax_code_id'] ?? null,
                'secondary_tax_code_id' => $l['secondary_tax_code_id'] ?? null,
                'tax_override_cents' => ($l['tax_override'] ?? '') !== ''
                    ? Money::fromString($l['tax_override'])->cents
                    : null,
            ], $validated['lines']),
        ];

        // Match to an imported bank transaction: post the receipt as an expense
        // against that line's account so the GST splits out and the line clears,
        // all in one entry (no double-post).
        if ($this->documentType === 'bank') {
            $line = BankStatementLine::findOrFail($this->bankLineId);

            if ($this->totalCents() !== abs((int) $line->amount_cents)) {
                $this->addError('lines', __('The line total must equal the bank transaction amount (:amount).', [
                    'amount' => Money::fromCents(abs((int) $line->amount_cents))->format(),
                ]));

                return;
            }

            try {
                $expense = $matcher->handle($this->item, $line, $overrides);
            } catch (\RuntimeException $e) {
                Flux::toast(variant: 'danger', text: $e->getMessage());

                return;
            }

            Flux::toast(variant: 'success', text: __('Recorded against the bank transaction.'));
            $this->redirectRoute('expenses.show', ['company' => $this->company->slug, 'expense' => $expense->getKey()], navigate: true);

            return;
        }

        try {
            $document = $action->handle($this->item, $overrides);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Draft created. Review and post it when you are ready.'));

        [$route, $param] = match ($this->documentType) {
            'expense' => ['expenses.show', 'expense'],
            'reimbursement' => ['reimbursements.show', 'bill'],
            default => ['bills.show', 'bill'],
        };

        $this->redirectRoute($route, ['company' => $this->company->slug, $param => $document->getKey()], navigate: true);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Contact>
     */
    #[Computed]
    public function contactOptions()
    {
        $query = Contact::query()->where('is_active', true);

        // Reimbursements are owed to a person, not a supplier.
        if ($this->documentType === 'reimbursement') {
            $query->where('is_employee', true);
        }

        return $query->orderBy('display_name')->get(['id', 'display_name']);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Account>
     */
    #[Computed]
    public function accountOptions()
    {
        $lineAccountIds = collect($this->lines)->pluck('account_id')->filter()->all();

        return Account::query()
            ->where(function ($q) use ($lineAccountIds) {
                $q->whereIn('id', Account::query()->selectableForItemAccount()->select('id'));

                if ($lineAccountIds !== []) {
                    $q->orWhereIn('id', $lineAccountIds);
                }
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Account>
     */
    #[Computed]
    public function paymentAccountOptions()
    {
        return Account::query()
            ->whereIn('subtype', ['bank', 'credit_card', 'current_asset'])
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    /**
     * Unmatched outflows (money out) from imported statements that this receipt
     * could clear. The exact-amount matches for the OCR'd total sort first.
     *
     * @return \Illuminate\Support\Collection<int, BankStatementLine>
     */
    #[Computed]
    public function bankLineOptions()
    {
        $total = (int) data_get($this->item->extracted, 'amount_cents', 0);

        return BankStatementLine::query()
            ->forReview()
            ->where('amount_cents', '<', 0)
            ->with('account:id,code,name')
            ->orderByRaw('CASE WHEN amount_cents = ? THEN 0 ELSE 1 END', [-$total])
            ->orderByDesc('txn_date')
            ->limit(50)
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\TaxCode>
     */
    #[Computed]
    public function taxCodeOptions()
    {
        $lineTaxCodeIds = collect($this->lines)
            ->flatMap(fn ($l) => [$l['tax_code_id'] ?? null, $l['secondary_tax_code_id'] ?? null])
            ->filter()->all();

        return \App\Models\TaxCode::query()
            ->where(function ($q) use ($lineTaxCodeIds) {
                $q->where(fn ($inner) => $inner->where('is_active', true)->forPurchases());

                if ($lineTaxCodeIds !== []) {
                    $q->orWhereIn('id', $lineTaxCodeIds);
                }
            })
            ->orderBy('code')
            ->get();
    }

    public function totalCents(): int
    {
        return array_sum(array_column($this->lines, 'total'));
    }

    /**
     * Per-tax-code breakdown so the totals can show each tax (e.g. GST and PST)
     * on its own row. Mirrors the expense form's taxBreakdown over unsaved state.
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
                [$line['tax_code_id'] ?? null, (int) ($line['tax_cents'] ?? 0)],
                [$line['secondary_tax_code_id'] ?? null, (int) ($line['secondary_tax_cents'] ?? 0)],
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
}; ?>

<section class="w-full">
    <div class="mb-6">
        <flux:button variant="ghost" size="sm" icon="arrow-left"
            :href="route('inbox.index', ['company' => $company->slug])" wire:navigate>
            {{ __('Back to inbox') }}
        </flux:button>
        <flux:heading size="xl" level="1" class="mt-2">{{ __('Review document') }}</flux:heading>
        <flux:subheading>{{ __('Check the details we read, split out any tax, then create a draft to post.') }}</flux:subheading>
    </div>

    @if ($item->ocr_error)
        <flux:callout variant="warning" class="mb-6" icon="exclamation-triangle">
            {{ __('We could not read this document automatically. Enter the details by hand.') }}
        </flux:callout>
    @elseif (! $ocrAvailable && empty($item->extracted))
        <flux:callout variant="secondary" class="mb-6" icon="sparkles">
            <flux:callout.heading>{{ __('Auto-fill is off') }}</flux:callout.heading>
            <flux:callout.text>
                @if (! $companyOcrEnabled)
                    {{ __('Receipt scanning is turned off for this organization, so the fields below were not filled in for you. Enter the details by hand, or turn scanning on to have new uploads read automatically.') }}
                    <flux:link :href="route('inbox-email.edit')" wire:navigate>{{ __('Open inbox settings') }}</flux:link>
                @else
                    {{ __('Receipt scanning is on for this organization, but it is not available on this server yet, so the fields below were not filled in. Enter the details by hand for now. (An administrator needs to enable the scanning service.)') }}
                @endif
            </flux:callout.text>
        </flux:callout>
    @endif

    <form wire:submit="promote" class="space-y-6">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- Document preview --}}
            <div class="rounded-lg border border-border p-4">
                <flux:heading size="lg">{{ $item->original_filename ?? __('Document') }}</flux:heading>
                @if ($item->attachment)
                    @php($downloadUrl = route('attachments.download', ['company' => $company->slug, 'attachment' => $item->attachment->id]))
                    @if ($item->attachment->isImage())
                        {{-- Images render inline; the app sends X-Frame-Options: DENY, so a
                             framed preview would be blank. Click opens the full file in a tab. --}}
                        <a href="{{ $downloadUrl.'?inline=1' }}" target="_blank" rel="noopener" class="mt-3 block">
                            <img src="{{ $downloadUrl.'?inline=1' }}" alt="{{ $item->original_filename }}"
                                class="max-h-[32rem] w-full rounded border border-border bg-muted object-contain" />
                        </a>
                    @elseif ($item->attachment->isInlineViewable())
                        {{-- PDFs and other inline types cannot be framed under X-Frame-Options:
                             DENY, so open them as a top-level navigation in a new tab. --}}
                        <flux:button class="mt-3" :href="$downloadUrl.'?inline=1'" target="_blank"
                            icon="arrow-top-right-on-square">
                            {{ __('Open document') }}
                        </flux:button>
                    @else
                        <flux:button class="mt-3" :href="$downloadUrl" icon="arrow-down-tray">
                            {{ __('Download file') }}
                        </flux:button>
                    @endif
                @endif
            </div>

            {{-- Key fields --}}
            <div class="space-y-4 rounded-lg border border-border p-4">
                <flux:select wire:model.live="documentType" :label="__('Create as')" data-test="inbox-document-type">
                    <flux:select.option value="bill">{{ __('Vendor bill') }}</flux:select.option>
                    <flux:select.option value="expense">{{ __('Expense (paid now)') }}</flux:select.option>
                    <flux:select.option value="reimbursement">{{ __('Employee reimbursement') }}</flux:select.option>
                    <flux:select.option value="bank">{{ __('Match a bank transaction') }}</flux:select.option>
                </flux:select>

                <flux:input wire:model="vendor" :label="__('Vendor / payee')" data-test="inbox-vendor" />

                @if ($documentType !== 'bank')
                    <flux:select wire:model="contactId"
                        :label="$documentType === 'reimbursement' ? __('Employee / owner') : __('Contact')"
                        :required="in_array($documentType, ['bill', 'reimbursement'], true)"
                        data-test="inbox-contact">
                        <flux:select.option value="">
                            {{ $documentType === 'reimbursement' ? __('Select an employee') : __('Select a contact') }}
                        </flux:select.option>
                        @foreach ($this->contactOptions as $contact)
                            <flux:select.option :value="$contact->id">{{ $contact->display_name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    @if ($documentType === 'reimbursement' && $this->contactOptions->isEmpty())
                        <flux:text class="text-sm text-muted-foreground">
                            {{ __('No employees yet.') }}
                            <flux:link :href="route('employees.index', ['company' => $company->slug])" wire:navigate>{{ __('Add an employee') }}</flux:link>
                            {{ __('(a contact marked as an employee) to reimburse them.') }}
                        </flux:text>
                    @endif

                    <flux:input type="date" wire:model="date" :label="__('Date')" required data-test="inbox-date" />
                @endif

                @if ($documentType === 'expense')
                    <flux:select wire:model="paymentAccountId" :label="__('Paid from')" required data-test="inbox-payment-account">
                        <flux:select.option value="">{{ __('Select an account') }}</flux:select.option>
                        @foreach ($this->paymentAccountOptions as $account)
                            <flux:select.option :value="$account->id">{{ $account->code }} — {{ $account->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                @if ($documentType === 'bank')
                    <flux:select wire:model="bankLineId" :label="__('Bank transaction')" required data-test="inbox-bank-line">
                        <flux:select.option value="">{{ __('Select a transaction') }}</flux:select.option>
                        @foreach ($this->bankLineOptions as $bankLine)
                            <flux:select.option :value="$bankLine->id">
                                {{ $bankLine->txn_date->toDateString() }} ·
                                {{ \App\Support\Money::fromCents(abs((int) $bankLine->amount_cents))->format() }} ·
                                {{ \Illuminate\Support\Str::limit($bankLine->description, 40) }}
                                @if ($bankLine->account)({{ $bankLine->account->name }})@endif
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    @if ($this->bankLineOptions->isEmpty())
                        <flux:text class="text-sm text-muted-foreground">
                            {{ __('No unmatched bank transactions to match against.') }}
                            <flux:link :href="route('banking.import', ['company' => $company->slug])" wire:navigate>{{ __('Import a statement') }}</flux:link>
                            {{ __('first.') }}
                        </flux:text>
                    @else
                        <flux:text class="text-xs text-muted-foreground">{{ __('The line total below must equal the selected transaction amount. GST is broken out as an input tax credit.') }}</flux:text>
                    @endif
                @endif
            </div>
        </div>

        @php($categoryHint = data_get($item->extracted, 'suggested_account_reason'))
        @if ($categoryHint)
            <flux:text class="flex items-center gap-1.5 text-sm text-muted-foreground">
                <flux:icon.sparkles class="size-4" />
                {{ __('Category suggestion:') }} {{ $categoryHint }}
            </flux:text>
        @endif

        {{-- Line + tax grid --}}
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="hidden bg-muted lg:table-header-group">
                    <tr>
                        <th class="w-56 px-2 py-2 text-left">{{ __('Category account') }}</th>
                        <th class="px-2 py-2 text-left">{{ __('Description') }}</th>
                        <th class="w-28 px-2 py-2 text-right">{{ __('Amount') }}</th>
                        <th class="w-36 px-2 py-2 text-left">{{ __('Tax') }}</th>
                        <th class="w-28 px-2 py-2 text-right">{{ __('Total') }}</th>
                        <th class="w-10 px-2 py-2"></th>
                    </tr>
                </thead>
                <tbody class="lg:divide-y lg:divide-border">
                    @foreach ($lines as $i => $line)
                        <tr wire:key="inbox-line-{{ $i }}" data-test="inbox-line-row" class="block border-b border-border p-3 lg:table-row lg:border-0 lg:p-0">
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Category account') }}</span>
                                <flux:select wire:model.live="lines.{{ $i }}.account_id" data-test="inbox-line-account">
                                    <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                    @foreach ($this->accountOptions as $opt)
                                        <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Description') }}</span>
                                <flux:input wire:model="lines.{{ $i }}.description" />
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Amount (pre-tax)') }}</span>
                                <x-amount-input model="lines.{{ $i }}.amount" class="lg:text-right" data-test="inbox-line-amount" />
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Tax') }}</span>
                                @php($selectedTaxIds = $line['tax_code_ids'] ?? [])
                                <flux:dropdown>
                                    <flux:button variant="outline" size="sm" icon:trailing="chevron-down" class="w-full justify-between font-normal" data-test="inbox-line-tax">
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
                                <x-amount-input model="lines.{{ $i }}.tax_override" modifiers=".live.debounce.500ms" size="sm"
                                    class="mt-1 lg:text-right"
                                    placeholder="{{ number_format($line['auto_tax_cents'] / 100, 2) }}"
                                    data-test="inbox-line-tax-override" />
                            </td>
                            <td class="flex items-center justify-between px-2 py-1 font-mono lg:table-cell lg:py-2 lg:text-right" data-test="inbox-line-total">
                                <span class="text-xs font-medium text-muted-foreground lg:hidden">{{ __('Total') }}</span>
                                <span>{{ number_format($line['total'] / 100, 2) }}</span>
                            </td>
                            <td class="block px-2 pt-2 text-right lg:table-cell lg:p-2">
                                <flux:button variant="ghost" size="sm" icon="x-mark" type="button" wire:click="removeLine({{ $i }})">
                                    <span class="lg:hidden">{{ __('Remove line') }}</span>
                                </flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="hidden bg-muted lg:table-footer-group">
                    @foreach ($this->taxBreakdown as $taxRow)
                        <tr data-test="inbox-tax-row">
                            <td colspan="4" class="px-2 py-2 text-right font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</td>
                            <td class="px-2 py-2 text-right font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</td>
                            <td></td>
                        </tr>
                    @endforeach
                    <tr class="text-base">
                        <td colspan="4" class="px-2 py-2 text-right font-semibold">{{ __('Total') }}</td>
                        <td class="px-2 py-2 text-right font-mono font-semibold" data-test="inbox-total">{{ number_format($this->totalCents() / 100, 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

            {{-- Mobile totals (tfoot is desktop-only) --}}
            <div class="space-y-1 border-t border-border bg-muted px-3 py-3 text-sm lg:hidden">
                @foreach ($this->taxBreakdown as $taxRow)
                    <div class="flex justify-between"><span class="font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</span><span class="font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</span></div>
                @endforeach
                <div class="flex justify-between text-base"><span class="font-semibold">{{ __('Total') }}</span><span class="font-mono font-semibold">{{ number_format($this->totalCents() / 100, 2) }}</span></div>
            </div>
        </div>

        @error('lines') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror

        <div class="flex items-center justify-between">
            <flux:button variant="filled" type="button" icon="plus" wire:click="addLine" data-test="inbox-add-line">{{ __('Add line') }}</flux:button>

            <flux:button type="submit" variant="primary" icon="document-plus" data-test="inbox-create-draft">
                {{ $documentType === 'bank' ? __('Record transaction') : __('Create draft') }}
            </flux:button>
        </div>
    </form>
</section>
