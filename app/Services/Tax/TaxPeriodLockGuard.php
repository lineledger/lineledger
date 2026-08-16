<?php

namespace App\Services\Tax;

use App\Enums\TaxReturnStatus;
use App\Exceptions\Posting\TaxPeriodFiledException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Blocks posting (or reposting) of a transaction whose tax codes belong to an
 * agency that already has a Filed tax return covering the entry date. The
 * filed snapshot must remain a faithful record of what was reported, so no new
 * postings or backdated edits inside that period are allowed.
 */
class TaxPeriodLockGuard
{
    /**
     * @param  iterable<int|null>  $taxCodeIds  Tax-code IDs touched by the transaction (nulls are ignored).
     */
    public function ensureNotFiled(int $companyId, iterable $taxCodeIds, CarbonInterface $entryDate): void
    {
        $ids = collect($taxCodeIds)
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $row = DB::table('tax_returns')
            ->join('tax_codes', 'tax_codes.agency_id', '=', 'tax_returns.tax_agency_id')
            ->join('tax_agencies', 'tax_agencies.id', '=', 'tax_returns.tax_agency_id')
            ->where('tax_returns.company_id', $companyId)
            ->where('tax_returns.status', TaxReturnStatus::Filed->value)
            ->whereNull('tax_returns.deleted_at')
            ->whereIn('tax_codes.id', $ids)
            ->whereDate('tax_returns.period_start', '<=', $entryDate)
            ->whereDate('tax_returns.period_end', '>=', $entryDate)
            ->select([
                'tax_agencies.name as agency_name',
                'tax_returns.period_start',
                'tax_returns.period_end',
            ])
            ->first();

        if ($row === null) {
            return;
        }

        throw TaxPeriodFiledException::for(
            (string) $row->agency_name,
            CarbonImmutable::parse($entryDate),
            CarbonImmutable::parse($row->period_start),
            CarbonImmutable::parse($row->period_end),
        );
    }
}
