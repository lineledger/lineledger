<?php

namespace App\Services\Banking;

use App\Enums\AccountSubtype;
use App\Models\BankStatementLine;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Finds the two legs of an inter-account transfer hiding in the review feed: an
 * outflow on one bank/credit-card account and a matching inflow on another, of
 * equal magnitude, same currency, within a few days. Each leg is paired at most
 * once, closest-dated first. Cross-currency movements (unequal magnitudes) and
 * same-account pairs are ignored.
 */
class TransferPairMatcher
{
    private const WINDOW_DAYS = 3;

    /**
     * @return Collection<int, array{out: BankStatementLine, in: BankStatementLine, days: int}>
     */
    public function candidates(Company $company): Collection
    {
        $lines = BankStatementLine::query()
            ->forReview()
            ->whereHas('account', fn ($q) => $q->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value]))
            ->with('account:id,code,name,currency_code')
            ->orderBy('txn_date')
            ->orderBy('id')
            ->get();

        $outs = $lines->filter(fn (BankStatementLine $l): bool => (int) $l->amount_cents < 0)->values();
        $ins = $lines->filter(fn (BankStatementLine $l): bool => (int) $l->amount_cents > 0)->values();

        $usedIn = [];
        $pairs = collect();

        foreach ($outs as $out) {
            $match = $ins
                ->reject(fn (BankStatementLine $in): bool => in_array($in->id, $usedIn, true))
                ->filter(fn (BankStatementLine $in): bool => $in->account_id !== $out->account_id
                    && (int) $in->amount_cents === -(int) $out->amount_cents
                    && ($in->account->currency_code ?? '') === ($out->account->currency_code ?? '')
                    && abs($this->dayDiff($in, $out)) <= self::WINDOW_DAYS)
                ->sortBy(fn (BankStatementLine $in): int => abs($this->dayDiff($in, $out)))
                ->first();

            if ($match !== null) {
                $usedIn[] = $match->id;
                $pairs->push(['out' => $out, 'in' => $match, 'days' => abs($this->dayDiff($match, $out))]);
            }
        }

        return $pairs->values();
    }

    private function dayDiff(BankStatementLine $a, BankStatementLine $b): int
    {
        return (int) CarbonImmutable::parse($a->txn_date)->diffInDays(CarbonImmutable::parse($b->txn_date), false);
    }
}
