<?php

namespace App\Services\Tax;

use App\Enums\AuditAction;
use App\Enums\TaxReturnStatus;
use App\Models\TaxReturn;
use App\Models\TaxReturnLine;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Files (or voids) a tax return. Filing snapshots every journal line that
 * contributed to the agency's tax balance for the period — frozen, denormalized,
 * so the record survives later edits or voids to the underlying source documents.
 *
 * This service intentionally has no GL impact; it is record-keeping only.
 */
class TaxReturnFiler
{
    public function __construct(
        protected TaxReturnBuilder $builder,
        protected AccountingAuditRecorder $auditRecorder,
    ) {}

    public function file(TaxReturn $return): TaxReturn
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($return) {
            $return->loadMissing('taxAgency', 'lines');

            if ($return->status !== TaxReturnStatus::Draft) {
                throw new RuntimeException('Only draft tax returns can be filed.');
            }

            $this->guardAgainstOverlap($return);

            $allRows = $this->builder->build(
                $return->taxAgency,
                CarbonImmutable::parse($return->period_start),
                CarbonImmutable::parse($return->period_end),
            );

            $excluded = array_map('intval', $return->excluded_journal_line_ids ?? []);
            $rows = $excluded === []
                ? $allRows
                : $allRows->reject(fn ($row) => in_array((int) $row['journal_line_id'], $excluded, true))->values();
            $excludedCount = $allRows->count() - $rows->count();

            $return->lines()->delete();

            $collected = 0;
            $paid = 0;
            $order = 0;

            foreach ($rows as $row) {
                if ($row['bucket'] === 'collected') {
                    $collected += $row['amount_cents'];
                } else {
                    $paid += $row['amount_cents'];
                }

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

            $return->forceFill([
                'status' => TaxReturnStatus::Filed,
                'collected_cents' => $collected,
                'paid_cents' => $paid,
                'net_cents' => $collected - $paid,
                'filed_at' => now(),
                'filed_by_user_id' => Auth::id(),
            ])->save();

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
                    'collected_cents' => $collected,
                    'paid_cents' => $paid,
                    'net_cents' => $collected - $paid,
                    'line_count' => $rows->count(),
                    'excluded_line_count' => $excludedCount,
                    'filing_reference' => $return->filing_reference,
                ],
            );

            return $return->fresh(['lines', 'taxAgency']);
        }));
    }

    public function void(TaxReturn $return, string $reason): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($return, $reason) {
            if ($return->status !== TaxReturnStatus::Filed) {
                throw new RuntimeException('Only filed tax returns can be voided.');
            }

            $return->forceFill([
                'status' => TaxReturnStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
                'void_reason' => $reason,
            ])->save();

            $this->auditRecorder->record(
                (int) $return->company_id,
                AuditAction::TaxReturnVoided,
                $return,
                [
                    'tax_return_no' => $return->tax_return_no,
                    'reason' => $reason,
                    'voided_at' => optional($return->voided_at)->format('Y-m-d H:i:s.u'),
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
