<?php

namespace App\Services\Tax;

use App\Enums\SalesTaxBucket;
use App\Enums\TaxReturnAdjustmentKind;
use App\Models\TaxAgency;
use App\Models\TaxReturn;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Works out a tax return's figures and its reconciliation to the agency's
 * payable account. The single source for the edit form's preview, the show
 * page of a draft, the totals a draft saves and what filing freezes — so the
 * four can never disagree.
 *
 * Pure read service: nothing here writes.
 */
class TaxReturnCalculator
{
    public function __construct(protected ReportCalculator $reports) {}

    /**
     * Figures for a saved return, from its own agency, period, exclusions and
     * adjustments.
     */
    public function forReturn(TaxReturn $return): TaxReturnFigures
    {
        $return->loadMissing('taxAgency', 'adjustments');

        return $this->calculate(
            $return->taxAgency,
            CarbonImmutable::parse($return->period_start),
            CarbonImmutable::parse($return->period_end),
            array_map('intval', $return->excluded_journal_line_ids ?? []),
            $return->adjustments->map(fn ($adjustment) => [
                'kind' => $adjustment->kind,
                'account_id' => (int) $adjustment->account_id,
                'amount_cents' => (int) $adjustment->amount_cents,
                'memo' => $adjustment->memo,
            ])->all(),
        );
    }

    /**
     * @param  int[]  $excludedLineIds  journal lines left off the return
     * @param  array<int, array{kind: TaxReturnAdjustmentKind|string, account_id: int, amount_cents: int, memo?: ?string}>  $adjustments
     */
    public function calculate(TaxAgency $agency, CarbonInterface $start, CarbonInterface $end, array $excludedLineIds = [], array $adjustments = []): TaxReturnFigures
    {
        $payableAccountId = (int) $agency->payable_account_id;

        $lines = $this->reports->salesTaxLines($agency, $start, $end)
            ->map(fn (array $line) => $line + [
                // Only a line on the return can be left off it.
                'excluded' => SalesTaxBucket::from($line['bucket'])->isOnReturn()
                    && in_array((int) $line['journal_line_id'], $excludedLineIds, true),
            ]);

        $normalized = array_values(array_map(function (array $adjustment) use ($payableAccountId): array {
            $kind = $adjustment['kind'] instanceof TaxReturnAdjustmentKind
                ? $adjustment['kind']
                : TaxReturnAdjustmentKind::from($adjustment['kind']);
            $amount = (int) $adjustment['amount_cents'];

            return [
                'kind' => $kind,
                'account_id' => (int) $adjustment['account_id'],
                'amount_cents' => $amount,
                'memo' => $adjustment['memo'] ?? null,
                'effect_cents' => $kind->effectOnNet($amount),
                // On the payable account itself the amount is already in the
                // ledger; the adjustment only brings it onto the return.
                'posts' => (int) $adjustment['account_id'] !== $payableAccountId,
            ];
        }, $adjustments));

        $included = $lines->filter(fn (array $line) => ! $line['excluded']);
        $sumAmount = fn (SalesTaxBucket $bucket): int => (int) $included->where('bucket', $bucket->value)->sum('amount_cents');
        $sumEffect = fn (SalesTaxBucket $bucket): int => (int) $included->where('bucket', $bucket->value)->sum('balance_effect_cents');
        $sumAdjustments = fn (TaxReturnAdjustmentKind $kind): int => array_sum(array_map(
            fn (array $adjustment) => $adjustment['kind'] === $kind ? $adjustment['amount_cents'] : 0,
            $normalized,
        ));

        $collected = $sumAmount(SalesTaxBucket::Collected) + $sumAdjustments(TaxReturnAdjustmentKind::Collected);
        $paid = $sumAmount(SalesTaxBucket::Paid) + $sumAdjustments(TaxReturnAdjustmentKind::Paid);
        $other = $sumAdjustments(TaxReturnAdjustmentKind::Other);
        $net = $collected - $paid + $other;

        $opening = $this->reports->salesTaxOpeningBalance($agency, $start);
        $closing = $this->reports->salesTaxClosingBalance($agency, $end);

        $openingEntries = $sumEffect(SalesTaxBucket::Opening);
        $payments = $sumEffect(SalesTaxBucket::Payment);
        $priorAdjustments = $sumEffect(SalesTaxBucket::Adjustment);
        $ledgerCollected = $sumEffect(SalesTaxBucket::Collected);
        $ledgerItc = $sumEffect(SalesTaxBucket::Paid);
        $excluded = (int) $lines->where('excluded', true)->sum('balance_effect_cents');

        // Whatever the classifier could not place (a line carrying both a debit
        // and a credit) — the plug that makes the ledger side add up exactly.
        $unclassified = $closing - $opening
            - ($openingEntries + $payments + $priorAdjustments + $ledgerCollected + $ledgerItc + $excluded);

        $toPost = array_sum(array_map(
            fn (array $adjustment) => $adjustment['posts'] ? $adjustment['effect_cents'] : 0,
            $normalized,
        ));
        $projected = $closing + $toPost;

        return new TaxReturnFigures(
            lines: $lines->values(),
            adjustments: $normalized,
            collectedCents: $collected,
            paidCents: $paid,
            otherAdjustmentsCents: $other,
            netCents: $net,
            reconciliation: [
                // Ledger side, each as its effect on what is owed.
                'opening_cents' => $opening,
                'opening_entries_cents' => $openingEntries,
                'payments_cents' => $payments,
                'collected_cents' => $ledgerCollected,
                'itc_cents' => $ledgerItc,
                'excluded_cents' => $excluded,
                'prior_adjustments_cents' => $priorAdjustments,
                'unclassified_cents' => $unclassified,
                'closing_cents' => $closing,
                'to_post_cents' => $toPost,
                'projected_cents' => $projected,
                // Return side.
                'return_collected_cents' => $collected,
                'return_itc_cents' => -$paid,
                'return_other_cents' => $other,
                'return_net_cents' => $net,
                'difference_cents' => $projected - $net,
            ],
        );
    }
}
