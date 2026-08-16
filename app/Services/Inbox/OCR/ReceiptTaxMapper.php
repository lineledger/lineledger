<?php

namespace App\Services\Inbox\OCR;

use App\Jobs\Inbox\ProcessInboxItem;
use App\Models\TaxCode;
use Illuminate\Support\Collection;

/**
 * Ties the raw tax lines an OCR read off a receipt (label, rate, amount) to the
 * company's own purchase tax codes, so the review screen can pre-select the
 * right code and break the GST/HST out as an Input Tax Credit.
 *
 * Runs inside {@see ProcessInboxItem}, where the tenant is bound,
 * so TaxCode queries are already company-scoped. It only ever matches a
 * RECOVERABLE code (a real ITC); an unrecognised levy (a tip, a non-recoverable
 * surcharge) is left with tax_code_id = null for the user to classify by hand.
 */
class ReceiptTaxMapper
{
    /**
     * Enrich each `extracted['taxes']` entry with a matching `tax_code_id`
     * (or null). All other keys pass through untouched.
     *
     * @param  array<string, mixed>  $extracted
     * @return array<string, mixed>
     */
    public function map(array $extracted): array
    {
        $taxes = $extracted['taxes'] ?? null;

        if (! is_array($taxes) || $taxes === []) {
            return $extracted;
        }

        $codes = TaxCode::query()
            ->where('is_active', true)
            ->forPurchases()
            ->get();

        $extracted['taxes'] = array_map(function ($tax) use ($codes) {
            if (! is_array($tax)) {
                return $tax;
            }

            $tax['tax_code_id'] = $this->matchCodeId($codes, $tax);

            return $tax;
        }, $taxes);

        return $extracted;
    }

    /**
     * Match an extracted tax line to a recoverable purchase tax code: first by an
     * exact rate match, then by label/code (e.g. "GST", "HST").
     *
     * @param  Collection<int, TaxCode>  $codes
     * @param  array<string, mixed>  $tax
     */
    protected function matchCodeId(Collection $codes, array $tax): ?int
    {
        $recoverable = $codes->filter(fn (TaxCode $c) => (bool) $c->is_recoverable);

        $rateBp = isset($tax['rate_bp']) ? (int) $tax['rate_bp'] : null;

        if ($rateBp !== null && $rateBp > 0) {
            $byRate = $recoverable->first(
                fn (TaxCode $c) => (int) round((float) $c->rate_basis_points) === $rateBp
            );

            if ($byRate !== null) {
                return $byRate->id;
            }
        }

        $label = isset($tax['label']) ? mb_strtoupper(trim((string) $tax['label'])) : '';

        if ($label !== '') {
            $byLabel = $recoverable->first(function (TaxCode $c) use ($label) {
                $code = mb_strtoupper((string) $c->code);

                return $code !== '' && ($code === $label || str_contains($label, $code) || str_contains($code, $label));
            });

            if ($byLabel !== null) {
                return $byLabel->id;
            }
        }

        return null;
    }
}
