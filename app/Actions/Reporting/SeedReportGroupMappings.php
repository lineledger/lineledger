<?php

namespace App\Actions\Reporting;

use App\Models\Account;
use App\Models\ReportGroup;
use App\Models\ReportGroupAccountMap;
use App\Models\ReportGroupLine;
use Illuminate\Support\Collection;

/**
 * Auto-maps a report group's member accounts onto combined lines by matching
 * account code. Idempotent and additive: only accounts not yet mapped are
 * touched, so user curation (renamed lines, net lines) survives a re-seed and
 * newly-added companies get picked up.
 *
 * Accounts that share a normalized code collapse onto one line; an account whose
 * code is unique within the group becomes its own passthrough line.
 */
class SeedReportGroupMappings
{
    public function handle(ReportGroup $group): void
    {
        $companyIds = $group->companyIds();

        if ($companyIds->isEmpty()) {
            return;
        }

        $alreadyMapped = $group->accountMaps()->pluck('account_id')->all();

        $accounts = Account::withoutGlobalScopes()
            ->whereIn('company_id', $companyIds)
            ->whereNotIn('id', $alreadyMapped)
            ->orderBy('code')
            ->get();

        if ($accounts->isEmpty()) {
            return;
        }

        $lineByCode = $this->existingLinesByCode($group);
        $nextSort = (int) $group->lines()->max('sort_order') + 1;

        $accounts
            ->groupBy(fn (Account $account) => $this->normalizeCode($account->code))
            ->each(function (Collection $group_accounts, string $code) use ($group, &$lineByCode, &$nextSort) {
                $line = $lineByCode[$code] ?? null;

                if ($line === null) {
                    /** @var Account $first */
                    $first = $group_accounts->first();

                    $line = ReportGroupLine::create([
                        'report_group_id' => $group->id,
                        'name' => $first->name,
                        'type' => $first->type,
                        'subtype' => $first->subtype,
                        'sort_order' => $nextSort++,
                        'is_passthrough' => $group_accounts->count() === 1,
                    ]);

                    $lineByCode[$code] = $line;
                }

                foreach ($group_accounts as $account) {
                    ReportGroupAccountMap::create([
                        'report_group_id' => $group->id,
                        'report_group_line_id' => $line->id,
                        'company_id' => $account->company_id,
                        'account_id' => $account->id,
                    ]);
                }
            });
    }

    /**
     * Map normalized account code → the line that already collects accounts of
     * that code, so re-seeding routes new accounts to the matching line.
     *
     * @return array<string, ReportGroupLine>
     */
    protected function existingLinesByCode(ReportGroup $group): array
    {
        $maps = $group->accountMaps()->with('account', 'line')->get();

        $byCode = [];

        foreach ($maps as $map) {
            if ($map->account === null || $map->line === null) {
                continue;
            }

            $byCode[$this->normalizeCode($map->account->code)] ??= $map->line;
        }

        return $byCode;
    }

    protected function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }
}
