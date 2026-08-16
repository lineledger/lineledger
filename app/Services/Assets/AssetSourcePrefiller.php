<?php

namespace App\Services\Assets;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Cheque;
use App\Models\ChequeLine;
use App\Models\JournalEntry;
use App\Models\JournalLine;

/**
 * Resolves an asset form's initial values from a source transaction line —
 * typically a Bill, Cheque, or Journal Entry line that hits a FixedAsset account.
 *
 * Returns the polymorphic source pair plus pre-fill data for the asset form.
 */
class AssetSourcePrefiller
{
    /**
     * @return array{
     *     source_type: ?string,
     *     source_id: ?int,
     *     name: ?string,
     *     description: ?string,
     *     asset_account_id: ?int,
     *     acquired_date: ?string,
     *     cost_cents: ?int
     * }|null
     */
    public function resolve(string $type, int $id): ?array
    {
        return match ($type) {
            'bill_line' => $this->fromBillLine($id),
            'cheque_line' => $this->fromChequeLine($id),
            'journal_line' => $this->fromJournalLine($id),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fromBillLine(int $id): ?array
    {
        $line = BillLine::query()->with(['bill', 'account'])->find($id);

        if (! $line || ! $this->isFixedAssetAccount($line->account_id)) {
            return null;
        }

        return [
            'source_type' => Bill::class,
            'source_id' => $line->bill_id,
            'name' => $line->description ?: ($line->bill->vendor_reference ?? null),
            'description' => $line->description,
            'asset_account_id' => (int) $line->account_id,
            'acquired_date' => $line->bill?->bill_date?->toDateString(),
            'cost_cents' => (int) $line->line_subtotal_cents,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fromChequeLine(int $id): ?array
    {
        $line = ChequeLine::query()->with(['cheque', 'account'])->find($id);

        if (! $line || ! $this->isFixedAssetAccount($line->account_id)) {
            return null;
        }

        return [
            'source_type' => Cheque::class,
            'source_id' => $line->cheque_id,
            'name' => $line->description ?: ($line->cheque->payee_name ?? null),
            'description' => $line->description,
            'asset_account_id' => (int) $line->account_id,
            'acquired_date' => $line->cheque?->cheque_date?->toDateString(),
            'cost_cents' => (int) $line->amount_cents,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fromJournalLine(int $id): ?array
    {
        $line = JournalLine::query()->with(['journalEntry', 'account'])->find($id);

        if (! $line || ! $this->isFixedAssetAccount($line->account_id)) {
            return null;
        }

        return [
            'source_type' => JournalEntry::class,
            'source_id' => $line->journal_entry_id,
            'name' => $line->memo,
            'description' => $line->memo,
            'asset_account_id' => (int) $line->account_id,
            'acquired_date' => $line->journalEntry?->entry_date?->toDateString(),
            'cost_cents' => (int) $line->debit_cents,
        ];
    }

    protected function isFixedAssetAccount(?int $accountId): bool
    {
        if (! $accountId) {
            return false;
        }

        $account = Account::query()->withoutGlobalScopes()->find($accountId);

        return $account && $account->subtype === AccountSubtype::FixedAsset;
    }
}
