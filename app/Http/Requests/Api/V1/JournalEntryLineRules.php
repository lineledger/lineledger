<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Contact;
use Illuminate\Contracts\Validation\Validator;

/**
 * Shared cross-field validation for journal-entry lines: Accounts Receivable
 * lines require a customer contact and Accounts Payable lines require a vendor
 * contact. Mirrors the Livewire form's validateLineContacts(). Lines with no
 * debit and no credit are ignored (the Action drops them).
 */
final class JournalEntryLineRules
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function validateContacts(Validator $validator, array $lines): void
    {
        $controlAccounts = Account::query()
            ->whereIn('subtype', [
                AccountSubtype::AccountsReceivable->value,
                AccountSubtype::AccountsPayable->value,
            ])
            ->get(['id', 'subtype'])
            ->mapWithKeys(fn (Account $a): array => [
                $a->id => $a->subtype === AccountSubtype::AccountsReceivable ? 'customer' : 'vendor',
            ]);

        foreach ($lines as $i => $line) {
            $debit = (int) ($line['debit_cents'] ?? 0);
            $credit = (int) ($line['credit_cents'] ?? 0);

            if ($debit === 0 && $credit === 0) {
                continue;
            }

            $role = $controlAccounts[(int) ($line['account_id'] ?? 0)] ?? null;

            if ($role === null) {
                continue;
            }

            $contactId = $line['contact_id'] ?? null;
            $column = $role === 'customer' ? 'is_customer' : 'is_vendor';

            $valid = $contactId !== null && Contact::query()
                ->whereKey($contactId)
                ->where($column, true)
                ->exists();

            if (! $valid) {
                $validator->errors()->add(
                    "lines.{$i}.contact_id",
                    $role === 'customer'
                        ? 'Select a valid customer for the Accounts Receivable line.'
                        : 'Select a valid vendor for the Accounts Payable line.',
                );
            }
        }
    }
}
