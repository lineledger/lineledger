<?php

namespace App\Services\Posting;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\CurrencyRevaluation;
use App\Models\JournalEntry;
use App\Services\Audit\AuditMute;
use App\Services\Currency\ExchangeRateService;
use App\Support\Currency;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Period-end Home Currency Adjustment.
 *
 * Open foreign monetary balances (foreign AR/AP control, foreign bank/credit) are
 * carried in home cents at the rates in force when each transaction posted. At
 * period end their home value no longer matches the foreign balance at the
 * current rate; the difference is an UNREALIZED gain/loss. This posts one balanced
 * entry that revalues each account to the closing rate against the Unrealized
 * Gain/Loss account, plus an auto-reversing entry dated the next day (the estimate
 * is backed out so it never compounds with the next period's revaluation or with
 * the realized gain/loss booked on eventual settlement).
 */
class CurrencyRevaluationService
{
    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
        protected ExchangeRateService $exchangeRates,
    ) {}

    /**
     * Revalue open foreign balances as of $asOf. Returns the revaluation record,
     * or null when nothing needed adjusting. $rateOverrides maps currency code to
     * a closing rate; any currency not present is resolved from the rate service.
     *
     * @param  array<string, string>  $rateOverrides
     */
    public function revalue(Company $company, CarbonImmutable $asOf, array $rateOverrides = []): ?CurrencyRevaluation
    {
        if (! $company->isMulticurrencyEnabled()) {
            return null;
        }

        $existing = CurrencyRevaluation::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereDate('as_of_date', $asOf->toDateString())
            ->exists();

        if ($existing) {
            throw new DomainException("A currency revaluation already exists for {$asOf->toDateString()}.");
        }

        $unrealizedId = $company->unrealized_gain_loss_account_id;

        if ($unrealizedId === null) {
            throw new DomainException('Company has no Unrealized Gain/Loss account; enable a foreign currency first.');
        }

        return DB::transaction(fn () => AuditMute::silence(function () use ($company, $asOf, $rateOverrides, $unrealizedId): ?CurrencyRevaluation {
            $accounts = Account::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereNotNull('currency_code')
                ->whereIn('subtype', [
                    AccountSubtype::Bank->value,
                    AccountSubtype::CreditCard->value,
                    AccountSubtype::AccountsReceivable->value,
                    AccountSubtype::AccountsPayable->value,
                ])
                ->get();

            /** @var array<int, int> $adjustments  account_id => signed home-cents adjustment */
            $adjustments = [];
            $snapshot = [];

            foreach ($accounts as $account) {
                $foreignBalance = $this->rawSumAsOf($account->id, $asOf, 'foreign_debit_cents - foreign_credit_cents');

                if ($foreignBalance === 0) {
                    continue;
                }

                $rate = $rateOverrides[mb_strtoupper((string) $account->currency_code)]
                    ?? $this->exchangeRates->rateFor($company, (string) $account->currency_code, $asOf);

                $carryingHome = $this->rawSumAsOf($account->id, $asOf, 'debit_cents - credit_cents');
                $adjustment = Currency::toHomeCents($foreignBalance, $rate) - $carryingHome;

                if ($adjustment !== 0) {
                    $adjustments[$account->id] = $adjustment;
                    $snapshot[mb_strtoupper((string) $account->currency_code)] = $rate;
                }
            }

            if ($adjustments === []) {
                return null;
            }

            $revaluation = CurrencyRevaluation::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'as_of_date' => $asOf->toDateString(),
                'rate_snapshot' => $snapshot,
            ]);

            $entry = $this->postAdjustmentEntry($company, $revaluation, $asOf, $adjustments, (int) $unrealizedId, reverse: false);
            $reversal = $this->postAdjustmentEntry($company, $revaluation, $asOf->addDay(), $adjustments, (int) $unrealizedId, reverse: true);

            $revaluation->forceFill([
                'journal_entry_id' => $entry->id,
                'reversal_entry_id' => $reversal->id,
            ])->save();

            return $revaluation;
        }));
    }

    /**
     * Build and post one balanced revaluation entry. Each account line nudges the
     * account's raw (debit − credit) balance by its adjustment; the net offsets to
     * Unrealized Gain/Loss. When $reverse is true every side is flipped (the next-
     * day backout). No foreign units move, so the foreign memo columns stay zero.
     *
     * @param  array<int, int>  $adjustments
     */
    private function postAdjustmentEntry(Company $company, CurrencyRevaluation $revaluation, CarbonImmutable $date, array $adjustments, int $unrealizedId, bool $reverse): JournalEntry
    {
        $sign = $reverse ? -1 : 1;
        $memo = ($reverse ? 'Reversal — ' : '').'Home currency adjustment '.$revaluation->as_of_date->toDateString();

        $entry = JournalEntry::create([
            'entry_no' => $this->entryNumbers->next($company),
            'entry_date' => $date->toDateString(),
            'memo' => $memo,
            'source_type' => CurrencyRevaluation::class,
            'source_id' => $revaluation->id,
        ]);

        $order = 0;
        $total = 0;

        foreach ($adjustments as $accountId => $adjustment) {
            $value = $sign * $adjustment;
            $total += $value;

            $entry->lines()->create([
                'account_id' => $accountId,
                'debit_cents' => max($value, 0),
                'credit_cents' => max(-$value, 0),
                'memo' => 'Unrealized FX revaluation',
                'line_order' => $order++,
            ]);
        }

        if ($total !== 0) {
            $entry->lines()->create([
                'account_id' => $unrealizedId,
                'debit_cents' => max(-$total, 0),
                'credit_cents' => max($total, 0),
                'memo' => 'Unrealized exchange gain/loss',
                'line_order' => $order++,
            ]);
        }

        $entry->refresh();
        $this->journalPoster->post($entry);

        return $entry;
    }

    private function rawSumAsOf(int $accountId, CarbonImmutable $asOf, string $expression): int
    {
        return (int) DB::table('journal_lines')
            ->where('account_id', $accountId)
            ->where('is_posted', true)
            ->where('entry_date', '<=', $asOf->toDateString())
            ->sum(DB::raw($expression));
    }
}
