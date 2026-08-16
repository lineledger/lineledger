<?php

namespace App\Services\Fundraising;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\AuditAction;
use App\Enums\DonationStatus;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Donation;
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
 * Posts a donation to the GL (home currency).
 *   DR  deposit-to account (amount)
 *   CR    donation revenue                       — unrestricted
 *   CR    deferred / restricted liability        — restricted, ASNPO deferral method
 *   CR    donation revenue tagged with the fund  — restricted, restricted-fund method
 *
 * In-kind donations book the same way; the deposit-to account is the asset (or
 * expense) receiving the gift at fair market value. A spawned official receipt
 * carries no debit account, so the issuer never re-posts the gift (no double count).
 */
class DonationPoster
{
    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
        protected AccountingAuditRecorder $auditRecorder,
    ) {}

    public function post(Donation $donation): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($donation) {
            $donation->loadMissing('company');
            $company = $donation->company;

            if ($donation->journal_entry_id) {
                throw AlreadyPostedException::for((int) $donation->journal_entry_id);
            }

            if ($company->isLockedFor(CarbonImmutable::parse($donation->donation_date))) {
                throw PeriodLockedException::for(
                    CarbonImmutable::parse($donation->donation_date),
                    CarbonImmutable::parse($company->lock_date),
                );
            }

            if ($donation->amount_cents <= 0) {
                throw new RuntimeException('A donation must have an amount greater than zero.');
            }

            if ($donation->deposit_to_account_id === null) {
                throw new RuntimeException('A deposit-to account is required to post a donation.');
            }

            [$creditAccountId, $fundId] = $this->resolveCreditLeg($donation, $company);

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($company),
                'entry_date' => $donation->donation_date,
                'memo' => 'Donation '.$donation->donation_no,
                'source_type' => Donation::class,
                'source_id' => $donation->id,
            ]);

            $entry->lines()->create([
                'account_id' => $donation->deposit_to_account_id,
                'debit_cents' => $donation->amount_cents,
                'credit_cents' => 0,
                'memo' => 'Donation received',
                'contact_id' => $donation->contact_id,
                'line_order' => 0,
            ]);

            $entry->lines()->create([
                'account_id' => $creditAccountId,
                'debit_cents' => 0,
                'credit_cents' => $donation->amount_cents,
                'memo' => $donation->is_restricted ? 'Restricted donation' : 'Donation revenue',
                'contact_id' => $donation->contact_id,
                'fund_id' => $fundId,
                'line_order' => 1,
            ]);

            $entry->refresh();
            $this->journalPoster->post($entry);

            $donation->forceFill([
                'status' => DonationStatus::Posted,
                'revenue_account_id' => $donation->revenue_account_id ?? $creditAccountId,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $entry->id,
            ])->save();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $donation->company_id,
                AuditAction::DonationPosted,
                $donation,
                [
                    'donation_no' => $donation->donation_no,
                    'amount_cents' => (int) $donation->amount_cents,
                    'is_restricted' => (bool) $donation->is_restricted,
                    'journal_entry_id' => (int) $entry->id,
                    'journal' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    public function void(Donation $donation, ?CarbonImmutable $voidDate = null): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($donation, $voidDate) {
            $donation->loadMissing('journalEntry');

            if (! $donation->journal_entry_id) {
                throw new RuntimeException('Donation is not posted.');
            }

            if ($donation->status === DonationStatus::Void) {
                throw new RuntimeException('Donation is already voided.');
            }

            $this->journalPoster->void($donation->journalEntry, $voidDate, "Void of donation {$donation->donation_no}");

            $donation->forceFill([
                'status' => DonationStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
            ])->save();

            $this->auditRecorder->record(
                (int) $donation->company_id,
                AuditAction::DonationVoided,
                $donation,
                [
                    'donation_no' => $donation->donation_no,
                    'journal_entry_id' => (int) $donation->journal_entry_id,
                ],
                $donation->journalEntry,
            );
        }));
    }

    /**
     * Resolve the credit account id and the fund tag for the donation's revenue leg,
     * per restriction and the company's contribution method.
     *
     * @return array{0: int, 1: ?int}
     */
    protected function resolveCreditLeg(Donation $donation, Company $company): array
    {
        if ($donation->is_restricted && $company->usesDeferralMethod()) {
            $deferred = $donation->deferred_account_id ?? $this->defaultDeferredAccountId($company);

            return [$deferred, null];
        }

        $revenue = $donation->revenue_account_id ?? $this->defaultRevenueAccountId($company);
        $fundId = $company->tracksFunds() ? $donation->fund_id : null;

        return [$revenue, $fundId];
    }

    protected function defaultRevenueAccountId(Company $company): int
    {
        $account = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', AccountType::Income->value)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('name', 'like', '%donation%')->orWhere('name', 'like', '%contribution%');
            })
            ->orderBy('code')
            ->first()
            ?? Account::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('type', AccountType::Income->value)
                ->orderBy('code')
                ->first();

        if ($account === null) {
            throw new RuntimeException('No donation revenue account is available to post the donation.');
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
            throw new RuntimeException('No deferred-contribution liability account is available; set one or run fundraising account setup.');
        }

        return $account->id;
    }
}
