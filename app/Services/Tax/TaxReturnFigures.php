<?php

namespace App\Services\Tax;

use App\Enums\SalesTaxBucket;
use App\Enums\TaxReturnAdjustmentKind;
use Illuminate\Support\Collection;

/**
 * A tax return's figures and its reconciliation to the agency's payable
 * account, as {@see TaxReturnCalculator} works them out. Everything is integer
 * cents.
 *
 * The reconciliation lists each movement on the payable account as its effect
 * on what is owed (credit − debit): tax collected is positive, input tax
 * credits and payments are negative. It adds up by construction — the opening
 * balance plus every movement is the ledger's closing balance — and the
 * difference is what the ledger will say is owed after filing, less what the
 * return says is owed.
 */
final readonly class TaxReturnFigures
{
    /**
     * @param  Collection<int, covariant array<string, mixed>>  $lines  every classified line in the period, each flagged `excluded`
     * @param  list<array{kind: TaxReturnAdjustmentKind, account_id: int, amount_cents: int, memo: ?string, effect_cents: int, posts: bool}>  $adjustments
     * @param  array<string, int>  $reconciliation
     */
    public function __construct(
        public Collection $lines,
        public array $adjustments,
        public int $collectedCents,
        public int $paidCents,
        public int $otherAdjustmentsCents,
        public int $netCents,
        public array $reconciliation,
    ) {}

    /**
     * The collected / paid lines a return is built from, excluded ones included
     * (flagged) so a form can offer to put them back.
     *
     * @return Collection<int, covariant array<string, mixed>>
     */
    public function returnLines(): Collection
    {
        return $this->lines
            ->filter(fn (array $line) => SalesTaxBucket::from($line['bucket'])->isOnReturn())
            ->values();
    }

    /**
     * The lines filing snapshots: on the return and not excluded.
     *
     * @return Collection<int, covariant array<string, mixed>>
     */
    public function includedLines(): Collection
    {
        return $this->returnLines()->reject(fn (array $line) => $line['excluded'])->values();
    }

    /**
     * Payments, earlier returns' adjustment entries and opening-balance entries
     * — movements on the payable account that belong in the reconciliation but
     * never on the return.
     *
     * @return Collection<int, covariant array<string, mixed>>
     */
    public function ledgerOnlyLines(): Collection
    {
        return $this->lines
            ->reject(fn (array $line) => SalesTaxBucket::from($line['bucket'])->isOnReturn())
            ->values();
    }

    /**
     * The adjustments filing posts to the ledger: those coded to an account
     * other than the agency's payable account.
     *
     * @return list<array{kind: TaxReturnAdjustmentKind, account_id: int, amount_cents: int, memo: ?string, effect_cents: int, posts: bool}>
     */
    public function postingAdjustments(): array
    {
        return array_values(array_filter($this->adjustments, fn (array $adjustment) => $adjustment['posts']));
    }

    public function differenceCents(): int
    {
        return $this->reconciliation['difference_cents'];
    }

    public function reconciles(): bool
    {
        return $this->differenceCents() === 0;
    }
}
