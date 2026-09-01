<?php

namespace App\Services\OpeningBalances;

use App\Actions\Sales\PostCustomerOpeningBalance;
use App\Enums\CreditMemoStatus;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\Invoice;
use App\Models\OpeningBalanceState;
use App\Services\Accounting\OpeningBalanceAccountResolver;
use App\Services\Posting\CreditMemoPoster;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\InvoicePoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Keeps a customer's opening AR detail in step with the number typed on the
 * workspace grid. A positive net balance is one opening-balance invoice
 * (DR AR / CR OBE, via the existing PostCustomerOpeningBalance action); a
 * negative one is an opening credit memo (DR OBE / CR AR). Edits repost the
 * existing document in place; sign flips and zeroes void through the posters,
 * so the audit trail and the contact's cached AR balance stay right.
 *
 * Amounts are in the CONTACT's currency — the posters lock the FX rate and
 * route foreign customers to the matching per-currency AR control account.
 */
class CustomerOpeningBalanceSync
{
    public function __construct(
        protected PostCustomerOpeningBalance $postOpeningInvoice,
        protected InvoicePoster $invoicePoster,
        protected CreditMemoPoster $creditMemoPoster,
        protected DocumentNumberGenerator $numbers,
        protected OpeningBalanceAccountResolver $openingBalanceAccounts,
    ) {}

    /**
     * The customer's live opening documents and their net signed total (in the
     * contact's currency; positive = owed to us).
     *
     * @return array{net: int, invoices: Collection<int, Invoice>, memos: Collection<int, CreditMemo>}
     */
    public function currentFor(Contact $contact): array
    {
        $invoices = Invoice::withoutGlobalScopes()
            ->where('company_id', $contact->company_id)
            ->where('contact_id', $contact->id)
            ->where('is_opening_balance', true)
            ->whereNotNull('journal_entry_id')
            ->whereNull('voided_at')
            ->get();

        $memos = CreditMemo::withoutGlobalScopes()
            ->where('company_id', $contact->company_id)
            ->where('contact_id', $contact->id)
            ->where('is_opening_balance', true)
            ->whereNotNull('journal_entry_id')
            ->whereNull('voided_at')
            ->get();

        return [
            'net' => (int) $invoices->sum('total_cents') - (int) $memos->sum('total_cents'),
            'invoices' => $invoices,
            'memos' => $memos,
        ];
    }

    /**
     * Make the customer's opening AR detail equal $signedCents. Posts, reposts
     * or voids immediately — each grid save is a real business event.
     */
    public function set(OpeningBalanceState $state, Contact $contact, int $signedCents): void
    {
        DB::transaction(function () use ($state, $contact, $signedCents): void {
            $asOf = $state->asOf();
            $current = $this->currentFor($contact);

            if ($current['net'] === $signedCents) {
                return;
            }

            $voidDate = $state->company->isLockedFor($asOf) ? null : $asOf;

            // Simple amount change on the one live invoice: repost in place.
            if ($signedCents > 0 && $current['invoices']->count() === 1 && $current['memos']->isEmpty()) {
                $this->repostInvoiceAt($current['invoices']->first(), $signedCents);

                return;
            }

            if ($signedCents < 0 && $current['memos']->count() === 1 && $current['invoices']->isEmpty()) {
                $this->repostMemoAt($current['memos']->first(), -$signedCents);

                return;
            }

            // Everything else — sign flip, zero, or consolidating multiple
            // documents — voids what exists and creates one fresh document.
            foreach ($current['invoices'] as $invoice) {
                $this->guardUnsettled($invoice);
                $this->invoicePoster->void($invoice, $voidDate);
            }

            foreach ($current['memos'] as $memo) {
                $this->creditMemoPoster->void($memo, $voidDate);
            }

            if ($signedCents > 0) {
                $this->postOpeningInvoice->handle($contact, $signedCents, $asOf);
            } elseif ($signedCents < 0) {
                $this->createOpeningCreditMemo($contact, -$signedCents, $asOf);
            }
        });
    }

    protected function repostInvoiceAt(Invoice $invoice, int $amountCents): void
    {
        if ((int) $invoice->total_cents === $amountCents) {
            return;
        }

        $this->guardUnsettled($invoice);

        $invoice->lines()->update([
            'unit_price_cents' => $amountCents,
            'line_subtotal_cents' => $amountCents,
            'line_total_cents' => $amountCents,
        ]);

        // The poster recalculates header totals from the lines and reposts the
        // same journal entry in place.
        $this->invoicePoster->repost($invoice->refresh());
    }

    protected function repostMemoAt(CreditMemo $memo, int $amountCents): void
    {
        if ((int) $memo->total_cents === $amountCents) {
            return;
        }

        $memo->lines()->update([
            'unit_price_cents' => $amountCents,
            'line_subtotal_cents' => $amountCents,
            'line_total_cents' => $amountCents,
        ]);

        $this->creditMemoPoster->repost($memo->refresh());
    }

    protected function createOpeningCreditMemo(Contact $contact, int $amountCents, CarbonImmutable $asOf): CreditMemo
    {
        $company = $contact->company;
        $obe = $this->openingBalanceAccounts->resolveOrFail((int) $company->id);

        $memo = CreditMemo::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'credit_memo_no' => $this->numbers->next($company, CreditMemo::class, 'credit_memo_no', 'OB'),
            'credit_memo_date' => $asOf,
            'status' => CreditMemoStatus::Draft,
            'subtotal_cents' => $amountCents,
            'tax_cents' => 0,
            'total_cents' => $amountCents,
            'currency_code' => $contact->currency_code,
            'memo' => 'Opening balance',
            'is_opening_balance' => true,
        ]);

        $memo->lines()->create([
            'account_id' => $obe->id,
            'description' => 'Opening balance',
            'quantity' => '1.0000',
            'unit_price_cents' => $amountCents,
            'line_subtotal_cents' => $amountCents,
            'line_tax_cents' => 0,
            'line_total_cents' => $amountCents,
            'line_order' => 0,
        ]);

        $this->creditMemoPoster->post($memo->fresh());

        return $memo->refresh();
    }

    /**
     * An opening invoice that already has receipts applied (or a GL
     * reconciliation) cannot be silently rewritten out from under them.
     */
    protected function guardUnsettled(Invoice $invoice): void
    {
        if ($invoice->settledCents() > 0) {
            throw new RuntimeException(
                "Opening invoice {$invoice->invoice_no} already has payments applied — adjust it from the invoice screen instead.",
            );
        }
    }
}
