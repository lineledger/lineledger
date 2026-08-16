<?php

namespace App\Services\Fundraising;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\AuditAction;
use App\Enums\GrantStatus;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Grant;
use App\Models\JournalEntry;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Posting\EntryNumberGenerator;
use App\Services\Posting\JournalPoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a grant award to the GL and reverses it on void.
 *   Deferral method (restricted):  DR deposit/receivable / CR deferred liability (2500)
 *                                   — revenue is recognized later via grant_recognitions.
 *   Restricted-fund method:         DR deposit/receivable [fund] / CR grant revenue [fund]
 *                                   — recognized immediately into the restricted fund.
 *   Unrestricted:                   DR deposit/receivable / CR grant revenue.
 */
class GrantPoster
{
    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
        protected AccountingAuditRecorder $auditRecorder,
    ) {}

    public function postAward(Grant $grant): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($grant) {
            $grant->loadMissing('company');
            $company = $grant->company;

            if ($grant->award_journal_entry_id) {
                throw AlreadyPostedException::for((int) $grant->award_journal_entry_id);
            }

            if ($company->isLockedFor(CarbonImmutable::parse($grant->period_start ?? $company->currentDateTime()->toDateString()))) {
                throw PeriodLockedException::for(
                    CarbonImmutable::parse($grant->period_start ?? $company->currentDateTime()->toDateString()),
                    CarbonImmutable::parse($company->lock_date),
                );
            }

            if ($grant->award_amount_cents <= 0) {
                throw new RuntimeException('A grant award must be greater than zero.');
            }

            if ($grant->deposit_to_account_id === null) {
                throw new RuntimeException('A deposit-to / receivable account is required to post a grant award.');
            }

            $deferred = $grant->is_restricted && $company->usesDeferralMethod();
            $fundId = $company->usesRestrictedFundMethod() ? $grant->fund_id : null;

            $creditAccountId = $deferred
                ? ($grant->deferred_account_id ?? $this->defaultDeferredAccountId($company))
                : ($grant->revenue_account_id ?? $this->defaultRevenueAccountId($company));

            $entryDate = $grant->period_start ?? $company->currentDateTime()->toDateString();

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($company),
                'entry_date' => $entryDate,
                'memo' => 'Grant award '.$grant->grant_no.' — '.$grant->name,
                'source_type' => Grant::class,
                'source_id' => $grant->id,
            ]);

            $entry->lines()->create([
                'account_id' => $grant->deposit_to_account_id,
                'debit_cents' => $grant->award_amount_cents,
                'credit_cents' => 0,
                'memo' => 'Grant received',
                'contact_id' => $grant->funder_contact_id,
                'fund_id' => $fundId,
                'line_order' => 0,
            ]);

            $entry->lines()->create([
                'account_id' => $creditAccountId,
                'debit_cents' => 0,
                'credit_cents' => $grant->award_amount_cents,
                'memo' => $deferred ? 'Deferred grant' : 'Grant revenue',
                'contact_id' => $grant->funder_contact_id,
                'fund_id' => $fundId,
                'line_order' => 1,
            ]);

            $entry->refresh();
            $this->journalPoster->post($entry);

            // Deferred grants start at zero recognized; everything else is recognized
            // in full at award.
            $recognized = $deferred ? 0 : $grant->award_amount_cents;

            $grant->forceFill([
                'status' => $recognized >= $grant->award_amount_cents ? GrantStatus::Completed : GrantStatus::Active,
                'recognized_to_date_cents' => $recognized,
                'revenue_account_id' => $grant->revenue_account_id ?? ($deferred ? null : $creditAccountId),
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'award_journal_entry_id' => $entry->id,
            ])->save();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $grant->company_id,
                AuditAction::GrantPosted,
                $grant,
                [
                    'grant_no' => $grant->grant_no,
                    'award_amount_cents' => (int) $grant->award_amount_cents,
                    'deferred' => $deferred,
                    'journal_entry_id' => (int) $entry->id,
                    'journal' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    public function void(Grant $grant, ?CarbonImmutable $voidDate = null): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($grant, $voidDate) {
            $grant->loadMissing('awardJournalEntry', 'recognitions.journalEntry');

            if (! $grant->award_journal_entry_id) {
                throw new RuntimeException('Grant award is not posted.');
            }

            if ($grant->status === GrantStatus::Void) {
                throw new RuntimeException('Grant is already voided.');
            }

            foreach ($grant->recognitions as $recognition) {
                if ($recognition->journal_entry_id !== null && $recognition->voided_at === null && $recognition->journalEntry !== null) {
                    $this->journalPoster->void($recognition->journalEntry, $voidDate, "Void of grant recognition (grant {$grant->grant_no})");
                    $recognition->forceFill(['voided_at' => now()])->save();
                }
            }

            $this->journalPoster->void($grant->awardJournalEntry, $voidDate, "Void of grant award {$grant->grant_no}");

            $grant->forceFill([
                'status' => GrantStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
            ])->save();

            $this->auditRecorder->record(
                (int) $grant->company_id,
                AuditAction::GrantVoided,
                $grant,
                [
                    'grant_no' => $grant->grant_no,
                    'journal_entry_id' => (int) $grant->award_journal_entry_id,
                ],
                $grant->awardJournalEntry,
            );
        }));
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
            throw new RuntimeException('No grant revenue account is available to post the grant.');
        }

        return $account->id;
    }

    protected function defaultDeferredAccountId(Company $company): int
    {
        $account = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('subtype', AccountSubtype::CurrentLiability->value)
            ->where(function ($q) {
                $q->where('name', 'like', '%deferred%')->orWhere('code', '2500');
            })
            ->orderBy('code')
            ->first();

        if ($account === null) {
            throw new RuntimeException('No deferred-contribution liability account is available; run fundraising account setup.');
        }

        return $account->id;
    }
}
