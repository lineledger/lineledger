<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Cast an integer `*_cents` column to a Money value object.
 * Currency comes from a sibling column (defaulting to "currency_code"),
 * or can be passed explicitly: `'amount_cents' => MoneyCast::class.':USD'`
 * `'amount_cents' => MoneyCast::class.':via:currency'` to read from a different column.
 */
class MoneyCast implements CastsAttributes
{
    public function __construct(protected ?string $currencyArg = null) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::fromCents((int) $value, $this->resolveCurrency($model, $attributes));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if ($value instanceof Money) {
            return [$key => $value->cents];
        }

        if (is_int($value)) {
            return [$key => $value];
        }

        if (is_string($value)) {
            return [$key => Money::fromString($value, $this->resolveCurrency($model, $attributes))->cents];
        }

        throw new InvalidArgumentException('MoneyCast expects an int, string, or Money value.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function resolveCurrency(Model $model, array $attributes): string
    {
        if ($this->currencyArg !== null) {
            if (str_starts_with($this->currencyArg, 'via:')) {
                $column = substr($this->currencyArg, 4);

                return (string) ($attributes[$column] ?? $model->getAttribute($column) ?? 'CAD');
            }

            return $this->currencyArg;
        }

        if (isset($attributes['currency_code'])) {
            return (string) $attributes['currency_code'];
        }

        $company = method_exists($model, 'company') ? $model->company : null;

        return $company?->currency_code ?? 'CAD';
    }
}
