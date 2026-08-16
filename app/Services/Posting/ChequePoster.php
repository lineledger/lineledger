<?php

namespace App\Services\Posting;

use App\Enums\AuditAction;
use App\Enums\ChequeStatus;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Cheque;
use App\Models\JournalEntry;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Currency\ExchangeRateService;
use App\Services\Tax\TaxPeriodLockGuard;
use App\Support\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a direct-expense cheque (no bill linkage) to the GL.
 *   DR  Expense (per-line, grouped by account, gross-up non-recoverable tax)
 *   DR  Tax Payable (per-agency, recoverable tax = input tax credit)
 *   CR    Bank account
 *
 * To pay an existing bill instead, create a BillPayment with a payment_method_id
 * referencing a cheque-flagged PaymentMethod and the cheque number as reference —
 * that flow is already supported by BillPaymentPoster.
 */
class ChequePoster
{
    use Concerns\PlugsForeignRounding;
    use Concerns\SplitsLineTax;

    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
        protected AccountingAuditRecorder $auditRecorder,
        protected TaxPeriodLockGuard $taxLockGuard,
        protected ExchangeRateService $exchangeRates,
    ) {}

    public function post(Cheque $cheque): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($cheque) {
            $cheque->loadMissing('lines.taxCode.agency', 'lines.secondaryTaxCode.agency', 'bankAccount', 'company');

            if ($cheque->journal_entry_id) {
                throw AlreadyPostedException::for((int) $cheque->journal_entry_id);
            }

            if ($cheque->company->isLockedFor(CarbonImmutable::parse($cheque->cheque_date))) {
                throw PeriodLockedException::for(
                    CarbonImmutable::parse($cheque->cheque_date),
                    CarbonImmutable::parse($cheque->company->lock_date),
                );
            }

            $this->taxLockGuard->ensureNotFiled(
                (int) $cheque->company_id,
                $cheque->lines->pluck('tax_code_id')->all(),
                CarbonImmutable::parse($cheque->cheque_date),
            );

            $cheque->recalculateAmount();

            if ($cheque->lines->isEmpty() || $cheque->amount_cents <= 0) {
                throw new RuntimeException('Cheque has no lines or zero amount; cannot post.');
            }

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($cheque->company),
                'entry_date' => $cheque->cheque_date,
                'memo' => 'Cheque '.$cheque->cheque_no.' — '.$cheque->payee_name,
                'source_type' => Cheque::class,
                'source_id' => $cheque->id,
            ]);

            $this->buildChequeLines($cheque, $entry);

            $entry->refresh();
            $this->journalPoster->post($entry);

            $cheque->forceFill([
                'status' => ChequeStatus::Posted,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $entry->id,
            ])->save();

            // A refund cheque debits Accounts Receivable, so the payee's cached
            // AR balance must be recomputed to match the GL.
            if ($cheque->isRefund()) {
                $cheque->payee?->recomputeArBalance();
            }

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $cheque->company_id,
                AuditAction::ChequePosted,
                $cheque,
                [
                    'cheque_no' => $cheque->cheque_no,
                    'cheque_date' => optional($cheque->cheque_date)->toDateString(),
                    'payee_name' => $cheque->payee_name,
                    'amount_cents' => (int) $cheque->amount_cents,
                    'bank_account_id' => (int) $cheque->bank_account_id,
                    'journal_entry_id' => (int) $entry->id,
                    'journal' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    public function void(Cheque $cheque, ?CarbonImmutable $voidDate = null): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($cheque, $voidDate) {
            $cheque->loadMissing('journalEntry');

            if (! $cheque->journal_entry_id) {
                throw new RuntimeException('Cheque is not posted.');
            }

            if ($cheque->status === ChequeStatus::Void) {
                throw new RuntimeException('Cheque is already voided.');
            }

            $this->journalPoster->void($cheque->journalEntry, $voidDate, "Void of cheque {$cheque->cheque_no}");

            $cheque->forceFill([
                'status' => ChequeStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
            ])->save();

            if ($cheque->isRefund()) {
                $cheque->payee?->recomputeArBalance();
            }

            $this->auditRecorder->record(
                (int) $cheque->company_id,
                AuditAction::ChequeVoided,
                $cheque,
                [
                    'cheque_no' => $cheque->cheque_no,
                    'voided_at' => optional($cheque->voided_at)->format('Y-m-d H:i:s.u'),
                    'journal_entry_id' => (int) $cheque->journal_entry_id,
                ],
                $cheque->journalEntry,
            );
        }));
    }

    /**
     * Write the expense + recoverable-tax debits and the bank credit. The cheque's
     * currency is its bank account's currency: a foreign bank converts every leg to
     * home cents at the cheque-date rate and carries the foreign amount as a memo,
     * with any rounding plugged onto the largest debit leg.
     */
    protected function buildChequeLines(Cheque $cheque, JournalEntry $entry): void
    {
        $bankCurrency = $cheque->bankAccount?->currency_code;
        $isForeign = $bankCurrency !== null && ! $cheque->company->isHomeCurrency($bankCurrency);
        $currency = $isForeign ? mb_strtoupper((string) $bankCurrency) : null;
        $rate = $isForeign ? $this->lockRate($cheque) : '1';

        $totalForeign = (int) $cheque->amount_cents;
        $bankHome = Currency::toHomeCents($totalForeign, $rate);

        /** @var list<array{account_id: int, class_id: ?int, location_id: ?int, foreign: int, home: int, memo: ?string}> $legs */
        $legs = [];

        foreach ($this->expenseByAccount($cheque) as $expense) {
            $legs[] = ['account_id' => $expense['account_id'], 'class_id' => $expense['class_id'], 'location_id' => $expense['location_id'], 'foreign' => $expense['cents'], 'home' => Currency::toHomeCents($expense['cents'], $rate), 'memo' => null];
        }

        foreach ($this->recoverableTaxByPayableAccount($cheque) as $payableAccountId => $foreignCents) {
            if ($foreignCents === 0) {
                continue;
            }

            // Input tax credit is a system/aggregate leg — never dimension-tagged.
            $legs[] = ['account_id' => $payableAccountId, 'class_id' => null, 'location_id' => null, 'foreign' => $foreignCents, 'home' => Currency::toHomeCents($foreignCents, $rate), 'memo' => 'Input tax credit'];
        }

        $this->applyRoundingPlug($legs, $bankHome);

        $order = 0;

        foreach ($legs as $leg) {
            $entry->lines()->create([
                'account_id' => $leg['account_id'],
                'debit_cents' => $leg['home'],
                'credit_cents' => 0,
                'memo' => $leg['memo'],
                'contact_id' => $cheque->payee_contact_id,
                'line_order' => $order++,
                'class_id' => $leg['class_id'],
                'location_id' => $leg['location_id'],
                ...Currency::lineMemo($currency, $rate, $leg['foreign'], 0),
            ]);
        }

        $entry->lines()->create([
            'account_id' => $cheque->bank_account_id,
            'debit_cents' => 0,
            'credit_cents' => $bankHome,
            'memo' => 'Cheque '.$cheque->cheque_no,
            'contact_id' => $cheque->payee_contact_id,
            'line_order' => $order++,
            ...Currency::lineMemo($currency, $rate, 0, $totalForeign),
        ]);

        if ($isForeign) {
            $cheque->forceFill(['fx_rate' => $rate, 'home_amount_cents' => $bankHome])->save();
        }
    }

    protected function lockRate(Cheque $cheque): string
    {
        if ($cheque->fx_rate !== null) {
            return (string) $cheque->fx_rate;
        }

        $rate = $this->exchangeRates->rateFor(
            $cheque->company,
            (string) $cheque->bankAccount?->currency_code,
            CarbonImmutable::parse($cheque->cheque_date),
        );

        $cheque->forceFill(['fx_rate' => $rate])->save();

        return $rate;
    }

    /**
     * @return list<array{account_id: int, class_id: ?int, location_id: ?int, cents: int}>
     */
    protected function expenseByAccount(Cheque $cheque): array
    {
        $grouped = [];

        foreach ($cheque->lines as $line) {
            $cents = (int) $line->amount_cents;

            // Non-recoverable tax: add to expense (gross-up). Each of the line's
            // taxes is grossed up only if it is itself non-recoverable.
            $cents += $this->nonRecoverableTax([
                [$line->taxCode, (int) $line->tax_cents],
                [$line->secondaryTaxCode, (int) $line->secondary_tax_cents],
            ]);

            $key = $line->account_id.':'.($line->class_id ?? '').':'.($line->location_id ?? '');
            $grouped[$key] ??= [
                'account_id' => (int) $line->account_id,
                'class_id' => $line->class_id,
                'location_id' => $line->location_id,
                'cents' => 0,
            ];
            $grouped[$key]['cents'] += $cents;
        }

        return array_values($grouped);
    }

    /**
     * @return array<int, int>
     */
    protected function recoverableTaxByPayableAccount(Cheque $cheque): array
    {
        $grouped = [];

        foreach ($cheque->lines as $line) {
            $this->addTaxesByPayable($grouped, [
                [$line->taxCode, (int) $line->tax_cents],
                [$line->secondaryTaxCode, (int) $line->secondary_tax_cents],
            ], recoverableOnly: true);
        }

        return $grouped;
    }
}
