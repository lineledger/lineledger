<?php

namespace App\Actions\Purchasing;

use App\Actions\Sales\PostCustomerOpeningBalance;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Models\Bill;
use App\Models\Contact;
use App\Services\Accounting\OpeningBalanceAccountResolver;
use App\Services\Migration\Importers\OpenBillsImporter;
use App\Services\Posting\BillPoster;
use App\Services\Posting\DocumentNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Posts a vendor's opening Accounts-Payable balance as a synthetic
 * opening-balance bill — the missing twin of
 * {@see PostCustomerOpeningBalance}, using the same
 * mechanism as the QuickBooks open-bills importer ({@see OpenBillsImporter}).
 *
 * The single line targets Opening Balance Equity, so {@see BillPoster}
 * naturally posts:
 *   DR  Opening Balance Equity     amount
 *   CR  Accounts Payable           amount
 *
 * No expense or tax is recognised — the purchase happened in the previous
 * system. The bill ages from $asOf so it lands in the correct AP-Aging bucket
 * and ties to the AP control account to the penny.
 */
final class PostVendorOpeningBalance
{
    public function __construct(
        protected DocumentNumberGenerator $numbers,
        protected BillPoster $poster,
        protected OpeningBalanceAccountResolver $openingBalanceAccounts,
    ) {}

    public function handle(Contact $contact, int $amountCents, CarbonImmutable $asOf): Bill
    {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Opening balance must be greater than zero.');
        }

        return DB::transaction(function () use ($contact, $amountCents, $asOf): Bill {
            $company = $contact->company;

            $obe = $this->openingBalanceAccounts->resolveOrFail((int) $company->id);

            $bill = Bill::create([
                'company_id' => $company->id,
                'contact_id' => $contact->id,
                'bill_type' => BillType::Vendor,
                'bill_no' => $this->numbers->next($company, Bill::class, 'bill_no', 'OB'),
                'bill_date' => $asOf,
                'due_date' => $asOf,
                'status' => BillStatus::Draft,
                'subtotal_cents' => $amountCents,
                'tax_cents' => 0,
                'total_cents' => $amountCents,
                'amount_paid_cents' => 0,
                'currency_code' => $contact->currency_code,
                'memo' => 'Opening balance',
                'is_opening_balance' => true,
            ]);

            $bill->lines()->create([
                'account_id' => $obe->id,
                'description' => 'Opening balance',
                'quantity' => '1.0000',
                'unit_price_cents' => $amountCents,
                'line_subtotal_cents' => $amountCents,
                'line_tax_cents' => 0,
                'line_total_cents' => $amountCents,
                'line_order' => 0,
            ]);

            $this->poster->post($bill->fresh());

            return $bill->refresh();
        });
    }
}
