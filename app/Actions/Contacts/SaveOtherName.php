<?php

namespace App\Actions\Contacts;

use App\Models\Contact;
use Illuminate\Validation\ValidationException;

/**
 * Creates or renames an "Other name" — a QuickBooks-style one-time payee that
 * is not a customer, vendor or employee. Shared by the Settings → Lists page
 * and (create only) the payee picker on cheques and expenses.
 *
 * Creation goes through SaveContact so every contact is born the same way.
 * Updates deliberately do NOT: SaveContact rebuilds every profile column from
 * its payload (absent => null), which would wipe the email / addresses /
 * defaults of a contact that also carries another role. Only the fields this
 * list edits are touched.
 *
 * Expected $data shape: display_name, notes?, is_active?
 */
final class SaveOtherName
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Contact $contact = null): Contact
    {
        $notes = trim((string) ($data['notes'] ?? ''));

        if ($contact && $contact->exists) {
            if (app()->bound('current_company') && $contact->company_id !== app('current_company')->id) {
                throw ValidationException::withMessages([
                    'display_name' => __('That contact belongs to another company.'),
                ]);
            }

            // The Other names list is gated by Section::Lists alone, so this
            // must never become a back door for renaming or deactivating a
            // vendor / customer / employee the caller cannot otherwise reach.
            if (! $contact->is_other_name) {
                throw ValidationException::withMessages([
                    'display_name' => __('Only an Other name can be edited here.'),
                ]);
            }

            $attributes = ['display_name' => $data['display_name']];

            if (array_key_exists('notes', $data)) {
                $attributes['notes'] = $notes !== '' ? $notes : null;
            }

            if (array_key_exists('is_active', $data)) {
                $attributes['is_active'] = (bool) $data['is_active'];
            }

            $contact->update($attributes);

            return $contact;
        }

        return app(SaveContact::class)->handle([
            'display_name' => $data['display_name'],
            'notes' => $notes !== '' ? $notes : null,
            'is_active' => $data['is_active'] ?? true,
        ], 'is_other_name');
    }
}
