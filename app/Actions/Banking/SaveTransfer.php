<?php

namespace App\Actions\Banking;

use App\Enums\TransferStatus;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Transfer;
use App\Services\Posting\DocumentNumberGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Builds or updates an account-transfer header. Shared by the Livewire form and
 * the API. Does NOT post — the caller decides whether to hand the result to
 * TransferPoster.
 *
 * A transfer moves money from one account to another. For a same-currency move
 * from_amount_cents and to_amount_cents are equal; for a cross-currency move each
 * amount is expressed in its own account's currency and TransferPoster plugs the
 * home-value spread to the Exchange Gain/Loss account.
 *
 * Only draft transfers may be edited; TransferPoster has no repost path, so a
 * posted transfer must be voided and recreated.
 *
 * Expected $data shape (cents-based, framework-agnostic):
 *   from_account_id:   int
 *   to_account_id:     int
 *   transfer_no:       ?string  (null → auto-generated, XFR prefix)
 *   transfer_date:     string
 *   from_amount_cents: int
 *   to_amount_cents:   int
 *   memo:              ?string
 */
final class SaveTransfer
{
    public function __construct(protected DocumentNumberGenerator $numbers) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Transfer $transfer = null): Transfer
    {
        return DB::transaction(function () use ($data, $transfer): Transfer {
            $company = app('current_company');

            $fromAccountId = (int) $data['from_account_id'];
            $toAccountId = (int) $data['to_account_id'];

            if ($fromAccountId === $toAccountId) {
                throw new PostingValidationException('A transfer must move money between two different accounts.');
            }

            $fromAmount = (int) $data['from_amount_cents'];
            $toAmount = (int) $data['to_amount_cents'];

            if ($fromAmount <= 0 || $toAmount <= 0) {
                throw new PostingValidationException('Transfer amounts must be greater than zero.');
            }

            $header = [
                'from_account_id' => $fromAccountId,
                'to_account_id' => $toAccountId,
                'transfer_date' => $data['transfer_date'],
                'from_amount_cents' => $fromAmount,
                'to_amount_cents' => $toAmount,
                'memo' => $data['memo'] ?? null,
            ];

            if ($transfer && $transfer->exists) {
                $transfer->update($header);
            } else {
                $transfer = Transfer::create($header + [
                    'transfer_no' => $data['transfer_no']
                        ?? $this->numbers->next($company, Transfer::class, 'transfer_no', 'XFR'),
                    'status' => TransferStatus::Draft,
                ]);
            }

            return $transfer->fresh();
        });
    }
}
