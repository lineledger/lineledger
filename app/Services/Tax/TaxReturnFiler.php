<?php

namespace App\Services\Tax;

use App\Enums\AuditAction;
use App\Enums\TaxReturnStatus;
use App\Exceptions\Posting\TaxReturnOutOfBalanceException;
use App\Models\TaxReturn;
use App\Models\TaxReturnLine;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Posting\TaxReturnAdjustmentPoster;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Files (or voids) a tax return.
 *
 * Filing snapshots every journal line on the return — frozen, denormalized, so
 * the record survives later edits or voids to the underlying source documents
 * — and freezes the return's reconciliation to the agency's payable account.
 * It refuses a return that does not reconcile unless the caller accepts that
 * exact difference.
 *
 * Filing touches the ledger only through the return's adjustments: those coded
 * to an account other than the payable account post one journal entry dated
 * the period's last day ({@see TaxReturnAdjustmentPoster}); voiding the return
 * reverses it.
 */
class TaxReturnFiler
{
    public function __construct(
        protected TaxReturnCalculator $calculator,
        protected TaxReturnAdjustmentPoster $adjustmentPoster,
        protected AccountingAuditRecorder $auditRecorder,
    ) {}

    /**
     * @param  ?int  $acceptedDifferenceCents  the difference from the payable account the filer
     *                                         has reviewed and accepts; must match the live one
     */
    public function file(TaxReturn $return, ?int $acceptedDifferenceCents = null): TaxReturn
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($return, $acceptedDifferenceCents) {
            $caller = $return;

            // Re-read under a row lock so two filings of the same draft can't
            // both pass the status check and post the adjustments twice.
            $return = TaxReturn::query()->lockForUpdate()->findOrFail($caller->id);
            $return->load('taxAgency', 'adjustments');

            if ($return->status !== TaxReturnStatus::Draft) {
                throw new RuntimeException('Only draft tax returns can be filed.');
            }

            $this->guardAgainstOverlap($return);

            $figures = $this->calculator->forReturn($return);
            $difference = $figures->differenceCents();

            if ($difference !== 0 && $difference !== $acceptedDifferenceCents) {
                throw TaxReturnOutOfBalanceException::from($difference, $acceptedDifferenceCents);
            }

            $entry = $this->adjustmentPoster->post($return, $figures);

            $return->lines()->delete();

            $rows = $figures->includedLines();
            $order = 0;

            foreach ($rows as $row) {
                TaxReturnLine::create([
                    'tax_return_id' => $return->id,
                    'journal_line_id' => $row['journal_line_id'],
                    'journal_entry_id' => $row['entry_id'],
                    'bucket' => $row['bucket'],
                    'amount_cents' => $row['amount_cents'],
                    'entry_no' => $row['entry_no'],
                    'entry_date' => $row['entry_date'],
                    'source_type' => $row['source_type'],
                    'source_id' => $row['source_id'],
                    'doc_label' => $row['doc_label'],
                    'is_reversal' => $row['is_reversal'],
                    'line_order' => $order++,
                ]);
            }

            $reconciliation = $figures->reconciliation + ['accepted_difference_cents' => $difference];

            $return->forceFill([
                'status' => TaxReturnStatus::Filed,
                'collected_cents' => $figures->collectedCents,
                'paid_cents' => $figures->paidCents,
                'other_adjustments_cents' => $figures->otherAdjustmentsCents,
                'net_cents' => $figures->netCents,
                'adjustment_journal_entry_id' => $entry?->id,
                'reconciliation' => $reconciliation,
                'filed_at' => now(),
                'filed_by_user_id' => Auth::id(),
            ])->save();

            $caller->setRawAttributes($return->getAttributes(), true);

            $this->auditRecorder->record(
                (int) $return->company_id,
                AuditAction::TaxReturnFiled,
                $return,
                [
                    'tax_return_no' => $return->tax_return_no,
                    'tax_agency_id' => (int) $return->tax_agency_id,
                    'tax_agency_name' => $return->taxAgency->name,
                    'period_start' => optional($return->period_start)->toDateString(),
                    'period_end' => optional($return->period_end)->toDateString(),
                    'collected_cents' => $figures->collectedCents,
                    'paid_cents' => $figures->paidCents,
                    'other_adjustments_cents' => $figures->otherAdjustmentsCents,
                    'net_cents' => $figures->netCents,
                    'line_count' => $rows->count(),
                    'excluded_line_count' => $figures->returnLines()->count() - $rows->count(),
                    'adjustments' => array_map(fn (array $adjustment) => [
                        'kind' => $adjustment['kind']->value,
                        'account_id' => $adjustment['account_id'],
                        'amount_cents' => $adjustment['amount_cents'],
                        'memo' => $adjustment['memo'],
                        'posted' => $adjustment['posts'],
                    ], $figures->adjustments),
                    'reconciliation' => $reconciliation,
                    'adjustment_journal_entry_id' => $entry?->id,
                    'journal' => $entry ? AccountingAuditRecorder::snapshotJournalEntry($entry) : null,
                    'filing_reference' => $return->filing_reference,
                ],
                $entry,
            );

            return $return->fresh(['lines', 'taxAgency', 'adjustments']);
        }));
    }

    public function void(TaxReturn $return, string $reason): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($return, $reason) {
            $locked = TaxReturn::query()->lockForUpdate()->findOrFail($return->id);

            if ($locked->status !== TaxReturnStatus::Filed) {
                throw new RuntimeException('Only filed tax returns can be voided.');
            }

            // Reverse the filing's own adjustment entry before the period is
            // released, so a re-filing never counts it twice.
            $this->adjustmentPoster->void($locked);

            $locked->forceFill([
                'status' => TaxReturnStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
                'void_reason' => $reason,
            ])->save();

            $return->setRawAttributes($locked->getAttributes(), true);

            $this->auditRecorder->record(
                (int) $locked->company_id,
                AuditAction::TaxReturnVoided,
                $locked,
                [
                    'tax_return_no' => $locked->tax_return_no,
                    'reason' => $reason,
                    'voided_at' => optional($locked->voided_at)->format('Y-m-d H:i:s.u'),
                    'adjustment_journal_entry_id' => $locked->adjustment_journal_entry_id,
                ],
            );
        }));
    }

    protected function guardAgainstOverlap(TaxReturn $return): void
    {
        $exists = TaxReturn::query()
            ->withoutGlobalScopes()
            ->where('company_id', $return->company_id)
            ->where('tax_agency_id', $return->tax_agency_id)
            ->where('id', '!=', $return->id)
            ->where('status', TaxReturnStatus::Filed->value)
            ->where('period_start', '<=', $return->period_end)
            ->where('period_end', '>=', $return->period_start)
            ->exists();

        if ($exists) {
            throw new RuntimeException('A filed tax return already covers part of this period for this agency.');
        }
    }
}
