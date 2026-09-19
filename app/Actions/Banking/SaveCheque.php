<?php

namespace App\Actions\Banking;

use App\Actions\Charity\SaveDonationReceipt;
use App\Enums\ChequeStatus;
use App\Models\Cheque;
use App\Models\Contact;
use App\Models\TaxCode;
use App\Support\Accounting\ControlAccountRoles;
use Illuminate\Support\Facades\DB;

/**
 * Builds or updates an expense cheque header and its line items, recalculating
 * the cheque amount. Shared by the Livewire form and the API. Does NOT post —
 * the caller decides whether to hand the result to ChequePoster.
 *
 * Drafts are rebuilt in place; a posted cheque is rebuilt here too and its GL
 * entry brought back into step by ChequePoster::repost(). Only a voided cheque
 * is frozen.
 *
 * Expected $data shape (cents-based, framework-agnostic):
 *   bank_account_id:  int
 *   cheque_no:        string
 *   cheque_date:      string
 *   payee_contact_id: ?int
 *   payee_name:       ?string  (null → resolved from payee contact)
 *   payee_address:    ?array{line1,line2,city,region,postal_code,country}
 *   memo:             ?string
 *   lines: array<int, array{
 *     account_id: int, contact_id: ?int, description: ?string, amount_cents: int,
 *     tax_code_id: ?int, tax_override_cents: ?int, secondary_tax_code_id: ?int,
 *     secondary_tax_override_cents: ?int, class_id: ?int, location_id: ?int
 *   }>
 *
 * contact_id is the customer (Accounts Receivable line) or vendor (Accounts
 * Payable line) whose sub-ledger the line moves — distinct from the header
 * payee, and what ChequePoster stamps on that GL leg.
 *
 * tax_override_cents, when non-null, is the exact tax the user typed and wins
 * over the tax code's computed amount.
 *
 * Every tax key is ignored on a line coded to an AR/AP control account: that
 * balance already includes the tax its originating invoice or bill recorded, so
 * taxing the settlement again double-counts it. Stripped here rather than in the
 * form so the API and any future caller are covered by the same rule
 * ({@see ControlAccountRoles::excludesTax()}).
 */
final class SaveCheque
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Cheque $cheque = null): Cheque
    {
        return DB::transaction(function () use ($data, $cheque): Cheque {
            $payeeName = $data['payee_name'] ?? null;

            if (! $payeeName && ! empty($data['payee_contact_id'])) {
                $payeeName = Contact::find($data['payee_contact_id'])?->display_name;
            }

            $header = [
                ...$this->payeeAddress($data),
                'bank_account_id' => $data['bank_account_id'],
                'cheque_no' => $data['cheque_no'],
                'cheque_date' => $data['cheque_date'],
                'payee_contact_id' => $data['payee_contact_id'] ?? null,
                'payee_name' => $payeeName,
                'memo' => $data['memo'] ?? null,
            ];

            if ($cheque && $cheque->exists) {
                $cheque->update($header);
            } else {
                $cheque = Cheque::create($header + [
                    'status' => ChequeStatus::Draft,
                ]);
            }

            $cheque->lines()->delete();

            // Hoisted: a two-query lookup, not one per line.
            $controlAccounts = ControlAccountRoles::map();

            foreach (array_values($data['lines']) as $index => $line) {
                $amountCents = (int) $line['amount_cents'];
                $excludesTax = ControlAccountRoles::excludesTax($controlAccounts, $line['account_id'] ?? null);

                // A receivable or payable already carries the tax its invoice or bill
                // recorded, so every tax input on such a line is dropped up front.
                $taxCodeId = $excludesTax ? null : ($line['tax_code_id'] ?? null);
                $secondaryTaxCodeId = $excludesTax ? null : ($line['secondary_tax_code_id'] ?? null);
                $override = $excludesTax ? null : ($line['tax_override_cents'] ?? null);
                $secondaryOverride = $excludesTax ? null : ($line['secondary_tax_override_cents'] ?? null);

                $taxCode = $taxCodeId !== null
                    ? TaxCode::withoutGlobalScopes()->where('company_id', app('current_company')->id)->find($taxCodeId)
                    : null;

                $secondaryTaxCode = $secondaryTaxCodeId !== null
                    ? TaxCode::withoutGlobalScopes()->where('company_id', app('current_company')->id)->find($secondaryTaxCodeId)
                    : null;

                $taxCents = $override !== null
                    ? (int) $override
                    : ($taxCode ? $taxCode->taxFor($amountCents) : 0);

                $secondaryTaxCents = $secondaryOverride !== null
                    ? (int) $secondaryOverride
                    : ($secondaryTaxCode ? $secondaryTaxCode->taxFor($amountCents) : 0);

                $cheque->lines()->create([
                    'account_id' => $line['account_id'],
                    'contact_id' => $line['contact_id'] ?? null,
                    'description' => $line['description'] ?? null,
                    'amount_cents' => $amountCents,
                    'tax_code_id' => $taxCode?->id,
                    'tax_cents' => $taxCents,
                    'tax_override_cents' => $override !== null ? (int) $override : null,
                    'secondary_tax_code_id' => $secondaryTaxCode?->id,
                    'secondary_tax_cents' => $secondaryTaxCents,
                    'secondary_tax_override_cents' => $secondaryOverride !== null ? (int) $secondaryOverride : null,
                    'line_order' => $index,
                    'class_id' => $line['class_id'] ?? null,
                    'location_id' => $line['location_id'] ?? null,
                ]);
            }

            $cheque->refresh();
            $cheque->recalculateAmount();

            return $cheque;
        });
    }

    /**
     * The address the cheque is mailed to, snapshotted onto the cheque itself.
     * An explicit value wins; otherwise it defaults from the payee contact's
     * billing address, the way {@see SaveDonationReceipt::donorSnapshot()}
     * builds a donor snapshot. The snapshot is what prints, so a later edit to
     * the contact never rewrites a cheque already written.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, ?string>
     */
    private function payeeAddress(array $data): array
    {
        $address = $data['payee_address'] ?? null;

        $contact = $address === null && ! empty($data['payee_contact_id'])
            ? Contact::find($data['payee_contact_id'])
            : null;

        $part = static function (string $key, string $column) use ($address, $contact): ?string {
            $value = $address !== null ? ($address[$key] ?? null) : $contact?->{$column};
            $value = trim((string) $value);

            return $value !== '' ? $value : null;
        };

        return [
            'payee_line1' => $part('line1', 'billing_line1'),
            'payee_line2' => $part('line2', 'billing_line2'),
            'payee_city' => $part('city', 'billing_city'),
            'payee_region' => $part('region', 'billing_region'),
            'payee_postal_code' => $part('postal_code', 'billing_postal_code'),
            'payee_country' => ($c = $part('country', 'billing_country')) !== null ? mb_strtoupper($c) : null,
        ];
    }
}
