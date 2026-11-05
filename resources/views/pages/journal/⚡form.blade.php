<?php

use App\Actions\Accounting\SaveJournalEntry;
use App\Actions\Accounting\SaveJournalEntryTemplate;
use App\Actions\Accounting\UpdateJournalEntryHeader;
use App\Enums\Activity;
use App\Enums\AuditAction;
use App\Enums\PermissionAction;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\ReconciliationLockedException;
use App\Exceptions\Posting\UnbalancedJournalException;
use App\Livewire\Attributes\Requires;
use App\Livewire\Concerns\GuardsEditLockedForm;
use App\Livewire\Concerns\HandlesPeriodLockOverride;
use App\Livewire\Concerns\ManagesLineContacts;
use App\Models\Account;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\JournalEntryTemplate;
use App\Models\JournalEntryTemplateLine;
use App\Models\Location;
use App\Models\TaxCode;
use App\Rules\MoneyString;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Currency\ExchangeRateService;
use App\Services\Currency\MissingExchangeRateException;
use App\Services\Posting\BackdatedEntryWarnings;
use App\Services\Posting\EntryNumberGenerator;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\PeriodLockOverride;
use App\Services\Reconciliation\BankReconciliationLockGuard;
use App\Support\Access\CurrentViewer;
use App\Support\Access\PayrollDetail;
use App\Support\Accounting\ForeignJournalLines;
use App\Support\Currency;
use App\Support\Reporting\SourceLinkResolver;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use App\Support\Access\RecordRedirect;

