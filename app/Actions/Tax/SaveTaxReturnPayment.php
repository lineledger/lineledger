<?php

namespace App\Actions\Tax;

use App\Enums\TaxReturnPaymentDirection;
use App\Enums\TaxReturnPaymentStatus;
use App\Models\TaxReturnPayment;
use App\Services\Posting\DocumentNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds or updates a DRAFT tax return payment and recalculates its total.
 * Shared by the Livewire form and the API. Does NOT post — the caller decides
 * whether to hand the result to TaxReturnPaymentPoster. There is no repost path;
 * posted payments must be voided and recreated.
 *
 * Expected $data shape (cents-based, framework-agnostic):
 *   tax_return_id:        int
 *   payment_no:           ?string  (null → auto-generated)
 *   payment_date:         string
 *   direction:            'outgoing'|'incoming'
 *   bank_account_id:      int
 *   payment_method_id:    ?int
 *   reference:            ?string
 *   net_amount_cents:     int
 *   penalty_cents:        ?int     penalty_account_id:    ?int
 *   interest_cents:       ?int     interest_account_id:   ?int
 *   commission_cents:     ?int     commission_account_id: ?int
 *   notes:                ?string
 */
final class SaveTaxReturnPayment
{
    public function __construct(protected DocumentNumberGenerator $numbers) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?TaxReturnPayment $payment = null): TaxReturnPayment
    {
        return DB::transaction(function () use ($data, $payment): TaxReturnPayment {
            $company = app('current_company');
            $direction = TaxReturnPaymentDirection::from($data['direction']);
            $isOutgoing = $direction === TaxReturnPaymentDirection::Outgoing;

            $penaltyCents = $isOutgoing ? (int) ($data['penalty_cents'] ?? 0) : 0;
            $interestCents = (int) ($data['interest_cents'] ?? 0);
            $commissionCents = $isOutgoing ? (int) ($data['commission_cents'] ?? 0) : 0;

            $attributes = [
                // The parent return is immutable on update; fall back to the
                // existing value when the update payload omits it.
                'tax_return_id' => $data['tax_return_id'] ?? $payment?->tax_return_id,
                'payment_date' => CarbonImmutable::parse($data['payment_date'])->toDateString(),
                'direction' => $direction,
                'bank_account_id' => $data['bank_account_id'],
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'net_amount_cents' => (int) $data['net_amount_cents'],
                'penalty_cents' => $penaltyCents,
                'penalty_account_id' => $penaltyCents > 0 ? ($data['penalty_account_id'] ?? null) : null,
                'interest_cents' => $interestCents,
                'interest_account_id' => $interestCents > 0 ? ($data['interest_account_id'] ?? null) : null,
                'commission_cents' => $commissionCents,
                'commission_account_id' => $commissionCents > 0 ? ($data['commission_account_id'] ?? null) : null,
                'notes' => $data['notes'] ?? null,
            ];

            if ($payment && $payment->exists) {
                $payment->forceFill($attributes);
                $payment->recalculateTotal();
                $payment->save();

                return $payment;
            }

            $payment = new TaxReturnPayment($attributes);
            $payment->payment_no = $data['payment_no']
                ?? $this->numbers->next($company, TaxReturnPayment::class, 'payment_no', 'TRP');
            $payment->status = TaxReturnPaymentStatus::Draft;
            $payment->recalculateTotal();
            $payment->save();

            return $payment;
        });
    }
}
