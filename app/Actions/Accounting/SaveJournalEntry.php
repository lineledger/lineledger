<?php

namespace App\Actions\Accounting;

use App\Exceptions\Posting\LinkedJournalEntryException;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\Currency\ExchangeRateService;
use App\Services\Currency\MissingExchangeRateException;
use App\Services\Posting\EntryNumberGenerator;
use App\Services\Posting\JournalPoster;
use App\Support\Accounting\ForeignJournalLines;
use App\Support\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Builds or rebuilds a journal entry header and its lines. Shared by the
 * Livewire journal form, the API, the MCP write tools and the generators
 * (recurring entries, depreciation, …). Does NOT post — balance enforcement,
 * period locking and the draft → posted flip stay in JournalPoster.
 *
 * Lines with nothing on them (no home and no foreign amount) are dropped,
 * matching the form.
 *
 * Expected $data shape (cents-based, framework-agnostic):
 *   entry_no:   ?string  (null → auto-generated JE-xxxxxx)
 *   entry_date: string
 *   memo:       ?string
 *   lines: array<int, array{
 *     account_id: int, debit_cents?: int, credit_cents?: int,
 *     memo: ?string, contact_id: ?int, tax_code_id: ?int,
 *     class_id: ?int, location_id: ?int, fund_id: ?int,
 *     currency_code?: ?string, fx_rate?: ?string,
 *     foreign_debit_cents?: ?int, foreign_credit_cents?: ?int
 *   }>
 *
 * tax_code_id is a reporting tag only — no tax amounts are derived from it.
 *
 * debit_cents / credit_cents are HOME cents, and the entry balances in home. A
 * line on a foreign-currency account (foreign bank or credit card, per-currency
 * AR/AP) also records its foreign amount, rate and currency — always the
 * account's own — and any two of home, foreign and rate give the third; a
 * missing rate is the one in force on the entry date. See
 * {@see ForeignJournalLines}. So a caller that only knows the home amount still
 * leaves foreign units on the account, and the period-end revaluation only ever
 * revalues real foreign exposure. Nothing is worked out for a line on a
 * home-currency account; it keeps a memo only when one comes complete (the kind
 * a foreign document puts on every leg, copied by a reversal). Anything
 * inconsistent is a ValidationException keyed to the line field
 * (lines.N.fx_rate, …).
 *
 * Every line must be on one of the current organization's accounts, checked
 * here as well as at posting, because this also rewrites a posted entry in place.
 */
final class SaveJournalEntry
{
    public function __construct(
        protected EntryNumberGenerator $numbers,
        protected JournalPoster $poster,
        protected ExchangeRateService $exchangeRates,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data, ?JournalEntry $entry = null): JournalEntry
    {
        return DB::transaction(function () use ($data, $entry): JournalEntry {
            $company = app('current_company');
            $entryDate = CarbonImmutable::parse($data['entry_date'])->toDateString();
            $lines = array_values($data['lines']);
            $amounts = $this->resolveLines($lines, $entryDate);

            $header = [
                'entry_date' => $entryDate,
                'memo' => $data['memo'] ?? null,
            ];

            if ($entry && $entry->exists) {
                if (! $entry->isManualEntry()) {
                    throw LinkedJournalEntryException::for($entry);
                }
                if (! empty($data['entry_no'])) {
                    $header['entry_no'] = $data['entry_no'];
                }
                $entry->update($header);
            } else {
                $entry = JournalEntry::create($header + [
                    'entry_no' => $data['entry_no'] ?? $this->numbers->next($company),
                ]);
            }

            $entry->lines()->delete();

            $order = 0;
            foreach ($lines as $i => $line) {
                $money = $amounts[$i];

                if ($money['debit_cents'] === 0 && $money['credit_cents'] === 0
                    && $money['foreign_debit_cents'] === 0 && $money['foreign_credit_cents'] === 0) {
                    continue;
                }

                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'contact_id' => $line['contact_id'] ?? null,
                    'debit_cents' => $money['debit_cents'],
                    'credit_cents' => $money['credit_cents'],
                    'memo' => $line['memo'] ?? null,
                    'tax_code_id' => $line['tax_code_id'] ?? null,
                    'line_order' => $order++,
                    'class_id' => $line['class_id'] ?? null,
                    'location_id' => $line['location_id'] ?? null,
                    'fund_id' => $line['fund_id'] ?? null,
                    ...Currency::lineMemo(
                        $money['currency_code'],
                        $money['fx_rate'],
                        $money['foreign_debit_cents'],
                        $money['foreign_credit_cents'],
                    ),
                ]);
            }

            $this->poster->ensureLineAccountsInCompany($entry);

            $entry->refresh();

            return $entry;
        });
    }

    /**
     * Each line's home amounts and foreign memo as {@see handle()} would write
     * them, in the order given, without writing anything — for callers that
     * must check the entry balances first (the API's in-place edit of a posted
     * entry). Index i of the result is line i of $lines.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<array{debit_cents: int, credit_cents: int, currency_code: ?string, fx_rate: ?string, foreign_debit_cents: int, foreign_credit_cents: int, derived: bool}>
     *
     * @throws ValidationException
     */
    public function resolveLines(array $lines, string $entryDate): array
    {
        $company = app('current_company');
        assert($company instanceof Company);

        $lines = array_values($lines);
        $currencies = $this->foreignCurrencies($company, $lines);
        $date = CarbonImmutable::parse($entryDate);

        /** @var array<string, ?string> $rates */
        $rates = [];

        $result = ForeignJournalLines::resolve(
            array_map(function (array $line) use ($company, $currencies): array {
                $code = $line['currency_code'] ?? null;

                return [
                    ...$line,
                    'currency' => $currencies[(int) ($line['account_id'] ?? 0)] ?? null,
                    // The home currency named on a line is the same as naming none.
                    'currency_code' => is_string($code) && ! $company->isHomeCurrency(trim($code)) ? $code : null,
                ];
            }, $lines),
            function (string $currency) use ($company, $date, &$rates): ?string {
                if (! array_key_exists($currency, $rates)) {
                    $rates[$currency] = $this->rateOnFile($company, $currency, $date);
                }

                return $rates[$currency];
            },
        );

        if ($result['errors'] !== []) {
            throw ValidationException::withMessages($result['errors']);
        }

        return $result['lines'];
    }

    /**
     * account_id => currency code for the lines' foreign-currency accounts.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<int, string>
     */
    private function foreignCurrencies(Company $company, array $lines): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn (array $line): int => (int) ($line['account_id'] ?? 0),
            $lines,
        ))));

        if ($ids === []) {
            return [];
        }

        return Account::query()
            ->withTrashed()
            ->whereIn('id', $ids)
            ->whereNotNull('currency_code')
            ->pluck('currency_code', 'id')
            ->reject(fn ($code): bool => $company->isHomeCurrency((string) $code))
            ->map(fn ($code): string => mb_strtoupper((string) $code))
            ->all();
    }

    /**
     * The rate the documents would lock for this date — manual, then fetched —
     * or null when none can be found (the line then has to name its rate).
     */
    private function rateOnFile(Company $company, string $currency, CarbonImmutable $date): ?string
    {
        try {
            return $this->exchangeRates->rateFor($company, $currency, $date);
        } catch (MissingExchangeRateException) {
            return null;
        } catch (HttpClientException $e) {
            report($e);

            return null;
        }
    }
}
