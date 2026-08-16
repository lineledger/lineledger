<?php

namespace App\Actions\Tax;

use App\Enums\TaxReturnStatus;
use App\Models\TaxReturn;
use App\Services\Posting\DocumentNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds or updates a DRAFT tax return header. Shared by the Livewire form and
 * the API. Does NOT file — filing snapshots the contributing journal lines and
 * is the sole responsibility of TaxReturnFiler. Lines are never built here.
 *
 * Expected $data shape (framework-agnostic):
 *   tax_agency_id:    int
 *   tax_return_no:    ?string  (null → auto-generated)
 *   period_start:     string
 *   period_end:       string
 *   filing_reference: ?string
 *   notes:            ?string
 *   excluded_journal_line_ids: ?int[]  (journal lines to omit from the snapshot)
 */
final class SaveTaxReturn
{
    public function __construct(protected DocumentNumberGenerator $numbers) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?TaxReturn $taxReturn = null): TaxReturn
    {
        return DB::transaction(function () use ($data, $taxReturn): TaxReturn {
            $company = app('current_company');

            $header = [
                'tax_agency_id' => $data['tax_agency_id'],
                'period_start' => CarbonImmutable::parse($data['period_start'])->toDateString(),
                'period_end' => CarbonImmutable::parse($data['period_end'])->toDateString(),
                'filing_reference' => $data['filing_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'excluded_journal_line_ids' => $this->normalizeExclusions($data['excluded_journal_line_ids'] ?? null),
            ];

            if ($taxReturn && $taxReturn->exists) {
                $taxReturn->forceFill($header)->save();

                return $taxReturn;
            }

            return TaxReturn::create($header + [
                'tax_return_no' => $data['tax_return_no']
                    ?? $this->numbers->next($company, TaxReturn::class, 'tax_return_no', 'TR'),
                'status' => TaxReturnStatus::Draft,
            ]);
        });
    }

    /**
     * Coerce the excluded-line input into a clean, de-duplicated list of ints.
     * Null/empty collapses to an empty array so the column is never left stale.
     *
     * @param  mixed  $value
     * @return int[]
     */
    protected function normalizeExclusions($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $value)));
    }
}
