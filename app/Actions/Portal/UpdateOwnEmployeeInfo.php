<?php

namespace App\Actions\Portal;

use App\Actions\Payroll\SaveEmployeePayrollProfile;
use App\Enums\SecurityEvent;
use App\Models\Contact;
use App\Models\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY write path the employee self-service portal exposes. It deliberately
 * does NOT reuse {@see SaveEmployeePayrollProfile}, which can
 * write the SIN, salary, statutory exemptions, recurring items and GL account
 * mappings. An employee may change only their own address (on the Contact) and
 * their own TD1 claim amounts / codes (on the payroll profile) — nothing else —
 * and the action operates strictly on the passed-in authenticated Contact, never
 * on an id taken from the request. Every applied change is recorded to the
 * immutable {@see SecurityLog}.
 */
final class UpdateOwnEmployeeInfo
{
    /** Contact address fields an employee may self-edit. */
    private const ADDRESS_FIELDS = [
        'billing_line1', 'billing_line2', 'billing_city',
        'billing_region', 'billing_postal_code', 'billing_country',
    ];

    /**
     * Apply the whitelisted changes to the employee's own Contact + profile.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Contact $employee, array $data): Contact
    {
        return DB::transaction(function () use ($employee, $data): Contact {
            $addressChanged = [];

            foreach (self::ADDRESS_FIELDS as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $value = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
                $value = ($value === '' || $value === null) ? null : (string) $value;

                if ($employee->{$field} !== $value) {
                    $employee->{$field} = $value;
                    $addressChanged[] = $field;
                }
            }

            $employee->save();

            // TD1 lives on the payroll profile. Claim amounts are clamped to a
            // non-negative integer here as a defensive floor on top of the form's
            // validation; codes are stored as trimmed strings (or null).
            $td1Changes = [];
            $profile = $employee->payrollProfile;

            if ($profile !== null) {
                foreach (['td1_federal_claim_cents', 'td1_provincial_claim_cents'] as $field) {
                    if (! array_key_exists($field, $data)) {
                        continue;
                    }

                    $value = max(0, (int) $data[$field]);

                    if ((int) $profile->{$field} !== $value) {
                        $td1Changes[$field] = ['from' => (int) $profile->{$field}, 'to' => $value];
                        $profile->{$field} = $value;
                    }
                }

                foreach (['td1_federal_code', 'td1_provincial_code'] as $field) {
                    if (! array_key_exists($field, $data)) {
                        continue;
                    }

                    $value = trim((string) $data[$field]);
                    $value = $value === '' ? null : $value;

                    if ($profile->{$field} !== $value) {
                        $td1Changes[$field] = ['from' => $profile->{$field}, 'to' => $value];
                        $profile->{$field} = $value;
                    }
                }

                $profile->save();
            }

            if ($addressChanged !== [] || $td1Changes !== []) {
                SecurityLog::create([
                    'recorded_at' => now(),
                    'user_id' => null,
                    'company_id' => $employee->company_id,
                    'event' => SecurityEvent::EmployeePortalInfoUpdated,
                    'ip_address' => request()->ip(),
                    'user_agent' => mb_substr((string) request()->userAgent(), 0, 500),
                    'metadata' => [
                        'contact_id' => $employee->id,
                        'address_fields_changed' => $addressChanged,
                        'td1_changes' => $td1Changes,
                    ],
                ]);
            }

            return $employee->refresh();
        });
    }
}
