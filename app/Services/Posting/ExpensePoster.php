<?php

namespace App\Services\Posting;

use App\Enums\AuditAction;
use App\Enums\ExpenseStatus;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Expense;
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
 * Posts a pay-now expense (QuickBooks "Expense") to the GL.
 *   DR  Expense (per-line, grouped by account, gross-up non-recoverable tax)
 *   DR  Tax Payable (per-agency, recoverable tax = input tax credit)
 *   CR    Payment account (bank asset OR credit-card liability)
 *
 * Mirrors {@see ChequePoster}; the structural difference is that the credit
 * leg is the chosen payment account (which may be a credit card), and the
 * document carries a payment method for expense-report filtering. Like the
 * cheque, there is no repost path — a posted expense is voided and recreated.
 */
class ExpensePoster
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

    public function post(Expense $expense): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($expense) {
            $expense->loadMissing('lines.taxCode.agency', 'lines.secondaryTaxCode.agency', 'paymentAccount', 'company');

            if ($expense->journal_entry_id) {
                throw AlreadyPostedException::for((int) $expense->journal_entry_id);
            }

            if ($expense->company->isLockedFor(CarbonImmutable::parse($expense->expense_date))) {
                throw PeriodLockedException::for(
                    CarbonImmutable::parse($expense->expense_date),
                    CarbonImmutable::parse($expense->company->lock_date),
                );
            }

            $this->taxLockGuard->ensureNotFiled(
                (int) $expense->company_id,
                $expense->lines->pluck('tax_code_id')->all(),
                CarbonImmutable::parse($expense->expense_date),
            );

            $expense->recalculateAmount();

            if ($expense->lines->isEmpty() || $expense->amount_cents <= 0) {
                throw new RuntimeException('Expense has no lines or zero amount; cannot post.');
            }

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($expense->company),
                'entry_date' => $expense->expense_date,
                'memo' => 'Expense'.($expense->reference ? ' '.$expense->reference : '').' — '.$expense->payee_name,
                'source_type' => Expense::class,
                'source_id' => $expense->id,
            ]);

            $this->buildExpenseLines($expense, $entry);

            $entry->refresh();
            $this->journalPoster->post($entry);

            $expense->forceFill([
                'status' => ExpenseStatus::Posted,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $entry->id,
            ])->save();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $expense->company_id,
                AuditAction::ExpensePosted,
                $expense,
                [
                    'reference' => $expense->reference,
                    'expense_date' => optional($expense->expense_date)->toDateString(),
                    'payee_name' => $expense->payee_name,
                    'amount_cents' => (int) $expense->amount_cents,
                    'payment_account_id' => (int) $expense->payment_account_id,
                    'payment_method_id' => $expense->payment_method_id !== null ? (int) $expense->payment_method_id : null,
                    'journal_entry_id' => (int) $entry->id,
                    'journal' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    public function void(Expense $expense, ?CarbonImmutable $voidDate = null): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($expense, $voidDate) {
            $expense->loadMissing('journalEntry');

            if (! $expense->journal_entry_id) {
                throw new RuntimeException('Expense is not posted.');
            }

            if ($expense->status === ExpenseStatus::Void) {
                throw new RuntimeException('Expense is already voided.');
            }

            $this->journalPoster->void($expense->journalEntry, $voidDate, "Void of expense {$expense->reference}");

            $expense->forceFill([
                'status' => ExpenseStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
            ])->save();

            $this->auditRecorder->record(
                (int) $expense->company_id,
                AuditAction::ExpenseVoided,
                $expense,
                [
                    'reference' => $expense->reference,
                    'voided_at' => optional($expense->voided_at)->format('Y-m-d H:i:s.u'),
                    'journal_entry_id' => (int) $expense->journal_entry_id,
                ],
                $expense->journalEntry,
            );
        }));
    }

    /**
     * Write the expense + recoverable-tax debits and the payment-account credit.
     * The expense's currency is its payment account's currency: a foreign account
     * converts every leg to home cents at the expense-date rate and carries the
     * foreign amount as a memo, with any rounding plugged onto the largest leg.
     */
    protected function buildExpenseLines(Expense $expense, JournalEntry $entry): void
    {
        $payCurrency = $expense->paymentAccount?->currency_code;
        $isForeign = $payCurrency !== null && ! $expense->company->isHomeCurrency($payCurrency);
        $currency = $isForeign ? mb_strtoupper((string) $payCurrency) : null;
        $rate = $isForeign ? $this->lockRate($expense) : '1';

        $totalForeign = (int) $expense->amount_cents;
        $payHome = Currency::toHomeCents($totalForeign, $rate);

        /** @var list<array{account_id: int, class_id: ?int, location_id: ?int, foreign: int, home: int, memo: ?string}> $legs */
        $legs = [];

        foreach ($this->expenseByAccount($expense) as $leg) {
            $legs[] = ['account_id' => $leg['account_id'], 'class_id' => $leg['class_id'], 'location_id' => $leg['location_id'], 'foreign' => $leg['cents'], 'home' => Currency::toHomeCents($leg['cents'], $rate), 'memo' => null];
        }

        foreach ($this->recoverableTaxByPayableAccount($expense) as $payableAccountId => $foreignCents) {
            if ($foreignCents === 0) {
                continue;
            }

            // Input tax credit is a system/aggregate leg — never dimension-tagged.
            $legs[] = ['account_id' => $payableAccountId, 'class_id' => null, 'location_id' => null, 'foreign' => $foreignCents, 'home' => Currency::toHomeCents($foreignCents, $rate), 'memo' => 'Input tax credit'];
        }

        $this->applyRoundingPlug($legs, $payHome);

        $order = 0;

        foreach ($legs as $leg) {
            $entry->lines()->create([
                'account_id' => $leg['account_id'],
                'debit_cents' => $leg['home'],
                'credit_cents' => 0,
                'memo' => $leg['memo'],
                'contact_id' => $expense->payee_contact_id,
                'line_order' => $order++,
                'class_id' => $leg['class_id'],
                'location_id' => $leg['location_id'],
                ...Currency::lineMemo($currency, $rate, $leg['foreign'], 0),
            ]);
        }

        $entry->lines()->create([
            'account_id' => $expense->payment_account_id,
            'debit_cents' => 0,
            'credit_cents' => $payHome,
            'memo' => $expense->reference ? 'Expense '.$expense->reference : 'Expense',
            'contact_id' => $expense->payee_contact_id,
            'line_order' => $order++,
            ...Currency::lineMemo($currency, $rate, 0, $totalForeign),
        ]);

        if ($isForeign) {
            $expense->forceFill(['fx_rate' => $rate, 'home_amount_cents' => $payHome])->save();
        }
    }

    protected function lockRate(Expense $expense): string
    {
        if ($expense->fx_rate !== null) {
            return (string) $expense->fx_rate;
        }

        $rate = $this->exchangeRates->rateFor(
            $expense->company,
            (string) $expense->paymentAccount?->currency_code,
            CarbonImmutable::parse($expense->expense_date),
        );

        $expense->forceFill(['fx_rate' => $rate])->save();

        return $rate;
    }

    /**
     * @return list<array{account_id: int, class_id: ?int, location_id: ?int, cents: int}>
     */
    protected function expenseByAccount(Expense $expense): array
    {
        $grouped = [];

        foreach ($expense->lines as $line) {
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
    protected function recoverableTaxByPayableAccount(Expense $expense): array
    {
        $grouped = [];

        foreach ($expense->lines as $line) {
            $this->addTaxesByPayable($grouped, [
                [$line->taxCode, (int) $line->tax_cents],
                [$line->secondaryTaxCode, (int) $line->secondary_tax_cents],
            ], recoverableOnly: true);
        }

        return $grouped;
    }
}
