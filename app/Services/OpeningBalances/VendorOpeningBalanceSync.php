<?php

namespace App\Services\OpeningBalances;

use App\Actions\Purchasing\PostVendorOpeningBalance;
use App\Enums\VendorCreditStatus;
use App\Models\Bill;
use App\Models\Contact;
use App\Models\OpeningBalanceState;
use App\Models\VendorCredit;
use App\Services\Accounting\OpeningBalanceAccountResolver;
use App\Services\Posting\BillPoster;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\VendorCreditPoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The vendor mirror of {@see CustomerOpeningBalanceSync}: a positive net
 * balance is one opening-balance bill (DR OBE / CR AP), a negative one an
 * opening vendor credit (DR AP / CR OBE). Amounts are in the vendor's
 * currency.
 */
class VendorOpeningBalanceSync
{
    public function __construct(
        protected PostVendorOpeningBalance $postOpeningBill,
        protected BillPoster $billPoster,
        protected VendorCreditPoster $vendorCreditPoster,
        protected DocumentNumberGenerator $numbers,
        protected OpeningBalanceAccountResolver $openingBalanceAccounts,
    ) {}

    /**
     * @return array{net: int, bills: Collection<int, Bill>, credits: Collection<int, VendorCredit>}
     */
    public function currentFor(Contact $contact): array
    {
        $bills = Bill::withoutGlobalScopes()
            ->where('company_id', $contact->company_id)
            ->where('contact_id', $contact->id)
            ->where('is_opening_balance', true)
            ->whereNotNull('journal_entry_id')
            ->whereNull('voided_at')
            ->get();

        $credits = VendorCredit::withoutGlobalScopes()
            ->where('company_id', $contact->company_id)
            ->where('contact_id', $contact->id)
            ->where('is_opening_balance', true)
            ->whereNotNull('journal_entry_id')
            ->whereNull('voided_at')
            ->get();

        return [
            'net' => (int) $bills->sum('total_cents') - (int) $credits->sum('total_cents'),
            'bills' => $bills,
            'credits' => $credits,
        ];
    }

    /** Make the vendor's opening AP detail equal $signedCents (positive = we owe). */
    public function set(OpeningBalanceState $state, Contact $contact, int $signedCents): void
    {
        DB::transaction(function () use ($state, $contact, $signedCents): void {
            $asOf = $state->asOf();
            $current = $this->currentFor($contact);

            if ($current['net'] === $signedCents) {
                return;
            }

            $voidDate = $state->company->isLockedFor($asOf) ? null : $asOf;

            if ($signedCents > 0 && $current['bills']->count() === 1 && $current['credits']->isEmpty()) {
                $this->repostBillAt($current['bills']->first(), $signedCents);

                return;
            }

            if ($signedCents < 0 && $current['credits']->count() === 1 && $current['bills']->isEmpty()) {
                $this->repostCreditAt($current['credits']->first(), -$signedCents);

                return;
            }

            foreach ($current['bills'] as $bill) {
                $this->guardUnsettled($bill);
                $this->billPoster->void($bill, $voidDate);
            }

            foreach ($current['credits'] as $credit) {
                $this->vendorCreditPoster->void($credit, $voidDate);
            }

            if ($signedCents > 0) {
                $this->postOpeningBill->handle($contact, $signedCents, $asOf);
            } elseif ($signedCents < 0) {
                $this->createOpeningVendorCredit($contact, -$signedCents, $asOf);
            }
        });
    }

    protected function repostBillAt(Bill $bill, int $amountCents): void
    {
        if ((int) $bill->total_cents === $amountCents) {
            return;
        }

        $this->guardUnsettled($bill);

        $bill->lines()->update([
            'unit_price_cents' => $amountCents,
            'line_subtotal_cents' => $amountCents,
            'line_total_cents' => $amountCents,
        ]);

        $this->billPoster->repost($bill->refresh());
    }

    protected function repostCreditAt(VendorCredit $credit, int $amountCents): void
    {
        if ((int) $credit->total_cents === $amountCents) {
            return;
        }

        $credit->lines()->update([
            'unit_price_cents' => $amountCents,
            'line_subtotal_cents' => $amountCents,
            'line_total_cents' => $amountCents,
        ]);

        $this->vendorCreditPoster->repost($credit->refresh());
    }

    protected function createOpeningVendorCredit(Contact $contact, int $amountCents, CarbonImmutable $asOf): VendorCredit
    {
        $company = $contact->company;
        $obe = $this->openingBalanceAccounts->resolveOrFail((int) $company->id);

        $credit = VendorCredit::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'vendor_credit_no' => $this->numbers->next($company, VendorCredit::class, 'vendor_credit_no', 'OB'),
            'vendor_credit_date' => $asOf,
            'status' => VendorCreditStatus::Draft,
            'subtotal_cents' => $amountCents,
            'tax_cents' => 0,
            'total_cents' => $amountCents,
            'currency_code' => $contact->currency_code,
            'memo' => 'Opening balance',
            'is_opening_balance' => true,
        ]);

        $credit->lines()->create([
            'account_id' => $obe->id,
            'description' => 'Opening balance',
            'quantity' => '1.0000',
            'unit_price_cents' => $amountCents,
            'line_subtotal_cents' => $amountCents,
            'line_tax_cents' => 0,
            'line_total_cents' => $amountCents,
            'line_order' => 0,
        ]);

        $this->vendorCreditPoster->post($credit->fresh());

        return $credit->refresh();
    }

    protected function guardUnsettled(Bill $bill): void
    {
        if ((int) $bill->amount_paid_cents + (int) ($bill->reconciled_cents ?? 0) > 0) {
            throw new RuntimeException(
                "Opening bill {$bill->bill_no} already has payments applied — adjust it from the bill screen instead.",
            );
        }
    }
}
