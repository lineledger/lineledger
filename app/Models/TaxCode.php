<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\TaxAppliesTo;
use App\Support\Locales;
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
        return Locales::name($this->code);
    }

    /**
     * The stored name for display. Seeded names are stored in English so
     * lookups stay stable; the UI translates them (a renamed one passes through).
     */
    public function displayName(): string
    {
        return Locales::name($this->name);
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
     * Active sale-eligible codes.
     *
     * @param  Builder<TaxCode>  $query
     * @return Builder<TaxCode>
     */
    public function scopeUsableForSales(Builder $query): Builder
    {
        return $query->where('is_active', true)->forSales();
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
     * What a sales document's tax picker offers: active codes that apply to sales,
     * plus any it already carries, so editing never drops one since deactivated or
     * re-flagged. See {@see TaxCode::idsOnLines()} for the ids to keep.
     *
     * @param  Builder<TaxCode>  $query
     * @param  array<int, int|string|null>  $keepIds
     * @return Builder<TaxCode>
     */
    public function scopeOfferedForSales(Builder $query, array $keepIds = []): Builder
    {
        $keepIds = array_values(array_filter($keepIds));

        return $query->where(function (Builder $q) use ($keepIds) {
            $q->where(fn (Builder $inner) => $inner->where('is_active', true)->forSales())
                ->when($keepIds !== [], fn (Builder $kept) => $kept->orWhereIn('id', $keepIds));
        });
    }

    /**
     * The purchase side of {@see TaxCode::scopeOfferedForSales()}.
     *
     * @param  Builder<TaxCode>  $query
     * @param  array<int, int|string|null>  $keepIds
     * @return Builder<TaxCode>
     */
    public function scopeOfferedForPurchases(Builder $query, array $keepIds = []): Builder
    {
        $keepIds = array_values(array_filter($keepIds));

        return $query->where(function (Builder $q) use ($keepIds) {
            $q->where(fn (Builder $inner) => $inner->where('is_active', true)->forPurchases())
                ->when($keepIds !== [], fn (Builder $kept) => $kept->orWhereIn('id', $keepIds));
        });
    }

    /**
     * Both tax codes every line of a document form carries.
     *
     * @param  array<int|string, array<string, mixed>>  $lines
     * @return array<int, int|string>
     */
    public static function idsOnLines(array $lines): array
    {
        return collect($lines)
            ->flatMap(fn (array $line) => [$line['tax_code_id'] ?? null, $line['secondary_tax_code_id'] ?? null])
            ->filter()
            ->values()
            ->all();
    }

    /**
     * An item, contact or account's default, if a sales document could have picked
     * it: one default serves every document, so it may only apply to purchases.
     */
    public static function defaultForSales(int|string|null $id): ?int
    {
        return self::defaultsForSales($id)[0];
    }

    /**
     * The purchase side of {@see TaxCode::defaultForSales()}.
     */
    public static function defaultForPurchases(int|string|null $id): ?int
    {
        return self::defaultsForPurchases($id)[0];
    }

    /**
     * An item's pair of defaults, for a sales document. A second code that is usable
     * moves up when the first is not, so a line never has a second code and no first.
     *
     * @return array{0: ?int, 1: ?int}
     */
    public static function defaultsForSales(int|string|null $primary, int|string|null $secondary = null): array
    {
        return self::usableDefaults(self::query()->usableForSales(), $primary, $secondary);
    }

    /**
     * The purchase side of {@see TaxCode::defaultsForSales()}.
     *
     * @return array{0: ?int, 1: ?int}
     */
    public static function defaultsForPurchases(int|string|null $primary, int|string|null $secondary = null): array
    {
        return self::usableDefaults(self::query()->usableForPurchases(), $primary, $secondary);
    }

    /**
     * @param  Builder<TaxCode>  $usable
     * @return array{0: ?int, 1: ?int}
     */
    private static function usableDefaults(Builder $usable, int|string|null $primary, int|string|null $secondary): array
    {
        $ids = array_map('intval', array_values(array_filter([$primary, $secondary])));

        if ($ids === []) {
            return [null, null];
        }

        $usableIds = $usable->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $kept = array_values(array_filter($ids, fn (int $id) => in_array($id, $usableIds, true)));

        return [$kept[0] ?? null, $kept[1] ?? null];
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
