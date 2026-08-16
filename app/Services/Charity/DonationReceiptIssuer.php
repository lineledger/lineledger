<?php

namespace App\Services\Charity;

use App\Actions\Accounting\SaveJournalEntry;
use App\Actions\Charity\SaveDonationReceipt;
use App\Enums\AccountType;
use App\Enums\AuditAction;
use App\Enums\DonationReceiptStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\DonationReceipt;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Posting\JournalPoster;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Issues, voids, and reissues official donation receipts.
 *
 *  - issue():   draft → issued. Freezes the eligible amount, runs CRA validations,
 *               and (for in-kind gifts) posts DR asset/expense / CR donation
 *               revenue at fair market value. Cash gifts post no GL (the money is
 *               already booked elsewhere) to avoid double-counting revenue.
 *  - void():    issued → void. Retains the serial number (CRA requires voided
 *               receipts be kept) and reverses any in-kind GL entry.
 *  - reissue(): voids the original and returns a fresh draft that references it,
 *               so a corrected receipt carries a new serial linked to the cancelled one.
 */
final class DonationReceiptIssuer
{
    /** CRA appraisal threshold for non-cash gifts: $1,000. */
    private const APPRAISAL_THRESHOLD_CENTS = 100_000;

    public function __construct(
        protected SaveJournalEntry $saveJournalEntry,
        protected JournalPoster $poster,
        protected SaveDonationReceipt $saver,
        protected AccountingAuditRecorder $audit,
    ) {}

    public function issue(DonationReceipt $receipt): DonationReceipt
    {
        if (! $receipt->isDraft()) {
            throw new InvalidArgumentException('Only draft donation receipts can be issued.');
        }

        $company = app('current_company');
        $eligible = $receipt->amount_cents - $receipt->advantage_cents;

        $this->assertReceiptIsIssuable($receipt, $eligible);

        return DB::transaction(function () use ($receipt, $company, $eligible): DonationReceipt {
            $entry = null;

            if ($receipt->isInKind() && $receipt->debit_account_id !== null) {
                $revenueAccountId = $receipt->revenue_account_id ?? $this->defaultDonationAccountId($company);

                $entry = $this->saveJournalEntry->handle([
                    'entry_date' => $receipt->gift_date->toDateString(),
                    'memo' => 'In-kind donation receipt '.$receipt->receipt_no,
                    'lines' => [
                        ['account_id' => $receipt->debit_account_id, 'debit_cents' => $receipt->amount_cents, 'credit_cents' => 0],
                        ['account_id' => $revenueAccountId, 'debit_cents' => 0, 'credit_cents' => $receipt->amount_cents],
                    ],
                ]);
                $this->poster->post($entry);
            }

            $receipt->forceFill([
                'status' => DonationReceiptStatus::Issued,
                'issued_date' => $company->currentDateTime()->toDateString(),
                'eligible_amount_cents' => $eligible,
                'journal_entry_id' => $entry?->id,
                'issued_by_user_id' => Auth::id(),
            ])->save();

            $this->audit->record($company->id, AuditAction::DonationReceiptIssued, $receipt, [
                'receipt_no' => $receipt->receipt_no,
                'eligible_amount_cents' => $eligible,
                'gift_type' => $receipt->gift_type->value,
            ], $entry);

            return $receipt;
        });
    }

    public function void(DonationReceipt $receipt, string $reason): DonationReceipt
    {
        if (! $receipt->isIssued()) {
            throw new InvalidArgumentException('Only issued donation receipts can be voided.');
        }

        return DB::transaction(function () use ($receipt, $reason): DonationReceipt {
            if ($receipt->journal_entry_id !== null && $receipt->journalEntry !== null) {
                $this->poster->void($receipt->journalEntry);
            }

            $receipt->forceFill([
                'status' => DonationReceiptStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
                'void_reason' => $reason,
            ])->save();

            $this->audit->record($receipt->company_id, AuditAction::DonationReceiptVoided, $receipt, [
                'receipt_no' => $receipt->receipt_no,
                'reason' => $reason,
            ]);

            return $receipt;
        });
    }

    public function reissue(DonationReceipt $original): DonationReceipt
    {
        if (! $original->isIssued()) {
            throw new InvalidArgumentException('Only issued donation receipts can be reissued.');
        }

        return DB::transaction(function () use ($original): DonationReceipt {
            $this->void($original, 'Reissued as a corrected receipt.');

            $draft = $this->saver->handle([
                'contact_id' => $original->contact_id,
                'gift_type' => $original->gift_type->value,
                'gift_date' => $original->gift_date->toDateString(),
                'donor_name' => $original->donor_name,
                'donor_line1' => $original->donor_line1,
                'donor_line2' => $original->donor_line2,
                'donor_city' => $original->donor_city,
                'donor_region' => $original->donor_region,
                'donor_postal_code' => $original->donor_postal_code,
                'donor_country' => $original->donor_country,
                'amount_cents' => $original->amount_cents,
                'advantage_cents' => $original->advantage_cents,
                'advantage_description' => $original->advantage_description,
                'in_kind_description' => $original->in_kind_description,
                'appraised_by' => $original->appraised_by,
                'appraisal_date' => $original->appraisal_date?->toDateString(),
                'revenue_account_id' => $original->revenue_account_id,
                'debit_account_id' => $original->debit_account_id,
            ]);

            $draft->forceFill(['reissued_from_id' => $original->id])->save();

            $this->audit->record($original->company_id, AuditAction::DonationReceiptReissued, $draft, [
                'reissued_from_receipt_no' => $original->receipt_no,
                'new_receipt_no' => $draft->receipt_no,
            ]);

            return $draft;
        });
    }

    protected function assertReceiptIsIssuable(DonationReceipt $receipt, int $eligible): void
    {
        if ($eligible <= 0) {
            throw new InvalidArgumentException('The eligible amount must be greater than zero (the advantage cannot equal or exceed the gift).');
        }

        if ($receipt->hasAdvantage() && blank($receipt->advantage_description)) {
            throw new InvalidArgumentException('A description of the advantage is required when an advantage is present.');
        }

        if ($receipt->isInKind()) {
            if (blank($receipt->in_kind_description)) {
                throw new InvalidArgumentException('A description of the property is required for an in-kind receipt.');
            }

            if ($receipt->amount_cents >= self::APPRAISAL_THRESHOLD_CENTS
                && (blank($receipt->appraised_by) || $receipt->appraisal_date === null)) {
                throw new InvalidArgumentException('An appraisal (appraiser and date) is required for an in-kind gift over $1,000.');
            }
        }
    }

    protected function defaultDonationAccountId(Company $company): int
    {
        $account = Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', '4000')->first()
            ?? Account::withoutGlobalScopes()->where('company_id', $company->id)->where('type', AccountType::Income->value)->orderBy('code')->first();

        if ($account === null) {
            throw new InvalidArgumentException('No donation income account is available to post the in-kind gift.');
        }

        return $account->id;
    }
}
