<?php

namespace App\Services\Posting;

use App\Models\JournalEntry;
use App\Models\TaxReturn;
use App\Services\Tax\TaxReturnFigures;
use App\Services\Tax\TaxReturnFiler;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Posts the adjustments a tax return carries against an account other than the
 * agency's payable account — one journal entry, dated the last day of the
 * return's period, so the payable account's balance at period end ties to the
 * filed net owing.
 *
 * Per adjustment, by its effect on the net owing:
 *   raises it (e.g. a correction):   DR  account        CR  Tax Payable
 *   lowers it (e.g. a commission):   DR  Tax Payable    CR  account
 *
 * Adjustments coded to the payable account itself post nothing — the amount is
 * already in the ledger. Called from inside {@see TaxReturnFiler},
 * whose transaction and audit record cover the entry.
 */
class TaxReturnAdjustmentPoster
{
    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
    ) {}

    /**
     * Post the return's ledger adjustments. Returns null when none post.
     */
    public function post(TaxReturn $return, TaxReturnFigures $figures): ?JournalEntry
    {
        $postings = array_filter($figures->postingAdjustments(), fn (array $adjustment) => $adjustment['effect_cents'] !== 0);

        if ($postings === []) {
            return null;
        }

        $return->loadMissing('company', 'taxAgency');
        $payableAccountId = (int) $return->taxAgency->payable_account_id;

        if (! $payableAccountId) {
            throw new RuntimeException('Tax agency has no payable account configured.');
        }

        $entry = JournalEntry::create([
            'entry_no' => $this->entryNumbers->next($return->company),
            'entry_date' => CarbonImmutable::parse($return->period_end)->toDateString(),
            'memo' => "Tax return {$return->tax_return_no} adjustments — {$return->taxAgency->name}",
            'source_type' => TaxReturn::class,
            'source_id' => $return->id,
        ]);

        $order = 0;

        foreach ($postings as $adjustment) {
            $effect = $adjustment['effect_cents'];
            $cents = abs($effect);
            $memo = $adjustment['memo'] ?: "Tax return {$return->tax_return_no} — {$adjustment['kind']->label()} adjustment";

            // Raising the net owing credits the payable account; lowering it
            // (a commission the agency lets you keep) debits it.
            $entry->lines()->create([
                'account_id' => $effect > 0 ? $adjustment['account_id'] : $payableAccountId,
                'debit_cents' => $cents,
                'credit_cents' => 0,
                'memo' => $memo,
                'line_order' => $order++,
            ]);

            $entry->lines()->create([
                'account_id' => $effect > 0 ? $payableAccountId : $adjustment['account_id'],
                'debit_cents' => 0,
                'credit_cents' => $cents,
                'memo' => $memo,
                'line_order' => $order++,
            ]);
        }

        $entry->refresh();

        return $this->journalPoster->post($entry);
    }

    /**
     * Reverse the return's adjustment entry on its own date, so the reversal
     * lands in the same period and re-filing that period starts clean — and is
     * refused, like any posting, when that date is locked. An entry already
     * voided elsewhere is left as it is.
     */
    public function void(TaxReturn $return): void
    {
        $entry = $return->adjustmentJournalEntry;

        if (! $entry || $entry->isVoided()) {
            return;
        }

        $this->journalPoster->void(
            $entry,
            CarbonImmutable::parse($entry->entry_date),
            "Void of tax return {$return->tax_return_no} adjustments",
        );
    }
}
