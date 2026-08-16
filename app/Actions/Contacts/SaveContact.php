<?php

namespace App\Actions\Contacts;

use App\Models\Contact;

/**
 * Creates or updates a Contact in a given role (customer / vendor / employee).
 * Shared by the Livewire directory pages and the API. A contact may hold
 * multiple roles, so updating in one role never clears the others.
 *
 * Expected $data shape:
 *   display_name, company_name, first_name, last_name, email, phone,
 *   tax_number, notes, is_active,
 *   billing_address: {line1,line2,city,region,postal_code,country},
 *   shipping_address: {...},
 *   default_terms_id, default_tax_code_id,
 *   default_income_account_id, default_expense_account_id
 */
final class SaveContact
{
    /**
     * @param  array<string, mixed>  $data
     * @param  'is_customer'|'is_vendor'|'is_employee'  $role
     */
    public function handle(array $data, string $role, ?Contact $contact = null): Contact
    {
        $attributes = [
            'display_name' => $data['display_name'],
            'company_name' => $data['company_name'] ?? null,
            'account_no' => $data['account_no'] ?? null,
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'tax_number' => $data['tax_number'] ?? null,
            'notes' => $data['notes'] ?? null,

            'billing_line1' => $data['billing_address']['line1'] ?? null,
            'billing_line2' => $data['billing_address']['line2'] ?? null,
            'billing_city' => $data['billing_address']['city'] ?? null,
            'billing_region' => $data['billing_address']['region'] ?? null,
            'billing_postal_code' => $data['billing_address']['postal_code'] ?? null,
            'billing_country' => $data['billing_address']['country'] ?? null,

            'shipping_line1' => $data['shipping_address']['line1'] ?? null,
            'shipping_line2' => $data['shipping_address']['line2'] ?? null,
            'shipping_city' => $data['shipping_address']['city'] ?? null,
            'shipping_region' => $data['shipping_address']['region'] ?? null,
            'shipping_postal_code' => $data['shipping_address']['postal_code'] ?? null,
            'shipping_country' => $data['shipping_address']['country'] ?? null,

            'default_terms_id' => $data['default_terms_id'] ?? null,
            'default_tax_code_id' => $data['default_tax_code_id'] ?? null,
            'default_income_account_id' => $data['default_income_account_id'] ?? null,
            'default_expense_account_id' => $data['default_expense_account_id'] ?? null,
        ];

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        // Outbound email consent. Absent means "leave alone" on update, and the
        // column default (off) on create — never silently opt a customer in.
        foreach (['invoice_emails_enabled', 'reminder_emails_enabled'] as $preference) {
            if (array_key_exists($preference, $data)) {
                $attributes[$preference] = (bool) $data[$preference];
            }
        }

        if ($contact && $contact->exists) {
            // A contact transacts in exactly one currency; it can only be changed
            // while the contact still has no transactions on the books.
            if (array_key_exists('currency_code', $data) && $contact->canChangeCurrency()) {
                $attributes['currency_code'] = $this->normalizeCurrency($data['currency_code']);
            }

            $contact->update($attributes + [$role => true]);

            return $contact;
        }

        return Contact::create($attributes + [
            $role => true,
            'is_active' => $data['is_active'] ?? true,
            'currency_code' => $this->normalizeCurrency($data['currency_code'] ?? null),
        ]);
    }

    /**
     * Uppercase the code, or null for the home currency / empty input.
     */
    private function normalizeCurrency(?string $code): ?string
    {
        $code = $code !== null ? mb_strtoupper(trim($code)) : null;

        if ($code === null || $code === '' || app('current_company')->isHomeCurrency($code)) {
            return null;
        }

        return $code;
    }
}
