<?php

namespace App\Actions\Charity;

use App\Models\Contact;
use App\Models\DonationReceipt;
use App\Services\Posting\DocumentNumberGenerator;
use InvalidArgumentException;

/**
 * Creates or updates a DRAFT donation receipt. Generates the serial number on
 * create, snapshots the donor from the contact when one is chosen, and freezes
 * the eligible amount (= fair market value − advantage). Issued/void receipts are
 * immutable — only drafts may be edited.
 */
final class SaveDonationReceipt
{
    public function __construct(protected DocumentNumberGenerator $numbers) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?DonationReceipt $receipt = null): DonationReceipt
    {
        if ($receipt && $receipt->exists && ! $receipt->isDraft()) {
            throw new InvalidArgumentException('Only draft donation receipts can be edited.');
        }

        $company = app('current_company');

        $amount = (int) $data['amount_cents'];
        $advantage = (int) ($data['advantage_cents'] ?? 0);

        $attributes = [
            'contact_id' => $data['contact_id'] ?? null,
            'gift_type' => $data['gift_type'] ?? 'cash',
            'gift_date' => $data['gift_date'],
            'amount_cents' => $amount,
            'advantage_cents' => $advantage,
            'eligible_amount_cents' => $amount - $advantage,
            'advantage_description' => $data['advantage_description'] ?? null,
            'in_kind_description' => $data['in_kind_description'] ?? null,
            'appraised_by' => $data['appraised_by'] ?? null,
            'appraisal_date' => $data['appraisal_date'] ?? null,
            'currency_code' => $data['currency_code'] ?? null,
            'revenue_account_id' => $data['revenue_account_id'] ?? null,
            'debit_account_id' => $data['debit_account_id'] ?? null,
            'customer_receipt_id' => $data['customer_receipt_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ] + $this->donorSnapshot($data);

        if ($receipt && $receipt->exists) {
            $receipt->update($attributes);

            return $receipt;
        }

        return DonationReceipt::create($attributes + [
            'receipt_no' => $this->numbers->next($company, DonationReceipt::class, 'receipt_no', 'DR'),
            'status' => 'draft',
        ]);
    }

    /**
     * Build the donor name/address snapshot, defaulting from the linked contact
     * when explicit values aren't supplied.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, ?string>
     */
    protected function donorSnapshot(array $data): array
    {
        $contact = isset($data['contact_id']) && $data['contact_id'] !== null
            ? Contact::query()->find($data['contact_id'])
            : null;

        return [
            'donor_name' => $data['donor_name'] ?? $contact?->display_name ?? '',
            'donor_line1' => $data['donor_line1'] ?? $contact?->billing_line1,
            'donor_line2' => $data['donor_line2'] ?? $contact?->billing_line2,
            'donor_city' => $data['donor_city'] ?? $contact?->billing_city,
            'donor_region' => $data['donor_region'] ?? $contact?->billing_region,
            'donor_postal_code' => $data['donor_postal_code'] ?? $contact?->billing_postal_code,
            'donor_country' => $data['donor_country'] ?? $contact?->billing_country,
        ];
    }
}
