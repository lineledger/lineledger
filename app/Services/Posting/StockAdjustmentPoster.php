<?php

namespace App\Services\Posting;

use App\Enums\AuditAction;
use App\Enums\StockAdjustmentReason;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Services\Accounting\OpeningBalanceAccountResolver;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Inventory\InventoryCostingFactory;
use App\Services\Inventory\MovementContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a stock adjustment to the GL and writes the matching stock movements.
 *
 *   Receipt line (qty_change > 0):
 *     DR  Inventory Asset (per-item asset account)   qty * unit_cost
 *     CR    Counter-account                           qty * unit_cost
 *
 *   Issue line (qty_change < 0):
 *     DR  Counter-account                             cost (from costing method)
 *     CR    Inventory Asset (per-item asset account)  cost
 *
 *   Counter-account:
 *     - reason = OpeningBalance: 3000 Opening Balance Equity (subtype Equity)
 *     - otherwise: item.cogs_account_id (defaults to company default_cogs_account_id)
 */
class StockAdjustmentPoster
{
    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
        protected AccountingAuditRecorder $auditRecorder,
        protected InventoryCostingFactory $costingFactory,
    ) {}

    public function post(StockAdjustment $adjustment): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($adjustment) {
            $adjustment->loadMissing('lines.item', 'company');

            if ($adjustment->journal_entry_id) {
                throw AlreadyPostedException::for((int) $adjustment->journal_entry_id);
            }

            if ($adjustment->company->isLockedFor(CarbonImmutable::parse($adjustment->adjustment_date))) {
                throw PeriodLockedException::for(
                    CarbonImmutable::parse($adjustment->adjustment_date),
                    CarbonImmutable::parse($adjustment->company->lock_date),
                );
            }

            if ($adjustment->lines->isEmpty()) {
                throw new RuntimeException('Stock adjustment has no lines; cannot post.');
            }

            foreach ($adjustment->lines as $line) {
                if (! $line->item->track_inventory) {
                    throw new RuntimeException("Item '{$line->item->name}' is not tracked as inventory.");
                }
            }

            $costing = $this->costingFactory->for($adjustment->company);

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($adjustment->company),
                'entry_date' => $adjustment->adjustment_date,
                'memo' => 'Stock adjustment '.$adjustment->adjustment_no.' — '.$adjustment->reason->label(),
                'source_type' => StockAdjustment::class,
                'source_id' => $adjustment->id,
            ]);

            // Per (inventory_asset, counter) pair, accumulate the net DR/CR cents.
            // We later flip the DR/CR roles based on the sign of the net movement.
            $byPair = [];
            $ctx = static fn (int $lineId) => MovementContext::for(
                $adjustment->adjustment_date,
                StockAdjustment::class,
                $adjustment->id,
                $lineId,
                $entry->id,
                $adjustment->notes,
            );

            foreach ($adjustment->lines as $line) {
                $item = $line->item;
                $qty = (float) $line->qty_change;
                $assetAccountId = $this->resolveInventoryAsset($item, $adjustment);
                $counterAccountId = $this->resolveCounterAccount($item, $adjustment);
                $key = $assetAccountId.':'.$counterAccountId.':'.($line->class_id ?? '').':'.($line->location_id ?? '');

                $byPair[$key] ??= [
                    'asset' => $assetAccountId,
                    'counter' => $counterAccountId,
                    'class_id' => $line->class_id,
                    'location_id' => $line->location_id,
                    'net_cost_cents' => 0,
                ];

                if ($qty > 0) {
                    $costing->recordReceipt($item, (string) $line->qty_change, (int) $line->unit_cost_cents, $ctx($line->id));
                    $byPair[$key]['net_cost_cents'] += (int) round($qty * (int) $line->unit_cost_cents);
                } elseif ($qty < 0) {
                    $result = $costing->recordIssue($item, (string) abs($qty), $ctx($line->id));
                    $byPair[$key]['net_cost_cents'] -= (int) $result['cost_cents'];
                }
            }

            $order = 0;
            foreach ($byPair as $pair) {
                $net = $pair['net_cost_cents'];
                if ($net === 0) {
                    continue;
                }

                [$debit, $credit] = $net > 0
                    ? [$pair['asset'], $pair['counter']]
                    : [$pair['counter'], $pair['asset']];

                $abs = abs($net);

                $entry->lines()->create([
                    'account_id' => $debit,
                    'debit_cents' => $abs,
                    'credit_cents' => 0,
                    'line_order' => $order++,
                    'class_id' => $pair['class_id'],
                    'location_id' => $pair['location_id'],
                ]);
                $entry->lines()->create([
                    'account_id' => $credit,
                    'debit_cents' => 0,
                    'credit_cents' => $abs,
                    'line_order' => $order++,
                    'class_id' => $pair['class_id'],
                    'location_id' => $pair['location_id'],
                ]);
            }

            $entry->refresh();

            if (! $entry->isBalanced()) {
                throw new RuntimeException('Stock adjustment journal entry is unbalanced.');
            }

            $this->journalPoster->post($entry);

            // Attach the journal_entry_id to every movement we just created.
            StockMovement::query()
                ->where('source_type', StockAdjustment::class)
                ->where('source_id', $adjustment->id)
                ->whereNull('journal_entry_id')
                ->update(['journal_entry_id' => $entry->id]);

            $adjustment->forceFill([
                'journal_entry_id' => $entry->id,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
            ])->save();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $adjustment->company_id,
                AuditAction::JournalEntryPosted,
                $adjustment,
                [
                    'adjustment_no' => $adjustment->adjustment_no,
                    'adjustment_date' => optional($adjustment->adjustment_date)->toDateString(),
                    'reason' => $adjustment->reason->value,
                    'journal_entry_id' => (int) $entry->id,
                    'journal' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    public function void(StockAdjustment $adjustment, ?CarbonImmutable $voidDate = null): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($adjustment, $voidDate) {
            $adjustment->loadMissing('journalEntry');

            if (! $adjustment->journal_entry_id) {
                throw new RuntimeException('Stock adjustment is not posted.');
            }

            if ($adjustment->voided_at) {
                throw new RuntimeException('Stock adjustment is already voided.');
            }

            $costing = $this->costingFactory->for($adjustment->company);

            $movements = StockMovement::query()
                ->where('source_type', StockAdjustment::class)
                ->where('source_id', $adjustment->id)
                ->whereNull('reversal_of_movement_id')
                ->orderByDesc('id')
                ->get();

            $reverseCtx = MovementContext::for(
                $voidDate ?? now(),
                StockAdjustment::class,
                $adjustment->id,
                null,
                null,
                "Void of adjustment {$adjustment->adjustment_no}",
            );

            foreach ($movements as $movement) {
                $costing->reverse($movement, $reverseCtx);
            }

            $this->journalPoster->void(
                $adjustment->journalEntry,
                $voidDate,
                "Void of stock adjustment {$adjustment->adjustment_no}",
            );

            $adjustment->forceFill([
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
            ])->save();
        }));
    }

    public function nextAdjustmentNumber(Company $company): string
    {
        $last = StockAdjustment::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->orderByDesc('id')
            ->first();

        $next = 1;
        if ($last && preg_match('/ADJ-(\d+)/', $last->adjustment_no, $m)) {
            $next = ((int) $m[1]) + 1;
        }

        return 'ADJ-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    protected function resolveInventoryAsset(Item $item, StockAdjustment $adjustment): int
    {
        $id = $item->inventory_asset_account_id ?? $adjustment->company->default_inventory_asset_account_id;

        if (! $id) {
            throw new RuntimeException("No inventory asset account configured for item '{$item->name}'.");
        }

        return (int) $id;
    }

    protected function resolveCounterAccount(Item $item, StockAdjustment $adjustment): int
    {
        if ($adjustment->reason === StockAdjustmentReason::OpeningBalance) {
            return $this->openingBalanceEquityAccount($adjustment)->id;
        }

        $id = $item->cogs_account_id ?? $adjustment->company->default_cogs_account_id;

        if (! $id) {
            throw new RuntimeException("No COGS / adjustment account configured for item '{$item->name}'.");
        }

        return (int) $id;
    }

    protected function openingBalanceEquityAccount(StockAdjustment $adjustment): Account
    {
        $account = app(OpeningBalanceAccountResolver::class)->resolve((int) $adjustment->company_id);

        if (! $account) {
            throw new RuntimeException("Missing 'Opening Balance Equity' account for company {$adjustment->company_id}.");
        }

        return $account;
    }
}
