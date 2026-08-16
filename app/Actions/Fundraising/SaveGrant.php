<?php

namespace App\Actions\Fundraising;

use App\Models\Contact;
use App\Models\Grant;
use App\Services\Posting\DocumentNumberGenerator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates or updates a DRAFT grant (a posted/active grant's structural fields are
 * immutable — edit it via void + recreate). Generates the serial on create and
 * flags the funder contact as a donor.
 *
 * Expected $data shape:
 *   funder_contact_id, name, award_amount_cents, is_restricted, fund_id,
 *   period_start, period_end, receivable_on_award, deposit_to_account_id,
 *   deferred_account_id, revenue_account_id, recognition_method, notes
 */
final class SaveGrant
{
    public function __construct(protected DocumentNumberGenerator $numbers) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Grant $grant = null): Grant
    {
        if ($grant && $grant->exists && ! $grant->isDraft()) {
            throw new InvalidArgumentException('Only draft grants can be edited.');
        }

        $company = app('current_company');

        return DB::transaction(function () use ($data, $grant, $company): Grant {
            $attributes = [
                'funder_contact_id' => $data['funder_contact_id'] ?? null,
                'name' => $data['name'],
                'award_amount_cents' => (int) $data['award_amount_cents'],
                'is_restricted' => (bool) ($data['is_restricted'] ?? true),
                'fund_id' => $data['fund_id'] ?? null,
                'period_start' => $data['period_start'] ?? null,
                'period_end' => $data['period_end'] ?? null,
                'receivable_on_award' => (bool) ($data['receivable_on_award'] ?? false),
                'deposit_to_account_id' => $data['deposit_to_account_id'] ?? null,
                'deferred_account_id' => $data['deferred_account_id'] ?? null,
                'revenue_account_id' => $data['revenue_account_id'] ?? null,
                'recognition_method' => $data['recognition_method'] ?? 'manual',
                'notes' => $data['notes'] ?? null,
            ];

            if ($grant && $grant->exists) {
                $grant->update($attributes);
            } else {
                $grant = Grant::create($attributes + [
                    'grant_no' => $this->numbers->next($company, Grant::class, 'grant_no', 'GR'),
                    'status' => 'draft',
                ]);
            }

            if ($grant->funder_contact_id !== null) {
                Contact::query()->whereKey($grant->funder_contact_id)->update(['is_donor' => true]);
            }

            return $grant;
        });
    }
}
