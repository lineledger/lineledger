<?php

namespace App\Actions\MasterData;

use App\Actions\Accounting\SaveAccount;
use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\TaxAgency;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a tax agency. Shared by the Livewire tax-codes page and
 * the API.
 *
 * Expected $data shape:
 *   name:                 string
 *   registration_number:  ?string
 *   payable_account_id:   ?int     (omit to auto-create a Tax Payable account)
 *   payable_account_name: ?string  (name for the auto-created account; defaults
 *                                   to "<name> Payable")
 *   is_active:            ?bool
 *
 * When a new agency is saved without a payable account, one is created for it:
 * a Tax Payable account named after the authority, numbered into the 22xx
 * liability band. This keeps the common "add a new tax authority" flow on a
 * single screen instead of forcing a detour to the Chart of Accounts. Updating
 * an existing agency never auto-creates — its account is left as-is unless an
 * explicit id is supplied.
 */
final class SaveTaxAgency
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?TaxAgency $agency = null): TaxAgency
    {
        return DB::transaction(function () use ($data, $agency): TaxAgency {
            $registration = isset($data['registration_number']) ? trim((string) $data['registration_number']) : '';

            $attributes = [
                'name' => $data['name'],
                'registration_number' => $registration !== '' ? $registration : null,
            ];

            if (array_key_exists('is_active', $data)) {
                $attributes['is_active'] = (bool) $data['is_active'];
            }

            if ($agency && $agency->exists) {
                // Only move the agency to a different account when one is supplied;
                // a blank id on update leaves the existing account untouched.
                if (! empty($data['payable_account_id'])) {
                    $attributes['payable_account_id'] = $data['payable_account_id'];
                }

                $agency->update($attributes);

                return $agency;
            }

            $attributes['payable_account_id'] = ! empty($data['payable_account_id'])
                ? $data['payable_account_id']
                : $this->createPayableAccount(
                    $data['payable_account_name'] ?? null,
                    (string) $data['name'],
                )->id;

            return TaxAgency::create($attributes + [
                'is_active' => $data['is_active'] ?? true,
            ]);
        });
    }

    /**
     * Create the Tax Payable account that backs a new agency, named after the
     * authority and numbered into the next free 22xx liability code.
     */
    private function createPayableAccount(?string $accountName, string $agencyName): Account
    {
        $name = $accountName !== null && trim($accountName) !== ''
            ? trim($accountName)
            : $agencyName.' Payable';

        return app(SaveAccount::class)->handle([
            'code' => $this->nextTaxPayableCode(),
            'name' => $name,
            'subtype' => AccountSubtype::TaxPayable->value,
            'is_active' => true,
        ]);
    }

    /**
     * The first unused account code in the tax-payable band: 2210, 2220 … 2290
     * (2200 is the seeded GST/HST / Sales Tax account), then 2211 … 2299, and as
     * a last resort one past the company's highest numeric code. Codes are unique
     * per company, so this is read under the active company scope.
     */
    private function nextTaxPayableCode(): string
    {
        $used = Account::query()->pluck('code')->all();
        $used = array_flip($used);

        for ($code = 2210; $code <= 2290; $code += 10) {
            if (! isset($used[(string) $code])) {
                return (string) $code;
            }
        }

        for ($code = 2211; $code <= 2299; $code++) {
            if (! isset($used[(string) $code])) {
                return (string) $code;
            }
        }

        $numeric = array_filter(array_keys($used), 'is_numeric');

        return (string) (($numeric === [] ? 2200 : max(array_map('intval', $numeric))) + 1);
    }
}
