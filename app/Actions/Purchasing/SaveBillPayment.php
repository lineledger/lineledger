<?php

namespace App\Actions\Purchasing;

use App\Enums\BillPaymentStatus;
use App\Enums\BillType;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Posting\DocumentNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds or updates a bill-payment header and its bill applications.
 * Shared by the Livewire form and the API. Does NOT post — the caller decides
 * whether to hand the result to BillPaymentPoster.
 *
 * Expected $data shape (cents-based, framework-agnostic):
 *   contact_id:           int
 *   payment_type:         ?string  'vendor'|'reimbursement' (default vendor)
 *   payment_no:           ?string  (null → auto-generated, PAY prefix)
 *   payment_date:         string
 *   paid_from_account_id: int
 *   payment_method_id:    ?int
 *   reference:            ?string
 *   amount_cents:         int
 *   memo:                 ?string
 *   applications: array<int, array{bill_id: int, amount_cents: int}>
 */
final class SaveBillPayment
{
    public function __construct(protected DocumentNumberGenerator $numbers) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?BillPayment $payment = null): BillPayment
    {
        return DB::transaction(function () use ($data, $payment): BillPayment {
            $company = app('current_company');
            $paymentType = isset($data['payment_type'])
                ? BillType::from($data['payment_type'])
                : ($payment?->payment_type ?? BillType::Vendor);

            $header = [
                'contact_id' => $data['contact_id'],
                'payment_date' => CarbonImmutable::parse($data['payment_date'])->toDateString(),
                'paid_from_account_id' => $data['paid_from_account_id'],
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'amount_cents' => (int) $data['amount_cents'],
                'memo' => $data['memo'] ?? null,
            ];

            if ($payment && $payment->exists) {
                $payment->update($header);
            } else {
                $payment = BillPayment::create($header + [
                    'payment_type' => $paymentType,
                    'payment_no' => $data['payment_no']
                        ?? $this->numbers->next($company, BillPayment::class, 'payment_no', 'PAY'),
                    'status' => BillPaymentStatus::Draft,
                    'currency_code' => $this->resolveCurrencyCode($company, $data),
                ]);
            }

            $payment->applications()->delete();

            foreach ($data['applications'] ?? [] as $application) {
                $payment->applications()->create([
                    'bill_id' => $application['bill_id'],
                    'amount_cents' => (int) $application['amount_cents'],
                ]);
            }

            return $payment->fresh(['applications']);
        });
    }

    /**
     * The payment's currency: an explicit override, else the vendor's currency.
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
