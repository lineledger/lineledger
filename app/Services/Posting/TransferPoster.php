<?php

namespace App\Services\Posting;

use App\Enums\AuditAction;
use App\Enums\TransferStatus;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\JournalEntry;
use App\Models\Transfer;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Currency\ExchangeRateService;
use App\Support\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts an account transfer to the GL.
 *   DR  Destination account (home value of the amount received)
 *   CR  Source account       (home value of the amount sent)
 *   DR/CR Exchange Gain/Loss  (the home-value spread, cross-currency only)
 *
 * A same-currency transfer has from_amount_cents == to_amount_cents at the same
 * rate, so the two legs net to zero and no gain/loss leg is written. A
 * cross-currency transfer converts each side at its own account-currency rate on
 * the transfer date and plugs the residual to the company Exchange Gain/Loss
 * account, mirroring ReceiptPoster / BillPaymentPoster.
 */
class TransferPoster
{
    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
        protected AccountingAuditRecorder $auditRecorder,
        protected ExchangeRateService $exchangeRates,
    ) {}

    public function post(Transfer $transfer): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($transfer) {
            $transfer->loadMissing('fromAccount', 'toAccount', 'company');

            if ($transfer->journal_entry_id) {
                throw AlreadyPostedException::for((int) $transfer->journal_entry_id);
            }

            if ($transfer->company->isLockedFor(CarbonImmutable::parse($transfer->transfer_date))) {
                throw PeriodLockedException::for(
                    CarbonImmutable::parse($transfer->transfer_date),
                    CarbonImmutable::parse($transfer->company->lock_date),
                );
            }

            if ($transfer->from_account_id === $transfer->to_account_id) {
                throw new RuntimeException('A transfer must move money between two different accounts.');
            }

            if ($transfer->from_amount_cents <= 0 || $transfer->to_amount_cents <= 0) {
                throw new RuntimeException('Transfer has a zero amount; cannot post.');
            }

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($transfer->company),
                'entry_date' => $transfer->transfer_date,
                'memo' => 'Transfer '.$transfer->transfer_no,
                'source_type' => Transfer::class,
                'source_id' => $transfer->id,
            ]);

            $this->buildTransferLines($transfer, $entry);

            $entry->refresh();
            $this->journalPoster->post($entry);

            $transfer->forceFill([
                'status' => TransferStatus::Posted,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $entry->id,
            ])->save();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $transfer->company_id,
                AuditAction::TransferPosted,
                $transfer,
                [
                    'transfer_no' => $transfer->transfer_no,
                    'transfer_date' => optional($transfer->transfer_date)->toDateString(),
                    'from_account_id' => (int) $transfer->from_account_id,
                    'to_account_id' => (int) $transfer->to_account_id,
                    'from_amount_cents' => (int) $transfer->from_amount_cents,
                    'to_amount_cents' => (int) $transfer->to_amount_cents,
                    'journal_entry_id' => (int) $entry->id,
                    'journal' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    public function void(Transfer $transfer, ?CarbonImmutable $voidDate = null): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($transfer, $voidDate) {
            $transfer->loadMissing('journalEntry');

            if (! $transfer->journal_entry_id) {
                throw new RuntimeException('Transfer is not posted.');
            }

            if ($transfer->status === TransferStatus::Void) {
                throw new RuntimeException('Transfer is already voided.');
            }

            $this->journalPoster->void($transfer->journalEntry, $voidDate, "Void of transfer {$transfer->transfer_no}");

            $transfer->forceFill([
                'status' => TransferStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
            ])->save();

            $this->auditRecorder->record(
                (int) $transfer->company_id,
                AuditAction::TransferVoided,
                $transfer,
                [
                    'transfer_no' => $transfer->transfer_no,
                    'voided_at' => optional($transfer->voided_at)->format('Y-m-d H:i:s.u'),
                    'journal_entry_id' => (int) $transfer->journal_entry_id,
                ],
                $transfer->journalEntry,
            );
        }));
    }

    /**
     * Debit the destination and credit the source at each side's home value. When
     * the two accounts share a currency at the same rate the legs net out; a
     * cross-currency transfer plugs the home-value spread to Exchange Gain/Loss.
     * Locked rates and the from-leg home value are cached back onto the transfer.
     */
    protected function buildTransferLines(Transfer $transfer, JournalEntry $entry): void
    {
        $company = $transfer->company;

        $fromCur = $transfer->fromAccount?->currency_code;
        $toCur = $transfer->toAccount?->currency_code;

        $fromIsForeign = $fromCur !== null && ! $company->isHomeCurrency($fromCur);
        $toIsForeign = $toCur !== null && ! $company->isHomeCurrency($toCur);

        $fromRate = $fromIsForeign ? $this->lockRate($transfer, 'from', (string) $fromCur) : '1';
        $toRate = $toIsForeign ? $this->lockRate($transfer, 'to', (string) $toCur) : '1';

        $fromForeign = (int) $transfer->from_amount_cents;
        $toForeign = (int) $transfer->to_amount_cents;

        $fromHome = Currency::toHomeCents($fromForeign, $fromRate);
        $toHome = Currency::toHomeCents($toForeign, $toRate);

        $fromCurrency = $fromIsForeign ? mb_strtoupper((string) $fromCur) : null;
        $toCurrency = $toIsForeign ? mb_strtoupper((string) $toCur) : null;

        $order = 0;

        $entry->lines()->create([
            'account_id' => $transfer->to_account_id,
            'debit_cents' => $toHome,
            'credit_cents' => 0,
            'memo' => 'Transfer '.$transfer->transfer_no,
            'line_order' => $order++,
            ...Currency::lineMemo($toCurrency, $toRate, $toForeign, 0),
        ]);

        $entry->lines()->create([
            'account_id' => $transfer->from_account_id,
            'debit_cents' => 0,
            'credit_cents' => $fromHome,
            'memo' => 'Transfer '.$transfer->transfer_no,
            'line_order' => $order++,
            ...Currency::lineMemo($fromCurrency, $fromRate, 0, $fromForeign),
        ]);

        // Cross-currency spread: a positive residual means the destination gained
        // home value (a credit/gain); a negative residual is a debit/loss.
        $residual = $toHome - $fromHome;

        if ($residual !== 0) {
            $entry->lines()->create([
                'account_id' => $this->exchangeGainLossAccountId($transfer),
                'debit_cents' => max(-$residual, 0),
                'credit_cents' => max($residual, 0),
                'memo' => 'Realized exchange '.($residual > 0 ? 'gain' : 'loss'),
                'line_order' => $order++,
            ]);
        }

        $transfer->forceFill([
            'from_currency_code' => $fromCurrency,
            'to_currency_code' => $toCurrency,
            'from_fx_rate' => $fromIsForeign ? $fromRate : null,
            'to_fx_rate' => $toIsForeign ? $toRate : null,
            'home_amount_cents' => $fromHome,
        ])->save();
    }

    /**
     * Resolve (and cache) the home-conversion rate for one side of the transfer.
     */
    protected function lockRate(Transfer $transfer, string $side, string $currency): string
    {
        $field = $side.'_fx_rate';

        if ($transfer->{$field} !== null) {
            return (string) $transfer->{$field};
        }

        return $this->exchangeRates->rateFor(
            $transfer->company,
            $currency,
            CarbonImmutable::parse($transfer->transfer_date),
        );
    }

    protected function exchangeGainLossAccountId(Transfer $transfer): int
    {
        $accountId = $transfer->company->exchange_gain_loss_account_id;

        if ($accountId === null) {
            throw new RuntimeException("Company {$transfer->company_id} has no Exchange Gain/Loss account; enable a foreign currency first.");
        }

        return (int) $accountId;
    }
}
