<?php

namespace App\Actions\Tax;

use App\Enums\TaxReturnAdjustmentKind;
use App\Enums\TaxReturnStatus;
use App\Models\Account;
use App\Models\TaxReturn;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Tax\TaxReturnCalculator;
use App\Support\Accounting\ControlAccountRoles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Builds or updates a DRAFT tax return: its header, the journal lines left off
 * it, and its manual adjustments. Shared by the Livewire form and the API.
 * Does NOT file — filing snapshots the contributing journal lines and posts the
 * adjustments, and is the sole responsibility of TaxReturnFiler.
 *
 * Every save also stores the draft's provisional totals, so a draft lists and
 * reads with its figures rather than zeros; filing recomputes them.
 *
 * Expected $data shape (framework-agnostic):
 *   tax_agency_id:    int
 *   tax_return_no:    ?string  (null → auto-generated)
 *   period_start:     string
 *   period_end:       string
 *   filing_reference: ?string
 *   notes:            ?string
 *   excluded_journal_line_ids: ?int[]  (journal lines to omit from the snapshot)
 *   adjustments:      ?list<array{kind: string, account_id: int, amount_cents: int, memo?: ?string}>
 *
 * Omitting `excluded_journal_line_ids` or `adjustments` leaves the saved ones
 * as they are; passing an empty list clears them.
 */
final class SaveTaxReturn
{
    public function __construct(
        protected DocumentNumberGenerator $numbers,
        protected TaxReturnCalculator $calculator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?TaxReturn $taxReturn = null): TaxReturn
    {
        $adjustments = array_key_exists('adjustments', $data)
            ? $this->normalizeAdjustments($data['adjustments'])
            : null;

        return DB::transaction(function () use ($data, $taxReturn, $adjustments): TaxReturn {
            $company = app('current_company');

            $header = [
                'tax_agency_id' => $data['tax_agency_id'],
                'period_start' => CarbonImmutable::parse($data['period_start'])->toDateString(),
                'period_end' => CarbonImmutable::parse($data['period_end'])->toDateString(),
                'filing_reference' => $data['filing_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];

            if (array_key_exists('excluded_journal_line_ids', $data) || ! $taxReturn?->exists) {
                $header['excluded_journal_line_ids'] = $this->normalizeExclusions($data['excluded_journal_line_ids'] ?? null);
            }

            if ($taxReturn && $taxReturn->exists) {
                $taxReturn->forceFill($header)->save();
            } else {
                $taxReturn = TaxReturn::create($header + [
                    'tax_return_no' => $data['tax_return_no']
                        ?? $this->numbers->next($company, TaxReturn::class, 'tax_return_no', 'TR'),
                    'status' => TaxReturnStatus::Draft,
                ]);
            }

            if ($adjustments !== null) {
                $taxReturn->adjustments()->delete();

                foreach ($adjustments as $order => $adjustment) {
                    $taxReturn->adjustments()->create($adjustment + ['line_order' => $order]);
                }
            }

            $figures = $this->calculator->forReturn($taxReturn->unsetRelation('adjustments')->unsetRelation('taxAgency'));

            $taxReturn->forceFill([
                'collected_cents' => $figures->collectedCents,
                'paid_cents' => $figures->paidCents,
                'other_adjustments_cents' => $figures->otherAdjustmentsCents,
                'net_cents' => $figures->netCents,
            ])->save();

            return $taxReturn;
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

    /**
     * Check and clean the adjustment rows. Every row needs a kind, a non-zero
     * amount, and an account of this company that isn't an AR/AP control account
     * — those carry a customer or vendor sub-ledger an adjustment can't name.
     *
     * @param  mixed  $value
     * @return list<array{kind: TaxReturnAdjustmentKind, account_id: int, amount_cents: int, memo: ?string}>
     */
    protected function normalizeAdjustments($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $controlAccounts = ControlAccountRoles::map();
        $rows = [];
        $errors = [];

        foreach (array_values($value) as $i => $row) {
            $kind = TaxReturnAdjustmentKind::tryFrom((string) ($row['kind'] ?? ''));
            $accountId = (int) ($row['account_id'] ?? 0);
            $amount = (int) ($row['amount_cents'] ?? 0);

            if ($kind === null) {
                $errors["adjustments.{$i}.kind"] = __('Choose what the adjustment changes.');
            }

            if ($accountId === 0 || ! Account::query()->whereKey($accountId)->exists()) {
                $errors["adjustments.{$i}.account_id"] = __('Choose an account.');
            } elseif (isset($controlAccounts[$accountId])) {
                $errors["adjustments.{$i}.account_id"] = __('An adjustment can’t be coded to accounts receivable or payable.');
            }

            if ($amount === 0) {
                $errors["adjustments.{$i}.amount_cents"] = __('Enter an amount other than zero.');
            }

            $memo = trim((string) ($row['memo'] ?? ''));

            $rows[] = [
                'kind' => $kind,
                'account_id' => $accountId,
                'amount_cents' => $amount,
                'memo' => $memo === '' ? null : mb_substr($memo, 0, 255),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }
}