new #[Title('Journal entry')] class extends Component
{
    use GuardsEditLockedForm;
    use HandlesPeriodLockOverride;
    use ManagesLineContacts;

    public Company $company;

    public ?JournalEntry $entry = null;

    public string $entryNo = '';

    /**
     * The number a new entry's page suggested when it opened. Left as is it is
     * only a preview: every posting in the company draws from the same JE
     * sequence, so the real number is taken when the entry is first saved.
     */
    #[Locked]
    public string $suggestedEntryNo = '';

    public string $entryDate = '';

    public string $memo = '';

    public bool $isPosted = false;

    /**
     * True for a source-linked entry the journal may edit header-only (see
     * JournalEntry::hasLockedLines()): date, number and memo are editable, the
     * lines are shown read-only and saved through UpdateJournalEntryHeader.
     */
    public bool $linesLocked = false;

    public ?string $sourceUrl = null;

    public string $sourceLabel = '';

    /**
     * Reasons to pause before a back-dated post or save (see
     * BackdatedEntryWarnings), listed in the confirm dialog.
     *
     * @var list<string>
     */
    #[Locked]
    public array $backdateWarnings = [];

    /**
     * Set for the one request in which the user confirmed the dialog.
     */
    private bool $backdateAcknowledged = false;

    #[Url(as: 'from')]
    public ?int $duplicateFromId = null;

    /**
     * Selected template to pull lines from when creating a new entry. Only shown
     * on create; ignored on save (it is purely a population trigger).
     */
    public ?int $template_id = null;

    /**
     * Name entered in the "Save as template" modal.
     */
    public string $template_name = '';

    /**
     * The entry's lines. debit / credit are always home-currency amounts and
     * are what has to balance. A line on a foreign-currency account is typed
     * in that account's currency instead — foreign_debit / foreign_credit at
     * fx_rate (home units per 1 foreign unit) — and its debit / credit show
     * the home equivalent, read-only:
     *
     *   fx_currency    the currency the foreign fields are in
     *   fx_rate_auto   the rate was filled in from the rates on file, so a new
     *                  entry date fetches the new date's rate; typing one stops that
     *   fx_home_fixed  the home amount is the line's own (a saved line, a
     *                  template's or one carried over from another account) and
     *                  the foreign amount follows it; typing a foreign amount or
     *                  a new rate makes the home amount follow the foreign one
     *
     * @var array<int, array{account_id: ?int, contact_id: ?int, contact_query: string, contact_creating: bool, new_contact_name: string, debit: string, credit: string, memo: ?string, tax_code_id: ?int, class_id: ?int, location_id: ?int, fund_id: ?int, foreign_debit: string, foreign_credit: string, fx_rate: string, fx_currency: ?string, fx_rate_auto: bool, fx_home_fixed: bool}>
     */
    public array $lines = [];

    /**
     * Rates looked up during this request, by "CUR|date" ('' when none is on file).
     *
     * @var array<string, string>
     */
    private array $rateLookups = [];

    protected function editLockRecord(): ?Model
    {
        return $this->entry;
    }

    public function mount(Company $company, ?JournalEntry $entry = null): void
    {
        $this->company = $company;

        if ($entry && $entry->exists) {
            if (! $entry->isEditableInJournal()) {
                $resolver = app(SourceLinkResolver::class);
                $url = $resolver->urlFor($entry, $company)
                    ?? route('journal.show', ['company' => $company->slug, 'entry' => $entry->id]);
                Flux::toast(text: __('This entry is managed by :source. Edit it there.', ['source' => $resolver->label($entry)]));
                $this->redirect($url, navigate: true);

                return;
            }

            if ($entry->hasLockedLines()) {
                // Voided or (impossibly) unposted adjustments have nothing to edit.
                if ($entry->isVoided() || ! $entry->isPosted()) {
                    $this->redirectRoute('journal.show', ['company' => $company->slug, 'entry' => $entry->id], navigate: true);

                    return;
                }

                $resolver = app(SourceLinkResolver::class);
                $this->linesLocked = true;
                $this->sourceLabel = $resolver->label($entry);
                $this->sourceUrl = $resolver->urlFor($entry, $company);
            }

            $this->entry = $entry;
            $this->isPosted = $entry->isPosted();
            $this->entryNo = $entry->entry_no;
            $this->entryDate = $entry->entry_date->toDateString();
            $this->memo = $entry->memo ?? '';
            $this->lines = $this->mapEntryLines($entry);

            if (! $this->linesLocked) {
                $this->hydrateForeignLines();
            }

            return;
        }

        $this->entryDate = $this->company->currentDateTime()->toDateString();
        $this->entryNo = $this->suggestedEntryNo = app(EntryNumberGenerator::class)->next($company);
        $this->lines = [$this->emptyLine(), $this->emptyLine()];

        if ($this->duplicateFromId) {
            $this->prefillFrom($this->duplicateFromId);
        }
    }

    /**
     * Copy a source entry's memo and lines into a fresh, unsaved draft. Ignores
     * sources from another company, and payroll entries the viewer can't open
     * (PayrollDetail). The new entry keeps the today's date and generated
     * entry number set up for a blank draft.
     */
    protected function prefillFrom(int $sourceId): void
    {
        $source = JournalEntry::query()
            ->where('company_id', $this->company->id)
            ->with('lines')
            ->find($sourceId);

        if (! $source || PayrollDetail::hidesEntry($source, CurrentViewer::permissions($this->company))) {
            return;
        }

        $this->memo = $source->memo ?? '';
        $this->lines = $this->mapEntryLines($source);
        $this->hydrateForeignLines();
    }

    /**
     * The entry's lines in the form's shape. A line carrying a foreign memo
     * keeps its saved foreign amount, rate and home amount exactly; see
     * {@see hydrateForeignLines()} for one without.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function mapEntryLines(JournalEntry $entry): array
    {
        return $entry->lines->map(fn ($l) => [
            ...$this->emptyLine(),
            'account_id' => $l->account_id,
            'contact_id' => $l->contact_id,
            'debit' => $l->debit_cents > 0 ? Money::fromCents($l->debit_cents)->toDecimalString() : '',
            'credit' => $l->credit_cents > 0 ? Money::fromCents($l->credit_cents)->toDecimalString() : '',
            'memo' => $l->memo,
            'tax_code_id' => $l->tax_code_id,
            'class_id' => $l->class_id,
            'location_id' => $l->location_id,
            'fund_id' => $l->fund_id,
            ...($l->currency_code !== null ? [
                'foreign_debit' => (int) $l->foreign_debit_cents !== 0 ? Money::fromCents((int) $l->foreign_debit_cents)->toDecimalString() : '',
                'foreign_credit' => (int) $l->foreign_credit_cents !== 0 ? Money::fromCents((int) $l->foreign_credit_cents)->toDecimalString() : '',
                // A memo saved without its rate gets the one its amounts imply.
                'fx_rate' => Currency::normalizeRate($l->fx_rate)
                    ?? Currency::impliedRate((int) $l->debit_cents + (int) $l->credit_cents, (int) $l->foreign_debit_cents + (int) $l->foreign_credit_cents)
                    ?? '',
                'fx_currency' => mb_strtoupper((string) $l->currency_code),
                'fx_home_fixed' => true,
            ] : []),
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyLine(): array
    {
        return [
            'account_id' => null,
            ...$this->emptyLineContactState(),
            'debit' => '',
            'credit' => '',
            'memo' => null,
            'tax_code_id' => null,
            'class_id' => null,
            'location_id' => null,
            'fund_id' => null,
            ...$this->emptyForeignState(),
        ];
    }

    /**
     * The foreign-amount fields of a line, cleared.
     *
     * @return array{foreign_debit: string, foreign_credit: string, fx_rate: string, fx_currency: ?string, fx_rate_auto: bool, fx_home_fixed: bool}
     */
    protected function emptyForeignState(): array
    {
        return [
            'foreign_debit' => '',
            'foreign_credit' => '',
            'fx_rate' => '',
            'fx_currency' => null,
            'fx_rate_auto' => false,
            'fx_home_fixed' => false,
        ];
    }

    /**
     * account_id => currency for the company's foreign-currency accounts (foreign
     * bank and credit-card accounts, and the per-currency receivable and
     * payable controls). A line on one of them is entered in that currency.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function foreignAccountCurrencies(): array
    {
        return Account::query()
            ->where('company_id', $this->company->id)
            ->whereNotNull('currency_code')
            ->pluck('currency_code', 'id')
            ->reject(fn ($code): bool => $this->company->isHomeCurrency((string) $code))
            ->map(fn ($code): string => mb_strtoupper((string) $code))
            ->all();
    }

    /**
     * The currency a line is entered in: its account's, or null for a
     * home-currency account.
     */
    public function lineCurrency(int $index): ?string
    {
        return $this->foreignAccountCurrencies[(int) ($this->lines[$index]['account_id'] ?? 0)] ?? null;
    }

    /**
     * Bring every line's foreign fields in step with its account after the
     * lines were loaded wholesale (an entry being edited or duplicated, a
     * template). A foreign-account line that came without its foreign amount
     * — a template line, or one saved before journal lines recorded them —
     * takes the rate in force on the entry date and works its foreign amount
     * out from its home amount, which it keeps. A home-account line keeps
     * whatever memo it was saved with (see {@see lineAmounts()}).
     */
    protected function hydrateForeignLines(): void
    {
        foreach (array_keys($this->lines) as $i) {
            $currency = $this->lineCurrency($i);
            $line = $this->lines[$i];

            if ($currency === null) {
                continue;
            }

            // A saved line with its memo — or one already being worked on in
            // the form — keeps its amounts and rate exactly.
            if (($line['fx_currency'] ?? null) === $currency) {
                continue;
            }

            $this->lines[$i] = [
                ...$line,
                ...$this->emptyForeignState(),
                'fx_currency' => $currency,
                'fx_rate' => $this->rateOnFile($currency),
                'fx_rate_auto' => true,
                'fx_home_fixed' => true,
            ];

            $this->adoptHomeAmount($i);
        }
    }

    /**
     * Work a foreign-account line's foreign amount out from its home amount
     * at its rate, keeping the home amount as it is. Does nothing until the
     * line has both a rate and a home amount.
     */
    protected function adoptHomeAmount(int $i): void
    {
        $line = $this->lines[$i];
        $rate = Currency::normalizeRate($line['fx_rate'] ?? '');
        $debit = $this->centsOf($line['debit'] ?? '');
        $credit = $this->centsOf($line['credit'] ?? '');

        if ($rate === null || ($debit === 0 && $credit === 0)) {
            return;
        }

        $foreignDebit = Currency::toForeignCents($debit, $rate);
        $foreignCredit = Currency::toForeignCents($credit, $rate);

        $this->lines[$i]['foreign_debit'] = $foreignDebit !== 0 ? Money::fromCents($foreignDebit)->toDecimalString() : '';
        $this->lines[$i]['foreign_credit'] = $foreignCredit !== 0 ? Money::fromCents($foreignCredit)->toDecimalString() : '';
        $this->lines[$i]['fx_home_fixed'] = true;
    }

    /**
     * The rate in force for a currency on the entry date — the one a foreign
     * document dated that day would lock — as the rate field shows it, or ''
     * when none is on file (the user then types one).
     */
    protected function rateOnFile(string $currency): string
    {
        try {
            $date = CarbonImmutable::parse($this->entryDate);
        } catch (InvalidFormatException) {
            return '';
        }

        $key = $currency.'|'.$date->toDateString();

        if (! array_key_exists($key, $this->rateLookups)) {
            try {
                $rate = app(ExchangeRateService::class)->rateFor($this->company, $currency, $date);
                $this->rateLookups[$key] = Currency::normalizeRate($rate) ?? '';
            } catch (MissingExchangeRateException) {
                $this->rateLookups[$key] = '';
            } catch (\Throwable $e) {
                report($e);
                $this->rateLookups[$key] = '';
            }
        }

        return $this->rateLookups[$key];
    }

    /**
     * A line's amounts the way {@see SaveJournalEntry} takes them. A
     * home-account line sends its debit / credit. A foreign-account line sends
     * its foreign amounts and rate, plus its home amounts only while those are
     * its own (fx_home_fixed); otherwise the action works them out.
     *
     * @return array<string, mixed>
     */
    protected function lineAmounts(int $i): array
    {
        $line = $this->lines[$i];
        $currency = $this->lineCurrency($i);

        if ($currency === null) {
            $amounts = [
                'debit_cents' => $this->centsOf($line['debit'] ?? ''),
                'credit_cents' => $this->centsOf($line['credit'] ?? ''),
            ];

            // A memo saved with a home-account line — a foreign document's leg,
            // copied by its reversal — goes back exactly as it came. Changing
            // the line's amount or account drops it.
            if (($line['fx_currency'] ?? null) !== null) {
                $amounts += [
                    'currency_code' => $line['fx_currency'],
                    'fx_rate' => trim((string) ($line['fx_rate'] ?? '')) ?: null,
                    'foreign_debit_cents' => $this->centsOf($line['foreign_debit'] ?? ''),
                    'foreign_credit_cents' => $this->centsOf($line['foreign_credit'] ?? ''),
                ];
            }

            return $amounts;
        }

        $fixed = (bool) ($line['fx_home_fixed'] ?? false);
        $rate = trim((string) ($line['fx_rate'] ?? ''));

        return [
            'debit_cents' => $fixed ? $this->centsOf($line['debit'] ?? '') : 0,
            'credit_cents' => $fixed ? $this->centsOf($line['credit'] ?? '') : 0,
            'foreign_debit_cents' => $this->centsOf($line['foreign_debit'] ?? ''),
            'foreign_credit_cents' => $this->centsOf($line['foreign_credit'] ?? ''),
            'fx_rate' => $rate !== '' ? $rate : null,
            'currency_code' => $currency,
        ];
    }

    /**
     * Show each foreign-account line's home amount as the action will work it
     * out (rounding plug included), on the lines whose home amount follows
     * their foreign one. Needs no rate lookup: the form always has the rate.
     */
    protected function syncForeignLines(): void
    {
        $foreign = array_filter(array_keys($this->lines), fn (int $i): bool => $this->lineCurrency($i) !== null);

        if ($foreign === []) {
            return;
        }

        $indexes = array_keys($this->lines);
        $result = ForeignJournalLines::resolve(
            array_map(fn (int $i): array => [...$this->lineAmounts($i), 'currency' => $this->lineCurrency($i)], $indexes),
            fn (string $currency): ?string => null,
        );

        foreach ($indexes as $position => $i) {
            if (! in_array($i, $foreign, true) || ($this->lines[$i]['fx_home_fixed'] ?? false)) {
                continue;
            }

            $resolved = $result['lines'][$position];
            $this->lines[$i]['debit'] = $resolved['debit_cents'] !== 0 ? Money::fromCents($resolved['debit_cents'])->toDecimalString() : '';
            $this->lines[$i]['credit'] = $resolved['credit_cents'] !== 0 ? Money::fromCents($resolved['credit_cents'])->toDecimalString() : '';
        }
    }

    /**
     * A line's account changed. To a home-currency account: drop the foreign
     * fields and keep the home amount the line showed. To a foreign account in
     * another currency: take that currency's rate on the entry date and work
     * the foreign amount out from the home amount. Same currency as before:
     * keep the foreign amount and rate.
     */
    protected function accountChanged(int $i): void
    {
        $currency = $this->lineCurrency($i);
        $line = $this->lines[$i];

        if ($currency === null) {
            $this->lines[$i] = [...$line, ...$this->emptyForeignState()];

            return;
        }

        if (($line['fx_currency'] ?? null) === $currency) {
            return;
        }

        $this->lines[$i] = [
            ...$line,
            ...$this->emptyForeignState(),
            'fx_currency' => $currency,
            'fx_rate' => $this->rateOnFile($currency),
            'fx_rate_auto' => true,
            'fx_home_fixed' => true,
        ];

        $this->adoptHomeAmount($i);
    }

    /**
     * Refresh the rates that were filled in from the rates on file when the
     * entry date moves; a rate the user typed stays. A line whose home amount
     * is its own re-works its foreign amount at the new rate.
     */
    public function updatedEntryDate(): void
    {
        $this->rateLookups = [];

        foreach (array_keys($this->lines) as $i) {
            $currency = $this->lineCurrency($i);

            if ($currency === null || ! ($this->lines[$i]['fx_rate_auto'] ?? false)) {
                continue;
            }

            $rate = $this->rateOnFile($currency);

            if ($rate === '' || $rate === $this->lines[$i]['fx_rate']) {
                continue;
            }

            $this->lines[$i]['fx_rate'] = $rate;

            if ($this->lines[$i]['fx_home_fixed'] ?? false) {
                $this->lines[$i]['foreign_debit'] = '';
                $this->lines[$i]['foreign_credit'] = '';
                $this->adoptHomeAmount($i);
            }
        }

        $this->syncForeignLines();
    }

    /**
     * A foreign-account line's home amount as its read-only cell shows it.
     */
    public function homeAmountLabel(mixed $value): string
    {
        $cents = $this->centsOf($value);

        return $cents !== 0 ? number_format($cents / 100, 2) : '';
    }

    /**
     * An amount field's value in cents, 0 while it doesn't parse.
     */
    public function homeAmountCents(mixed $value): int
    {
        return $this->centsOf($value);
    }

    private function centsOf(mixed $value): int
    {
        return Money::tryFromString((string) $value)?->cents ?? 0;
    }

    public function addLine(): void
    {
        $this->lines[] = $this->emptyLine();
    }

    public function removeLine(int $index): void
    {
        if (count($this->lines) <= 2) {
            return;
        }

        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);

        // A rounding cent on the remaining foreign lines may move.
        $this->syncForeignLines();
    }

    /**
     * The code and name ride alongside the label for the typeable account
     * picker (<x-account-combo>), which ranks GL-number matches first.
     *
     * @return array<int, array{value: int, label: string, code: string, name: string}>
     */
    #[Computed]
    public function accountOptions(): array
    {
        return Account::query()
            ->where('company_id', $this->company->id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->code} — {$a->displayName()}", 'code' => (string) $a->code, 'name' => $a->displayName()])
            ->all();
    }

    /**
     * Reset the contact picker when a line's account changes to one that no longer
     * requires it, and fill a blank tax code from the account's default. Keep the
     * home amounts of foreign-currency lines in step with what was typed.
     */
    public function updatedLines(mixed $value, ?string $key = null): void
    {
        // Livewire passes a null key when the whole `lines` array is updated
        // (a top-level, dot-less path) rather than a single nested field.
        if ($key === null || ! str_contains($key, '.')) {
            return;
        }

        [$index, $field] = explode('.', $key, 2);
        $index = (int) $index;

        if (! isset($this->lines[$index])) {
            return;
        }

        match ($field) {
            'account_id' => $this->lineAccountChanged($index, $value),
            // Typing a foreign amount makes the home amount follow it.
            'foreign_debit', 'foreign_credit' => $this->lines[$index]['fx_home_fixed'] = false,
            'fx_rate' => $this->lineRateChanged($index),
            // A home-account line's saved memo no longer describes a new amount.
            'debit', 'credit' => $this->lineCurrency($index) === null
                ? $this->lines[$index] = [...$this->lines[$index], ...$this->emptyForeignState()]
                : null,
            default => null,
        };

        if (in_array($field, ['account_id', 'debit', 'credit', 'foreign_debit', 'foreign_credit', 'fx_rate'], true)) {
            $this->syncForeignLines();
        }
    }

    protected function lineAccountChanged(int $index, mixed $value): void
    {
        // Picking an account fills a blank tax code from the account's default —
        // never overwriting one already on the line. The tag carries no amounts,
        // so nothing is recalculated.
        if ($value && empty($this->lines[$index]['tax_code_id'])) {
            $this->lines[$index]['tax_code_id'] = Account::find($value)?->default_tax_code_id;
        }

        if (! isset($this->contactRequiringAccounts[(int) $value])) {
            $this->resetLineContactState($index);
        }

        $this->accountChanged($index);
    }

    /**
     * A rate typed by hand stays put when the date moves. On a line that has
     * a home amount but no foreign amount yet, it works the foreign amount
     * out; otherwise the home amount follows the foreign one at the new rate.
     */
    protected function lineRateChanged(int $index): void
    {
        $this->lines[$index]['fx_rate_auto'] = false;
        $line = $this->lines[$index];

        $hasForeign = $this->centsOf($line['foreign_debit'] ?? '') !== 0 || $this->centsOf($line['foreign_credit'] ?? '') !== 0;
        $hasHome = $this->centsOf($line['debit'] ?? '') !== 0 || $this->centsOf($line['credit'] ?? '') !== 0;

        if (! $hasForeign && $hasHome) {
            $this->adoptHomeAmount($index);

            return;
        }

        $this->lines[$index]['fx_home_fixed'] = false;
    }

    #[Computed]
    public function templateOptions()
    {
        return JournalEntryTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Pull a template's lines onto the form when one is picked. Only acts while
     * creating (templates are hidden when editing). Replaces the seeded blank
     * lines if they are all still pristine, otherwise appends.
     */
    public function updatedTemplateId(?int $value): void
    {
        if ($this->entry?->id || ! $value) {
            return;
        }

        $template = JournalEntryTemplate::with('lines.account')->find($value);

        if (! $template) {
            return;
        }

        $mapped = $template->lines->map(fn (JournalEntryTemplateLine $tl) => $this->lineFromTemplate($tl))->all();

        if ($mapped === []) {
            return;
        }

        if (collect($this->lines)->every(fn (array $l): bool => $this->isEmptyLine($l))) {
            $this->lines = $mapped;
        } else {
            $this->lines = array_merge($this->lines, $mapped);
        }

        $this->lines = array_values($this->lines);

        // Templates hold home amounts; a foreign-account line works out its
        // foreign amount at the entry date's rate.
        $this->hydrateForeignLines();
        $this->syncForeignLines();
    }

    /**
     * Map a template line into the form's $lines shape (mirrors emptyLine()).
     *
     * @return array<string, mixed>
     */
    protected function lineFromTemplate(JournalEntryTemplateLine $tl): array
    {
        return [
            ...$this->emptyLine(),
            'account_id' => $tl->account_id,
            'debit' => $tl->debit_cents > 0 ? Money::fromCents($tl->debit_cents)->toDecimalString() : '',
            'credit' => $tl->credit_cents > 0 ? Money::fromCents($tl->credit_cents)->toDecimalString() : '',
            'memo' => $tl->memo,
            // Templates carry no tax code; fill it from the account's default,
            // as updatedLines() does when the account is picked by hand.
            'tax_code_id' => $tl->tax_code_id ?? $tl->account?->default_tax_code_id,
            'class_id' => $tl->class_id,
            'location_id' => $tl->location_id,
            'fund_id' => $tl->fund_id,
        ];
    }

    /**
     * Whether a line is still a pristine blank — used to decide replace-vs-append
     * when applying a template and which lines to skip when saving as a template.
     *
     * @param  array<string, mixed>  $line
     */
    protected function isEmptyLine(array $line): bool
    {
        return empty($line['account_id'])
            && (Money::tryFromString((string) ($line['debit'] ?? ''))?->cents ?? 0) === 0
            && (Money::tryFromString((string) ($line['credit'] ?? ''))?->cents ?? 0) === 0
            && $this->centsOf($line['foreign_debit'] ?? '') === 0
            && $this->centsOf($line['foreign_credit'] ?? '') === 0
            && trim((string) ($line['memo'] ?? '')) === '';
    }

    /**
     * Capture the current lines as a reusable journal-entry template. Reuses the
     * same SaveJournalEntryTemplate action as the dedicated management page.
     */
    #[Requires(Activity::JournalEntryTemplates, PermissionAction::Create)]
    public function saveAsTemplate(): void
    {
        $validated = $this->validate(
            [
                'template_name' => ['required', 'string', 'max:255'],
                'lines.*.account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $this->company->id)],
            ],
            attributes: ['template_name' => __('template name')],
        );

        $lines = collect($this->lines)
            ->reject(fn (array $line): bool => $this->isEmptyLine($line))
            ->map(fn (array $line): array => [
                'account_id' => $line['account_id'] ?? null,
                'debit_cents' => Money::tryFromString((string) ($line['debit'] ?? ''))?->cents ?? 0,
                'credit_cents' => Money::tryFromString((string) ($line['credit'] ?? ''))?->cents ?? 0,
                'memo' => $line['memo'] ?? null,
                'class_id' => $line['class_id'] ?? null,
                'location_id' => $line['location_id'] ?? null,
                'fund_id' => $line['fund_id'] ?? null,
            ])
            ->values()
            ->all();

        if ($lines === []) {
            $this->addError('template_name', __('Add at least one line before saving a template.'));

            return;
        }

        app(SaveJournalEntryTemplate::class)->handle([
            'name' => $validated['template_name'],
            'is_active' => true,
            'lines' => $lines,
        ]);

        Flux::modal('save-as-je-template')->close();
        $this->template_name = '';
        unset($this->templateOptions);
        Flux::toast(variant: 'success', text: __('Template saved.'));
    }

    #[Computed]
    public function totalDebitsCents(): int
    {
        return array_sum(array_map(fn ($l) => Money::tryFromString((string) $l['debit'])?->cents ?? 0, $this->lines));
    }

    #[Computed]
    public function totalCreditsCents(): int
    {
        return array_sum(array_map(fn ($l) => Money::tryFromString((string) $l['credit'])?->cents ?? 0, $this->lines));
    }

    #[Computed]
    public function differenceCents(): int
    {
        return $this->totalDebitsCents - $this->totalCreditsCents;
    }

    #[Computed]
    public function taxCodeOptions()
    {
        return TaxCode::query()->where('is_active', true)->orderBy('code')->get();
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

    #[Computed]
    public function fundOptions()
    {
        return Fund::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function tracksFunds(): bool
    {
        return $this->company->tracksFunds();
    }

    #[Computed]
    public function dimensionColumns(): int
    {
        return (int) $this->tracksClasses + (int) $this->tracksLocations + (int) $this->tracksFunds;
    }

    public function saveDraft(): void
    {
        if ($this->finishIfAlreadyPosted()) {
            return;
        }

        $this->persist(post: false);
        Flux::toast(variant: 'success', text: __('Draft saved.'));
        $this->redirectRoute(RecordRedirect::route('journal.edit'), ['company' => $this->company->slug, 'entry' => $this->entry->id], navigate: true);
    }

    public function postEntry(JournalPoster $poster): void
    {
        if ($this->finishIfAlreadyPosted()) {
            return;
        }

        if ($this->differenceCents === 0 && $this->totalDebitsCents > 0 && $this->holdForBackdateWarnings()) {
            return;
        }

        $this->persist(post: false);

        try {
            $poster->post($this->entry);
        } catch (PeriodLockedException $e) {
            if (! $this->offerPeriodLockOverride($e, 'postEntry')) {
                $this->addError('lines', $e->getMessage());
            }

            return;
        } catch (UnbalancedJournalException|ReconciliationLockedException $e) {
            $this->addError('lines', $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Entry posted.'));
        $this->redirectRoute('journal.show', ['company' => $this->company->slug, 'entry' => $this->entry->id], navigate: true);
    }

    /**
     * "Post anyway" / "Save anyway" from the back-dated entry dialog: re-run the
     * action the dialog interrupted, this time without stopping for warnings.
     */
    public function confirmBackdated(JournalPoster $poster): void
    {
        $this->backdateAcknowledged = true;
        $this->backdateWarnings = [];
        Flux::modal('backdate-warning')->close();

        $this->isPosted ? $this->saveChanges($poster) : $this->postEntry($poster);
    }

    /**
     * Open the confirm dialog — and stop the action — when the entry date gives
     * a reason to pause (a reconciled period, a prior fiscal year). Advisory
     * only: a locked period or a reconciled line is left to the action's own
     * hard error, and an unparseable date to validation.
     */
    private function holdForBackdateWarnings(): bool
    {
        // A closed-period override the user just confirmed is the stronger
        // warning; don't stack a second dialog on top of it.
        if ($this->backdateAcknowledged || PeriodLockOverride::isActive()) {
            return false;
        }

        try {
            $date = CarbonImmutable::parse($this->entryDate);
        } catch (InvalidFormatException) {
            return false;
        }

        if ($this->entryDate === '' || $this->company->isLockedFor($date)) {
            return false;
        }

        // A posted entry carrying a reconciled line can't be saved at all; let
        // the action report that rather than warn about it first.
        if ($this->isPosted && $this->entry) {
            try {
                app(BankReconciliationLockGuard::class)->ensureLinesNotReconciled((int) $this->company->id, $this->entry->lines);
            } catch (ReconciliationLockedException) {
                return false;
            }
        }

        $accountIds = $this->linesLocked && $this->entry
            ? $this->entry->lines->pluck('account_id')
            : collect($this->lines)->pluck('account_id');

        $this->backdateWarnings = app(BackdatedEntryWarnings::class)->for($this->company, $accountIds, $date);

        if ($this->backdateWarnings === []) {
            return false;
        }

        Flux::modal('backdate-warning')->show();

        return true;
    }

    /**
     * A second Post or Save draft — a double-click, or Enter pressed again
     * before the redirect lands — arrives holding the entry the first request
     * just posted. Saving it as a draft would rewrite a posted entry's lines
     * outside saveChanges() (no lock check, no balance recompute) and posting
     * it again throws, so finish the way the first request did instead. Reads
     * the row fresh: the posting may have committed after this request began.
     */
    private function finishIfAlreadyPosted(): bool
    {
        if (! $this->entry?->exists || ! $this->entry->fresh()?->isPosted()) {
            return false;
        }

        $this->redirectRoute('journal.show', ['company' => $this->company->slug, 'entry' => $this->entry->id], navigate: true);

        return true;
    }

    /**
     * Save edits to an already-posted entry in place. The entry stays posted, so it
     * must remain balanced, out of any locked period and free of reconciled lines;
     * affected account balances are recomputed and the overwrite is recorded to the
     * audit trail.
     */
    public function saveChanges(JournalPoster $poster): void
    {
        abort_unless($this->entry && $this->entry->isPosted(), 403);
        abort_unless($this->entry->isEditableInJournal(), 403);

        if ($this->entry->hasLockedLines()) {
            if (! $this->holdForBackdateWarnings()) {
                $this->saveHeaderOnly();
            }

            return;
        }

        if ($this->differenceCents !== 0 || $this->totalDebitsCents === 0) {
            $this->addError('lines', __('A posted entry must balance before saving.'));

            return;
        }

        try {
            $poster->ensurePostedEntryEditable($this->entry, CarbonImmutable::parse($this->entryDate));
        } catch (PeriodLockedException $e) {
            if (! $this->offerPeriodLockOverride($e, 'saveChanges')) {
                $this->addError('lines', $e->getMessage());
            }

            return;
        } catch (ReconciliationLockedException $e) {
            $this->addError('lines', $e->getMessage());

            return;
        }

        if ($this->holdForBackdateWarnings()) {
            return;
        }

        $affectedAccountIds = $this->entry->lines->pluck('account_id')->all();

        $this->persist(post: false);

        $affectedAccountIds = array_merge($affectedAccountIds, $this->entry->lines->pluck('account_id')->all());
        $poster->recomputeAccounts($affectedAccountIds);

        $fresh = $this->entry->fresh('lines.account');

        app(AccountingAuditRecorder::class)->record(
            (int) $this->entry->company_id,
            AuditAction::JournalEntryUpdated,
            $this->entry,
            AccountingAuditRecorder::snapshotJournalEntry($fresh),
            $fresh,
        );

        Flux::toast(variant: 'success', text: __('Entry updated.'));
        $this->redirectRoute('journal.show', ['company' => $this->company->slug, 'entry' => $this->entry->id], navigate: true);
    }

    /**
     * Header-only save for an entry whose lines belong to another document (a
     * reconciliation's service charge / interest): the date, number and memo
     * change in place, the lines — and the bank line's cleared flag — survive.
     */
    protected function saveHeaderOnly(): void
    {
        $validated = $this->validate([
            'entryNo' => ['required', 'string', 'max:40', $this->entryNoIsFree()],
            'entryDate' => ['required', 'date'],
            'memo' => ['nullable', 'string'],
        ], $this->entryNoMessages());

        try {
            $this->entry = app(UpdateJournalEntryHeader::class)->handle($this->entry, [
                'entry_no' => $validated['entryNo'],
                'entry_date' => $validated['entryDate'],
                'memo' => $validated['memo'] ?: null,
            ]);
        } catch (PeriodLockedException $e) {
            if (! $this->offerPeriodLockOverride($e, 'saveChanges')) {
                $this->addError('entryDate', $e->getMessage());
            }

            return;
        } catch (ReconciliationLockedException $e) {
            $this->addError('entryDate', $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Entry updated.'));
        $this->redirectRoute('journal.show', ['company' => $this->company->slug, 'entry' => $this->entry->id], navigate: true);
    }

    private function persist(bool $post): void
    {
        $companyId = $this->company->id;

        // A new entry still showing the number its page suggested takes the
        // next free one as it saves (someone may have posted since the page
        // opened); a number typed by hand is kept, and must be free.
        $autoNumber = ! $this->entry?->exists && trim($this->entryNo) === $this->suggestedEntryNo;

        $validated = $this->validate([
            'entryNo' => $autoNumber ? ['required', 'string', 'max:40'] : ['required', 'string', 'max:40', $this->entryNoIsFree()],
            'entryDate' => ['required', 'date'],
            'memo' => ['nullable', 'string'],
            'lines' => ['array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'lines.*.contact_id' => ['nullable', 'integer'],
            'lines.*.debit' => ['nullable', 'string', new MoneyString],
            'lines.*.credit' => ['nullable', 'string', new MoneyString],
            'lines.*.memo' => ['nullable', 'string'],
            'lines.*.tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('company_id', $companyId)],
            'lines.*.class_id' => ['nullable', 'integer', Rule::exists('classifications', 'id')->where('company_id', $companyId)],
            'lines.*.location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'lines.*.fund_id' => ['nullable', 'integer', Rule::exists('funds', 'id')->where('company_id', $companyId)],
            ...$this->foreignLineRules(),
        ], [...$this->entryNoMessages(), ...$this->foreignLineMessages()]);

        $this->resolveNewLineContacts();
        $this->validateLineContacts();

        // The validator lists explicitly-keyed rules (a foreign line's
        // lines.N.fx_rate) before wildcard ones, so its lines can come back
        // out of order; save them in the order the form shows them.
        $validatedLines = $validated['lines'];
        ksort($validatedLines, SORT_NUMERIC);

        $lines = [];
        foreach ($validatedLines as $i => $line) {
            $requiresContact = isset($this->contactRequiringAccounts[(int) $line['account_id']]);

            $lines[] = [
                'account_id' => $line['account_id'],
                'contact_id' => $requiresContact ? ($this->lines[$i]['contact_id'] ?: null) : null,
                ...$this->lineAmounts($i),
                'memo' => $line['memo'] ?: null,
                'tax_code_id' => ($this->lines[$i]['tax_code_id'] ?? null) ?: null,
                'class_id' => $this->lines[$i]['class_id'] ?? null,
                'location_id' => $this->lines[$i]['location_id'] ?? null,
                'fund_id' => $this->lines[$i]['fund_id'] ?? null,
            ];
        }

        try {
            $this->entry = app(SaveJournalEntry::class)->handle([
                'entry_no' => $autoNumber ? null : $validated['entryNo'],
                'entry_date' => $validated['entryDate'],
                'memo' => $validated['memo'] ?: null,
                'lines' => $lines,
            ], $this->entry);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages($this->formLineErrors($e->errors(), array_keys($validatedLines)));
        } catch (UniqueConstraintViolationException $e) {
            // A typed number taken between validation and the insert. The
            // save rolled back, so nothing was written.
            if (! str_contains($e->getMessage(), 'entry_no')) {
                throw $e;
            }

            throw ValidationException::withMessages(['entryNo' => $this->entryNoMessages()['entryNo.unique']]);
        }

        $this->entryNo = $this->entry->entry_no;
    }

    /**
     * A foreign-account line with an amount on it needs its rate; its foreign
     * amounts must parse like any other.
     *
     * @return array<string, list<mixed>>
     */
    private function foreignLineRules(): array
    {
        $rules = [];

        foreach (array_keys($this->lines) as $i) {
            if ($this->lineCurrency($i) === null) {
                continue;
            }

            $rules["lines.{$i}.foreign_debit"] = ['nullable', 'string', new MoneyString];
            $rules["lines.{$i}.foreign_credit"] = ['nullable', 'string', new MoneyString];

            $amounts = $this->lineAmounts($i);

            if ($amounts['debit_cents'] !== 0 || $amounts['credit_cents'] !== 0
                || $amounts['foreign_debit_cents'] !== 0 || $amounts['foreign_credit_cents'] !== 0) {
                $rules["lines.{$i}.fx_rate"] = ['required', 'numeric', 'decimal:0,8', 'gt:0', 'max:9999999999'];
            }
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    private function foreignLineMessages(): array
    {
        $invalid = __('Enter an exchange rate above zero, with at most 8 decimal places.');

        return [
            'lines.*.fx_rate.required' => __('Enter the exchange rate for this line.'),
            'lines.*.fx_rate.numeric' => $invalid,
            'lines.*.fx_rate.decimal' => $invalid,
            'lines.*.fx_rate.gt' => $invalid,
            'lines.*.fx_rate.max' => $invalid,
        ];
    }

    /**
     * Point the action's line errors (lines.N.fx_rate, lines.N.foreign_debit_cents,
     * …, numbered over the lines it was sent) at the form's own fields.
     *
     * @param  array<string, array<int, string>>  $errors
     * @param  list<int|string>  $formIndexes  the form index of each line sent, in order
     * @return array<string, array<int, string>>
     */
    private function formLineErrors(array $errors, array $formIndexes): array
    {
        $fields = [
            'debit_cents' => 'debit',
            'credit_cents' => 'credit',
            'foreign_debit_cents' => 'foreign_debit',
            'foreign_credit_cents' => 'foreign_credit',
            'currency_code' => 'account_id',
        ];

        $mapped = [];

        foreach ($errors as $key => $messages) {
            if (preg_match('/^lines\.(\d+)\.(\w+)$/', $key, $m) === 1 && isset($formIndexes[(int) $m[1]])) {
                $key = 'lines.'.$formIndexes[(int) $m[1]].'.'.($fields[$m[2]] ?? $m[2]);
            }

            $mapped[$key] = $messages;
        }

        return $mapped;
    }

    /**
     * Entry numbers are unique per company (any entry, including another
     * document's posting), apart from this entry's own.
     */
    private function entryNoIsFree(): Unique
    {
        return Rule::unique('journal_entries', 'entry_no')
            ->where('company_id', $this->company->id)
            ->ignore($this->entry?->id);
    }

    /**
     * @return array<string, string>
     */
    private function entryNoMessages(): array
    {
        return ['entryNo.unique' => __('Entry # :input is already used. Pick another number.')];
    }

    /**
     * @param  array{debit: string, credit: string}  $line
     */
    protected function lineHasAmount(array $line): bool
    {
        $debit = Money::tryFromString((string) $line['debit'])?->cents ?? 0;
        $credit = Money::tryFromString((string) $line['credit'])?->cents ?? 0;

        return $debit !== 0 || $credit !== 0
            || $this->centsOf($line['foreign_debit'] ?? '') !== 0
            || $this->centsOf($line['foreign_credit'] ?? '') !== 0;
    }
}; ?>

<section class="w-full">
    @if ($editLockBlocked) <x-edit-lock.blocked :lock="$this->editLockView" /> @else
    <x-edit-lock.status :lock="$this->editLockView" />

    <flux:heading size="xl" level="1" class="mb-6">{{ $entry?->id ? __('Edit journal entry') : __('New journal entry') }}</flux:heading>

    @if ($linesLocked)
        <flux:callout variant="secondary" icon="link" class="mb-6" data-test="locked-lines-callout">
            <flux:callout.heading>{{ __('Created by :source', ['source' => $sourceLabel]) }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('You can change the date, entry number, and memo here. The accounts and amounts belong to the reconciliation — change those from the reconciliation itself.') }}
                @if ($sourceUrl)
                    <a href="{{ $sourceUrl }}" wire:navigate class="underline" data-test="locked-lines-source-link">{{ __('Open :source', ['source' => $sourceLabel]) }}</a>
                @endif
            </flux:callout.text>
        </flux:callout>
    @elseif ($isPosted)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6">
            {{ __('This entry is posted. Saving overwrites it in place and changes already-reported balances.') }}
        </flux:callout>
    @endif

    <form wire:submit="{{ $isPosted ? 'saveChanges' : 'postEntry' }}" class="space-y-6">
        @unless ($entry?->id)
            @if ($this->templateOptions->isNotEmpty())
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <flux:select wire:model.live="template_id" :label="__('Template')" :description="__('Fill the lines below from a saved template.')" data-test="journal-entry-template-picker">
                        <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                        @foreach ($this->templateOptions as $tpl)
                            <flux:select.option :value="$tpl->id">{{ $tpl->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif
        @endunless

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <flux:input wire:model="entryNo" :label="__('Entry #')" required data-test="entry-no-input" />
            {{-- Live: a foreign-currency line's rate follows the date. --}}
            <flux:input type="date" wire:model.live="entryDate" :label="__('Date')" required data-test="entry-date-input" />
            <flux:input wire:model="memo" :label="__('Memo')" data-test="entry-memo-input" />
        </div>

        @if ($linesLocked)
            <div class="overflow-x-auto rounded-lg border border-border" data-test="locked-lines">
                <table class="w-full text-sm">
                    <thead class="bg-muted">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium">{{ __('Account') }}</th>
                            <th class="px-4 py-2 text-left font-medium">{{ __('Line memo') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ __('Debit') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ __('Credit') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($entry->lines as $line)
                            <tr data-test="locked-line-row">
                                <td class="px-4 py-2">{{ $line->account->code }} — {{ $line->account->displayName() }}</td>
                                <td class="px-4 py-2 text-muted-foreground">{{ $line->memo }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ $line->debit_cents > 0 ? number_format($line->debit_cents / 100, 2) : '' }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ $line->credit_cents > 0 ? number_format($line->credit_cents / 100, 2) : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-muted">
                        <tr>
                            <td colspan="2" class="px-4 py-2 text-right font-medium">{{ __('Totals') }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($entry->totalDebitsCents() / 100, 2) }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($entry->totalCreditsCents() / 100, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="flex items-center justify-end gap-2">
                @error('entryDate')
                    <flux:text class="me-auto text-red-600">{{ $message }}</flux:text>
                @enderror
                <flux:button variant="ghost" type="button" :href="route('journal.show', ['company' => $company->slug, 'entry' => $entry->id])" wire:navigate>{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" type="submit" data-test="save-changes-button">{{ __('Save changes') }}</flux:button>
            </div>
        @else
        @php($lineGrid = 'lg:grid lg:grid-cols-[minmax(0,1fr)_8rem_7rem_7rem_minmax(0,1.1fr)_2.75rem] lg:items-start lg:gap-3')
        <div class="overflow-hidden rounded-lg border border-border text-sm">
            {{-- Column headers (desktop): the line fields below align to these columns. --}}
            <div class="{{ $lineGrid }} hidden bg-muted px-3 py-2 font-medium text-muted-foreground">
                <div>{{ __('Account') }}</div>
                <div>{{ __('Tax code') }}</div>
                <div class="text-right">{{ __('Debit') }}</div>
                <div class="text-right">{{ __('Credit') }}</div>
                <div>{{ __('Line memo') }}</div>
                <div></div>
            </div>

            <div class="divide-y divide-border">
                <x-account-combo.options key="accounts" :options="$this->accountOptions" />
                @foreach ($lines as $i => $line)
                    @php($lineCurrency = $this->foreignAccountCurrencies[(int) ($line['account_id'] ?? 0)] ?? null)
                    <div wire:key="line-{{ $i }}" data-test="entry-line-row" class="space-y-3 p-3">
                        {{-- Tier 1: account, debit, credit, memo, remove --}}
                        <div class="{{ $lineGrid }} grid grid-cols-1 gap-3">
                            <div>
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Account') }}</span>
                                <x-account-combo model="lines.{{ $i }}.account_id" options="accounts" :placeholder="__('— Select —')" data-test="line-account" />

                                @if (! $lineCurrency && ($line['fx_currency'] ?? null))
                                    {{-- The memo a foreign document's leg carries; it goes back unchanged unless the amount changes. --}}
                                    @php($memoCents = abs($this->homeAmountCents($line['foreign_debit'] ?? '') - $this->homeAmountCents($line['foreign_credit'] ?? '')))
                                    <div class="mt-1 font-mono text-xs text-muted-foreground" data-test="line-foreign-memo">{{ $line['fx_currency'].' '.number_format($memoCents / 100, 2).(($line['fx_rate'] ?? '') !== '' ? ' @ '.$line['fx_rate'] : '') }}</div>
                                @endif

                                @php($contactRole = $this->contactRequiringAccounts[(int) ($line['account_id'] ?? 0)] ?? null)
                                @if ($contactRole === 'customer')
                                    <x-line-contact-combo
                                        :index="$i"
                                        add-label="customer"
                                        :placeholder="__('Search or add a customer…')"
                                        :options="$this->lineContactOptions($i)"
                                        :selected-id="$line['contact_id']"
                                        :selected-name="$this->lineContactName($i)"
                                        :query="$line['contact_query']"
                                        :creating="$line['contact_creating']"
                                        data-test="line-customer-combo"
                                    />
                                @elseif ($contactRole === 'vendor')
                                    <x-line-contact-combo
                                        :index="$i"
                                        add-label="vendor"
                                        :placeholder="__('Search or add a vendor…')"
                                        :options="$this->lineContactOptions($i)"
                                        :selected-id="$line['contact_id']"
                                        :selected-name="$this->lineContactName($i)"
                                        :query="$line['contact_query']"
                                        :creating="$line['contact_creating']"
                                        data-test="line-vendor-combo"
                                    />
                                @endif
                            </div>
                            <div>
                                {{-- A reporting tag only — no amounts are calculated from it. --}}
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Tax code') }}</span>
                                <flux:select wire:model="lines.{{ $i }}.tax_code_id" data-test="line-tax">
                                    <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                    @foreach ($this->taxCodeOptions as $opt)
                                        <flux:select.option :value="$opt->id">{{ $opt->label() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                            <div>
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Debit') }}</span>
                                @if ($lineCurrency)
                                    {{-- The home equivalent of the foreign amount below; it is what balances. --}}
                                    <div class="flex min-h-10 items-center justify-end rounded-lg bg-muted px-3 font-mono text-muted-foreground" data-test="line-home-debit">{{ $this->homeAmountLabel($line['debit']) }}</div>
                                @else
                                    <x-amount-input model="lines.{{ $i }}.debit" class="lg:text-right" placeholder="0.00" data-test="line-debit" />
                                @endif
                            </div>
                            <div>
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Credit') }}</span>
                                @if ($lineCurrency)
                                    <div class="flex min-h-10 items-center justify-end rounded-lg bg-muted px-3 font-mono text-muted-foreground" data-test="line-home-credit">{{ $this->homeAmountLabel($line['credit']) }}</div>
                                @else
                                    <x-amount-input model="lines.{{ $i }}.credit" class="lg:text-right" placeholder="0.00" data-test="line-credit" />
                                @endif
                            </div>
                            <div>
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Line memo') }}</span>
                                <flux:input wire:model="lines.{{ $i }}.memo" />
                            </div>
                            <div class="flex lg:justify-end lg:pt-1">
                                <flux:button variant="ghost" size="sm" icon="x-mark" type="button" wire:click="removeLine({{ $i }})">
                                    <span class="lg:hidden">{{ __('Remove line') }}</span>
                                </flux:button>
                            </div>
                        </div>

                        {{-- A line on a foreign-currency account is typed in that currency at a
                             rate; the Debit / Credit above show its home equivalent. --}}
                        @if ($lineCurrency)
                            @php($lineRate = \App\Support\Currency::normalizeRate($line['fx_rate'] ?? ''))
                            <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-start" data-test="line-foreign">
                                <div class="sm:w-40">
                                    <x-amount-input model="lines.{{ $i }}.foreign_debit" :label="__('Debit').' ('.$lineCurrency.')'" class="text-right" placeholder="0.00" data-test="line-foreign-debit" />
                                </div>
                                <div class="sm:w-40">
                                    <x-amount-input model="lines.{{ $i }}.foreign_credit" :label="__('Credit').' ('.$lineCurrency.')'" class="text-right" placeholder="0.00" data-test="line-foreign-credit" />
                                </div>
                                <div class="sm:w-48">
                                    <flux:input
                                        wire:model.live.blur="lines.{{ $i }}.fx_rate"
                                        :label="__('Exchange rate')"
                                        :description:trailing="$lineRate !== null ? '1 '.$lineCurrency.' = '.$lineRate.' '.$company->currency_code : __('No rate on file for this date. Enter one.')"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        data-test="line-fx-rate"
                                    />
                                </div>
                            </div>
                        @endif

                        {{-- Tier 2: class, location & fund dimensions (only when tracked) --}}
                        @if ($this->tracksClasses || $this->tracksLocations || $this->tracksFunds)
                            <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                                @if ($this->tracksClasses)
                                    <flux:select wire:model="lines.{{ $i }}.class_id" :label="__('Class')" class="sm:w-56" data-test="line-class">
                                        <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                        @foreach ($this->classificationOptions as $opt)
                                            <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @endif
                                @if ($this->tracksLocations)
                                    <flux:select wire:model="lines.{{ $i }}.location_id" :label="__('Location')" class="sm:w-56" data-test="line-location">
                                        <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                        @foreach ($this->locationOptions as $opt)
                                            <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @endif
                                @if ($this->tracksFunds)
                                    <flux:select wire:model="lines.{{ $i }}.fund_id" :label="__('Fund')" class="sm:w-56" data-test="line-fund">
                                        <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                        @foreach ($this->fundOptions as $opt)
                                            <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Totals (desktop): aligned to the debit/credit columns above. --}}
            <div class="{{ $lineGrid }} hidden border-t border-border bg-muted px-3 py-2">
                <div></div>
                <div class="text-right font-medium">{{ __('Totals') }}</div>
                <div class="text-right font-mono" data-test="total-debits">{{ number_format($this->totalDebitsCents / 100, 2) }}</div>
                <div class="text-right font-mono" data-test="total-credits">{{ number_format($this->totalCreditsCents / 100, 2) }}</div>
                <div class="text-muted-foreground" data-test="balance-indicator">
                    @if ($this->differenceCents === 0 && $this->totalDebitsCents > 0)
                        <span class="text-green-600">{{ __('Balanced') }}</span>
                    @elseif ($this->totalDebitsCents === 0 && $this->totalCreditsCents === 0)
                        {{ __('Enter amounts') }}
                    @else
                        <span class="text-amber-600">{{ __('Out of balance by') }} {{ number_format(abs($this->differenceCents) / 100, 2) }}</span>
                    @endif
                </div>
                <div></div>
            </div>

            {{-- Totals (mobile) --}}
            <div class="space-y-1 border-t border-border bg-muted px-3 py-3 lg:hidden">
                <div class="flex justify-between"><span class="font-medium">{{ __('Total debits') }}</span><span class="font-mono" data-test="total-debits-mobile">{{ number_format($this->totalDebitsCents / 100, 2) }}</span></div>
                <div class="flex justify-between"><span class="font-medium">{{ __('Total credits') }}</span><span class="font-mono" data-test="total-credits-mobile">{{ number_format($this->totalCreditsCents / 100, 2) }}</span></div>
                <div class="flex justify-between" data-test="balance-indicator-mobile">
                    <span class="font-medium">{{ __('Balance') }}</span>
                    <span class="text-muted-foreground">
                        @if ($this->differenceCents === 0 && $this->totalDebitsCents > 0)
                            <span class="text-green-600">{{ __('Balanced') }}</span>
                        @elseif ($this->totalDebitsCents === 0 && $this->totalCreditsCents === 0)
                            {{ __('Enter amounts') }}
                        @else
                            <span class="text-amber-600">{{ __('Out of balance by') }} {{ number_format(abs($this->differenceCents) / 100, 2) }}</span>
                        @endif
                    </span>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <flux:button variant="filled" type="button" icon="plus" wire:click="addLine">
                {{ __('Add line') }}
            </flux:button>

            @error('lines')
                <flux:text class="text-red-600">{{ $message }}</flux:text>
            @enderror

            <div class="flex gap-2">
                <flux:button variant="ghost" type="button" icon="document-duplicate" x-on:click="$flux.modal('save-as-je-template').show()" data-test="save-as-je-template-button">{{ __('Save as template') }}</flux:button>
                @if ($isPosted)
                    <flux:button variant="primary" type="submit" data-test="save-changes-button">{{ __('Save changes') }}</flux:button>
                @else
                    <flux:button variant="filled" type="button" wire:click="saveDraft" data-test="save-draft-button">{{ __('Save draft') }}</flux:button>
                    <flux:button variant="primary" type="submit" data-test="post-entry-button">{{ __('Post entry') }}</flux:button>
                @endif
            </div>
        </div>
        @endif
    </form>

    <flux:modal name="save-as-je-template" class="max-w-md">
        <form wire:submit="saveAsTemplate" class="space-y-6">
            <flux:heading size="lg">{{ __('Save as template') }}</flux:heading>
            <flux:text>{{ __('Save the current lines as a reusable template for future journal entries.') }}</flux:text>

            <flux:input wire:model="template_name" :label="__('Template name')" placeholder="{{ __('e.g. Monthly depreciation') }}" required data-test="save-as-je-template-name" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="save-as-je-template-confirm">{{ __('Save template') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-period-lock-override :company="$company" :message="$periodLockMessage" />

    <flux:modal name="backdate-warning" class="max-w-lg" data-test="backdate-warning-modal">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Post to an earlier period?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Check the date before you continue:') }}</flux:text>
            </div>

            <ul class="list-disc space-y-2 ps-5">
                @foreach ($backdateWarnings as $warning)
                    <li><flux:text data-test="backdate-warning">{{ $warning }}</flux:text></li>
                @endforeach
            </ul>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="button" wire:click="confirmBackdated" data-test="backdate-confirm-button">
                    {{ $isPosted ? __('Save anyway') : __('Post anyway') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
    @endif
</section>
