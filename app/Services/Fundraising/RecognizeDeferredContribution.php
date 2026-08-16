<?php

namespace App\Services\Fundraising;

use App\Enums\AccountType;
use App\Enums\AuditAction;
use App\Enums\GrantStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Grant;
use App\Models\GrantRecognition;
use App\Models\JournalEntry;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Posting\EntryNumberGenerator;
use App\Services\Posting\JournalPoster;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Recognizes deferred grant revenue: DR the deferred liability / CR grant revenue,
 * recording a {@see GrantRecognition} row and advancing the grant's recognized-to-date.
 * Guards that cumulative recognition never exceeds the award. A straight-line helper
 * computes the per-period slice across the grant period.
 */
class RecognizeDeferredContribution
{
    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
        protected AccountingAuditRecorder $auditRecorder,
    ) {}

    public function recognize(Grant $grant, int $amountCents, string $date, ?string $memo = null): GrantRecognition
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($grant, $amountCents, $date, $memo) {
            $grant->loadMissing('company');
            $company = $grant->company;

            if (! $grant->award_journal_entry_id || $grant->status === GrantStatus::Void) {
                throw new RuntimeException('Only a posted, active grant can recognize revenue.');
            }

            if ($amountCents <= 0) {
                throw new RuntimeException('The recognition amount must be greater than zero.');
            }

            if ($grant->recognized_to_date_cents + $amountCents > $grant->award_amount_cents) {
                throw new RuntimeException('Recognition would exceed the grant award.');
            }

            $deferredId = $grant->deferred_account_id ?? $this->defaultDeferredAccountId($company);
            $revenueId = $grant->revenue_account_id ?? $this->defaultRevenueAccountId($company);
            $fundId = $company->usesRestrictedFundMethod() ? $grant->fund_id : null;

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($company),
                'entry_date' => $date,
                'memo' => $memo ?: 'Grant revenue recognized — '.$grant->grant_no,
                'source_type' => Grant::class,
                'source_id' => $grant->id,
            ]);

            $entry->lines()->create([
                'account_id' => $deferredId,
                'debit_cents' => $amountCents,
                'credit_cents' => 0,
                'memo' => 'Release deferred grant',
                'fund_id' => $fundId,
                'line_order' => 0,
            ]);

            $entry->lines()->create([
                'account_id' => $revenueId,
                'debit_cents' => 0,
                'credit_cents' => $amountCents,
                'memo' => 'Grant revenue recognized',
                'fund_id' => $fundId,
                'line_order' => 1,
            ]);

            $entry->refresh();
            $this->journalPoster->post($entry);

            $recognition = GrantRecognition::create([
                'grant_id' => $grant->id,
                'recognition_date' => $date,
                'amount_cents' => $amountCents,
                'memo' => $memo,
                'journal_entry_id' => $entry->id,
            ]);

            $newTotal = $grant->recognized_to_date_cents + $amountCents;

            $grant->forceFill([
                'recognized_to_date_cents' => $newTotal,
                'status' => $newTotal >= $grant->award_amount_cents ? GrantStatus::Completed : GrantStatus::Active,
            ])->save();

            $this->auditRecorder->record(
                (int) $grant->company_id,
                AuditAction::GrantRecognized,
                $grant,
                [
                    'grant_no' => $grant->grant_no,
                    'amount_cents' => $amountCents,
                    'recognized_to_date_cents' => $newTotal,
                    'journal_entry_id' => (int) $entry->id,
                ],
                $entry->fresh(),
            );

            return $recognition;
        }));
    }

    /**
     * Per-period straight-line recognition amount across the grant period, split by
     * whole calendar months. Falls back to the full remaining balance when the
     * period is unset or a single month. Cents remainder lands on the final period.
     */
    public function straightLineAmountCents(Grant $grant): int
    {
        if ($grant->period_start === null || $grant->period_end === null) {
            return $grant->deferredBalanceCents();
        }

        $months = $grant->period_start->diffInMonths($grant->period_end) + 1;
        $months = max(1, (int) $months);

        return intdiv($grant->award_amount_cents, $months);
    }

    protected function defaultRevenueAccountId(Company $company): int
    {
        $account = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', AccountType::Income->value)
            ->where('is_active', true)
            ->where('name', 'like', '%grant%')
            ->orderBy('code')
            ->first()
            ?? Account::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('type', AccountType::Income->value)
                ->orderBy('code')
                ->first();

        if ($account === null) {
            throw new RuntimeException('No grant revenue account is available.');
        }

        return $account->id;
    }

    protected function defaultDeferredAccountId(Company $company): int
    {
        $account = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('name', 'like', '%deferred%')
            ->orderBy('code')
            ->first();

        if ($account === null) {
            throw new RuntimeException('No deferred-contribution liability account is available.');
        }

        return $account->id;
    }
}
