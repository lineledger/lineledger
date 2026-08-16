<?php

namespace App\Services\Assets;

use App\Actions\Accounting\SaveJournalEntry;
use App\Models\Asset;
use App\Models\AssetDepreciationEntry;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\Recurring\RecurringJournalEntryGenerator;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns the asset register into Draft monthly book-depreciation journal
 * entries by feeding the existing SaveJournalEntry action — one draft per
 * company per uncovered month, bundling every due asset as a debit
 * (depreciation expense) / credit (accumulated depreciation) line pair.
 * Never posts to the general ledger — a human reviews and posts each draft.
 *
 * Mirrors {@see RecurringJournalEntryGenerator}: binds
 * app('current_company') for the action call (not bound in a job/command).
 * Idempotency lives in asset_depreciation_entries (one row per asset per
 * month); its unique(asset_id, period) index is the concurrency backstop — a
 * racing worker aborts its per-month transaction and the next run reconciles.
 *
 * Skip rules: months that haven't ended yet (company-timezone today), months
 * whose end falls on or before the lock date (permanently — record those
 * manually), months from the disposal month onward, and months already
 * covered by a pivot row. Voided entries keep their pivot rows, so voided
 * months never regenerate; deleted drafts cascade their rows away and do.
 */
class DepreciationGenerator
{
    /**
     * Hard cap on catch-up months generated per asset in a single run,
     * guarding against a pathological backdated in-service date flooding the
     * journal. Mirrors the recurring generator's MAX_CATCHUP.
     */
    protected const MAX_CATCHUP = 60;

    /**
     * Generate every uncovered, ended month for the company's auto-depreciating
     * assets, grouped into one draft journal entry per month.
     *
     * @return Collection<int, JournalEntry>
     */
    public function generateDue(Company $company, CarbonImmutable $today): Collection
    {
        $created = collect();
        $today = $today->startOfDay();

        $assets = Asset::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('auto_depreciate', true)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (Asset $asset): bool => $asset->isAutoDepreciable());

        if ($assets->isEmpty()) {
            return $created;
        }

        $covered = AssetDepreciationEntry::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('asset_id', $assets->pluck('id'))
            ->get(['asset_id', 'period'])
            ->groupBy('asset_id')
            ->map(fn (Collection $rows): Collection => $rows
                ->mapWithKeys(fn (AssetDepreciationEntry $row): array => [$row->period->format('Y-m-d') => true]));

        /** @var array<string, list<array{asset: Asset, amount_cents: int}>> $due keyed by first-of-month Y-m-d */
        $due = [];

        foreach ($assets as $asset) {
            /** @var Collection<string, bool> $coveredPeriods */
            $coveredPeriods = $covered->get($asset->id, collect());
            $disposalMonth = $asset->disposed_at !== null
                ? CarbonImmutable::parse($asset->disposed_at->toDateString())->startOfMonth()
                : null;
            $uncovered = 0;

            foreach (DepreciationSchedule::for($asset) as $row) {
                /** @var CarbonImmutable $period */
                $period = $row['period'];

                // Only months that have fully ended (company-timezone) are due;
                // schedule rows are ascending so the rest are unfinished too.
                if ($period->endOfMonth()->startOfDay()->greaterThanOrEqualTo($today)) {
                    break;
                }

                // No depreciation in or after the disposal month.
                if ($disposalMonth !== null && $period->greaterThanOrEqualTo($disposalMonth)) {
                    break;
                }

                if ($coveredPeriods->has($period->format('Y-m-d'))) {
                    continue;
                }

                // Locked months are permanently skipped — record them manually.
                if ($company->isLockedFor($period->endOfMonth()->startOfDay())) {
                    continue;
                }

                // A zero month (base rounds to 0¢) has nothing to post; leave it
                // uncovered so the schedule still totals via the final month.
                if ((int) $row['amount_cents'] === 0) {
                    continue;
                }

                if (++$uncovered > self::MAX_CATCHUP) {
                    Log::warning('Asset depreciation hit catch-up cap.', [
                        'asset_id' => $asset->id,
                        'company_id' => $company->id,
                    ]);
                    break;
                }

                $due[$period->format('Y-m-d')][] = [
                    'asset' => $asset,
                    'amount_cents' => (int) $row['amount_cents'],
                ];
            }
        }

        ksort($due);

        foreach ($due as $periodKey => $items) {
            $created->push(DB::transaction(
                fn (): JournalEntry => $this->createDraftFor($company, CarbonImmutable::parse($periodKey), $items)
            ));
        }

        return $created;
    }

    /**
     * Create one draft journal entry for the month plus its idempotency rows.
     *
     * @param  list<array{asset: Asset, amount_cents: int}>  $items
     */
    protected function createDraftFor(Company $company, CarbonImmutable $period, array $items): JournalEntry
    {
        $lines = [];

        foreach ($items as $item) {
            $asset = $item['asset'];
            $memo = $asset->asset_no.' — '.$asset->name;

            $lines[] = [
                'account_id' => $asset->depreciation_expense_account_id,
                'debit_cents' => $item['amount_cents'],
                'credit_cents' => 0,
                'memo' => $memo,
                'contact_id' => null,
            ];
            $lines[] = [
                'account_id' => $asset->accumulated_depreciation_account_id,
                'debit_cents' => 0,
                'credit_cents' => $item['amount_cents'],
                'memo' => $memo,
                'contact_id' => null,
            ];
        }

        $entry = $this->withCompany($company, fn (): JournalEntry => app(SaveJournalEntry::class)->handle([
            'entry_no' => null,
            'entry_date' => $period->endOfMonth()->toDateString(),
            'memo' => 'Monthly depreciation — '.$period->format('F Y'),
            'lines' => $lines,
        ]));

        $now = now();

        AssetDepreciationEntry::query()->insert(array_map(fn (array $item): array => [
            'company_id' => $company->id,
            'asset_id' => $item['asset']->id,
            'journal_entry_id' => $entry->id,
            'period' => $period->format('Y-m-d'),
            'amount_cents' => $item['amount_cents'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $items));

        return $entry;
    }

    /**
     * Bind $company as the current tenant for the closure, then restore whatever
     * (if anything) was bound before — so SaveJournalEntry and the global company
     * scope behave correctly inside a job.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function withCompany(Company $company, Closure $callback): mixed
    {
        $previous = app()->bound('current_company') ? app('current_company') : null;
        app()->instance('current_company', $company);

        try {
            return $callback();
        } finally {
            if ($previous !== null) {
                app()->instance('current_company', $previous);
            } else {
                app()->forgetInstance('current_company');
            }
        }
    }
}
