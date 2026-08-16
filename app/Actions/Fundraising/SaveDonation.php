<?php

namespace App\Actions\Fundraising;

use App\Actions\Charity\SaveDonationReceipt;
use App\Models\Contact;
use App\Models\Donation;
use App\Services\Posting\DocumentNumberGenerator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates or updates a DRAFT donation (posted/void donations are immutable).
 * Generates the serial on create, flags the contact as a donor, and — when asked
 * and the company is a registered charity — spawns a draft official receipt linked
 * back via donation_id (with no debit account, so the issuer posts no GL: the
 * donation already books the revenue).
 *
 * Expected $data shape:
 *   contact_id, gift_type, donation_date, amount_cents, is_restricted, fund_id,
 *   restriction_note, deposit_to_account_id, revenue_account_id, deferred_account_id,
 *   notes, issue_receipt (bool)
 */
final class SaveDonation
{
    public function __construct(
        protected DocumentNumberGenerator $numbers,
        protected SaveDonationReceipt $saveReceipt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Donation $donation = null): Donation
    {
        if ($donation && $donation->exists && ! $donation->isDraft()) {
            throw new InvalidArgumentException('Only draft donations can be edited.');
        }

        $company = app('current_company');

        return DB::transaction(function () use ($data, $donation, $company): Donation {
            $attributes = [
                'contact_id' => $data['contact_id'] ?? null,
                'gift_type' => $data['gift_type'] ?? 'cash',
                'donation_date' => $data['donation_date'],
                'amount_cents' => (int) $data['amount_cents'],
                'is_restricted' => (bool) ($data['is_restricted'] ?? false),
                'fund_id' => $data['fund_id'] ?? null,
                'restriction_note' => $data['restriction_note'] ?? null,
                'deposit_to_account_id' => $data['deposit_to_account_id'] ?? null,
                'revenue_account_id' => $data['revenue_account_id'] ?? null,
                'deferred_account_id' => $data['deferred_account_id'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];

            if ($donation && $donation->exists) {
                $donation->update($attributes);
            } else {
                $donation = Donation::create($attributes + [
                    'donation_no' => $this->numbers->next($company, Donation::class, 'donation_no', 'DON'),
                    'status' => 'draft',
                ]);
            }

            if ($donation->contact_id !== null) {
                Contact::query()->whereKey($donation->contact_id)->update(['is_donor' => true]);
            }

            if (($data['issue_receipt'] ?? false) && $company->isRegisteredCharity() && $donation->donation_receipt_id === null) {
                $this->spawnReceipt($donation);
            }

            return $donation;
        });
    }

    /**
     * Spawn a draft official receipt for the donation. No debit_account_id is set,
     * so the issuer posts no GL — the donation already recorded the revenue.
     */
    protected function spawnReceipt(Donation $donation): void
    {
        $receipt = $this->saveReceipt->handle([
            'contact_id' => $donation->contact_id,
            'gift_type' => $donation->gift_type->value,
            'gift_date' => $donation->donation_date->toDateString(),
            'amount_cents' => $donation->amount_cents,
            'advantage_cents' => 0,
            'revenue_account_id' => $donation->revenue_account_id,
        ]);

        $receipt->forceFill(['donation_id' => $donation->id])->save();
        $donation->forceFill(['donation_receipt_id' => $receipt->id])->save();
    }
}
