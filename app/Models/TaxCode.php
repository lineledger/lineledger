<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\TaxAppliesTo;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Larastan does not infer the enum cast on `applies_to`, which makes every
 * `match` on it look unreachable at the call sites. Annotated explicitly, the
 * same way {@see Company} handles its own mis-inferred attributes.
 *
 * @property TaxAppliesTo|null $applies_to
 */
#[Fillable(['company_id', 'code', 'name', 'rate_basis_points', 'agency_id', 'is_recoverable', 'applies_to', 'is_active'])]
class TaxCode extends Model
{
    use BelongsToCompany;

    /**
     * @return BelongsTo<TaxAgency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(TaxAgency::class, 'agency_id');
    }

    /**
     * Tax for an amount in cents, rounded half-up. The rate is held in basis
     * points (1% = 100bp) and may carry up to three decimals — e.g. QST is
     * 997.5bp — so the result is still an exact integer-cent amount.
     */
    public function taxFor(int $subtotalCents): int
    {
        if ((float) $this->rate_basis_points === 0.0) {
            return 0;
        }

        return (int) round(($subtotalCents * (float) $this->rate_basis_points) / 10000);
    }

    public function ratePercent(): float
    {
        return (float) $this->rate_basis_points / 100;
    }

    /**
     * Format a tax rate percentage for display. Whole and standard rates keep two
     * decimal places (e.g. 5.00%), while rates carrying fractional hundredths
     * (such as Quebec QST at 9.975%) preserve them without rounding.
     */
    public static function formatRate(float|int|string|null $rate): string
    {
        $rate = (float) $rate;

        if (abs(round($rate, 2) - $rate) > 0.00001) {
            return rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.');
        }

        return number_format($rate, 2);
    }

    public function formattedRate(): string
    {
        return self::formatRate($this->ratePercent());
    }

    /**
     * Locale-aware label for pickers. Stored codes stay stable (QST-QC);
     * French UI shows TVQ, TPS, TVH, …
     */
    public function label(): string
    {
        return __($this->code);
    }

    /**
     * Codes selectable on purchase documents (bills, expenses, cheques,
     * purchase orders, vendor credits): those flagged purchase-only or both.
     * A sale-only code must never be offered when coding an expense.
     *
     * @param  Builder<TaxCode>  $query
     * @return Builder<TaxCode>
     */
    public function scopeForPurchases(Builder $query): Builder
    {
        return $query->whereIn('applies_to', [
            TaxAppliesTo::PurchaseOnly->value,
            TaxAppliesTo::Both->value,
        ]);
    }

    /**
     * Active purchase-eligible codes — what a bank-import row may apply.
     *
     * @param  Builder<TaxCode>  $query
     * @return Builder<TaxCode>
     */
    public function scopeUsableForPurchases(Builder $query): Builder
    {
        return $query->where('is_active', true)->forPurchases();
    }

    /**
     * Codes selectable on sales documents (invoices, sales receipts, estimates,
     * credit memos): those flagged sale-only or both.
     *
     * @param  Builder<TaxCode>  $query
     * @return Builder<TaxCode>
     */
    public function scopeForSales(Builder $query): Builder
    {
        return $query->whereIn('applies_to', [
            TaxAppliesTo::SaleOnly->value,
            TaxAppliesTo::Both->value,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_basis_points' => 'float',
            'is_recoverable' => 'boolean',
            'applies_to' => TaxAppliesTo::class,
            'is_active' => 'boolean',
        ];
    }
}
