<?php

namespace App\Actions\Contacts;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Promotes an "Other name" to a directory role — QuickBooks' one-way "change
 * type". The contact keeps its id, so every cheque, expense and journal line
 * already written to it stays linked; it simply moves from the Other names
 * list to the Vendors / Customers / Employees page. The contact.updated audit
 * row is written by AuditableObserver.
 */
final class ConvertOtherName
{
    public const ROLES = ['is_customer', 'is_vendor', 'is_employee'];

    /**
     * @param  string  $role  one of self::ROLES — validated at runtime because the
     *                        value arrives from the UI, not a typed call site
     */
    public function handle(Contact $contact, string $role): Contact
    {
        if (! in_array($role, self::ROLES, true)) {
            throw ValidationException::withMessages([
                'convert' => __('Choose vendor, customer or employee.'),
            ]);
        }

        if (! $contact->is_other_name) {
            throw ValidationException::withMessages([
                'convert' => __('Only an Other name can be converted.'),
            ]);
        }

        if (app()->bound('current_company') && $contact->company_id !== app('current_company')->id) {
            throw ValidationException::withMessages([
                'convert' => __('That contact belongs to another company.'),
            ]);
        }

        return DB::transaction(function () use ($contact, $role): Contact {
            $contact->update([$role => true, 'is_other_name' => false]);

            return $contact;
        });
    }
}
