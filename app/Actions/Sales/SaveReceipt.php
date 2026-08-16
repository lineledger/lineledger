<?php

namespace App\Actions\Sales;

use App\Enums\ReceiptStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Services\Posting\DocumentNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds or updates a customer receipt header and its invoice applications.
 * Shared by the Livewire form and the API. Does NOT post — the caller decides
 * whether to hand the result to ReceiptPoster.
 *
 * Expected $data shape (cents-based, framework-agnostic):
 *   contact_id:            int
 *   receipt_no:            ?string  (null → auto-generated)
 *   receipt_date:          string
 *   deposit_to_account_id: int
 *   payment_method_id:     ?int
 *   reference:             ?string
 *   amount_cents:          int
 *   memo:                  ?string
 *   applications: array<int, array{invoice_id: int, amount_cents: int}>
 */
final class SaveReceipt
{
    public function __construct(protected DocumentNumberGenerator $numbers) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?CustomerReceipt $receipt = null): CustomerReceipt
    {
        return DB::transaction(function () use ($data, $receipt): CustomerReceipt {
            $company = app('current_company');

            $header = [
                'contact_id' => $data['contact_id'],
                'receipt_date' => CarbonImmutable::parse($data['receipt_date'])->toDateString(),
                'deposit_to_account_id' => $data['deposit_to_account_id'],
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'amount_cents' => (int) $data['amount_cents'],
                'memo' => $data['memo'] ?? null,
            ];

            if ($receipt && $receipt->exists) {
                $receipt->update($header);
            } else {
                $receipt = CustomerReceipt::create($header + [
                    'receipt_no' => $data['receipt_no']
                        ?? $this->numbers->next($company, CustomerReceipt::class, 'receipt_no', 'REC'),
                    'status' => ReceiptStatus::Draft,
                    'currency_code' => $this->resolveCurrencyCode($company, $data),
                ]);
            }

            $receipt->applications()->delete();

            foreach ($data['applications'] ?? [] as $application) {
                $receipt->applications()->create([
                    'invoice_id' => $application['invoice_id'],
                    'amount_cents' => (int) $application['amount_cents'],
                ]);
            }

            return $receipt->fresh(['applications']);
        });
    }

    /**
     * The receipt's currency: an explicit override, else the contact's currency.
     * Null for the home currency.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveCurrencyCode(Company $company, array $data): ?string
    {
        $code = $data['currency_code'] ?? Contact::find($data['contact_id'])?->currency_code;

        if ($code === null || $company->isHomeCurrency($code)) {
            return null;
        }

        return mb_strtoupper($code);
    }
}
