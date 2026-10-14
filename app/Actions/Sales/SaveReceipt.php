<?php

namespace App\Actions\Sales;

use App\Enums\ReceiptStatus;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\ReceiptPoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds or updates a customer receipt header and its invoice applications.
 * Shared by the Livewire form and the API. Does NOT post — the caller decides
 * whether to hand the result to ReceiptPoster.
 *
 * On an update the returned receipt remembers which invoices (and contact) it
 * applied to BEFORE the rewrite — see CustomerReceipt::previousApplicationInvoiceIds()
 * — so ReceiptPoster::repost() can recompute the invoices this receipt no
 * longer pays. Without that, moving a receipt from one invoice to another left
 * the old invoice's paid amount and status stale.
 *
 * A refund receipt can't be edited here (remove it and record it again), and a
 * payment that has been refunded can't change customer or drop its unapplied
 * credit below what was refunded from it.
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
    public function __construct(
        protected DocumentNumberGenerator $numbers,
        protected ReceiptPoster $poster,
    ) {}

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

            // The number is user-editable, so a correction has to reach an existing
            // receipt too — not just the create below. filled() keeps a partial update
            // that omits the key from blanking the current number.
            if (filled($data['receipt_no'] ?? null)) {
                $header['receipt_no'] = $data['receipt_no'];
            }

            $previousInvoiceIds = [];
            $previousContactId = null;

            if ($receipt && $receipt->exists) {
                // Lock the row a refund of this payment locks first, so an edit and
                // a new refund can't both spend the same unapplied credit.
                CustomerReceipt::query()->lockForUpdate()->find($receipt->id);

                if ($receipt->isRefund()) {
                    throw new PostingValidationException(__('A refund can\'t be edited. Remove it and record it again.'));
                }

                if ((int) $data['contact_id'] !== (int) $receipt->contact_id && $receipt->hasLiveRefunds()) {
                    throw new PostingValidationException(__('This payment has been refunded, so it can\'t move to another customer. Remove its refunds first.'));
                }

                $previousInvoiceIds = $receipt->applications()->pluck('invoice_id')->map(fn ($id) => (int) $id)->all();
                $previousContactId = (int) $receipt->contact_id;
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

            $saved = $receipt->fresh(['applications']);

            $this->poster->ensureAmountCoversRefunds($saved);

            return $saved->rememberPreviousApplications($previousInvoiceIds, $previousContactId);
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
