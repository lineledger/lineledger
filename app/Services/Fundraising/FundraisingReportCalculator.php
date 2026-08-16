<?php

namespace App\Services\Fundraising;

use App\Enums\DonationStatus;
use App\Enums\GrantStatus;
use App\Models\Company;
use App\Models\Donation;
use App\Models\Grant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read-side aggregates for the fundraising reports. Pure, testable math over the
 * posted Donation and Grant records, independent of the Livewire report views.
 */
class FundraisingReportCalculator
{
    /**
     * Posted donation totals per donor over the period.
     *
     * @return Collection<int, array{donor: string, count: int, total_cents: int}>
     */
    public function donationsByDonor(Company $company, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Donation::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', DonationStatus::Posted->value)
            ->whereBetween('donation_date', [$from->toDateString(), $to->toDateString()])
            ->with('contact:id,display_name')
            ->get(['id', 'contact_id', 'amount_cents'])
            ->groupBy(fn (Donation $d) => $d->contact?->display_name ?? __('Anonymous'))
            ->map(fn (Collection $group, string $donor): array => [
                'donor' => $donor,
                'count' => $group->count(),
                'total_cents' => (int) $group->sum('amount_cents'),
            ])
            ->sortByDesc('total_cents')
            ->values();
    }

    /**
     * Posted restricted-donation totals per fund over the period.
     *
     * @return Collection<int, array{fund: string, total_cents: int}>
     */
    public function donationsByFund(Company $company, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Donation::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', DonationStatus::Posted->value)
            ->whereNotNull('fund_id')
            ->whereBetween('donation_date', [$from->toDateString(), $to->toDateString()])
            ->with('fund:id,name')
            ->get(['id', 'fund_id', 'amount_cents'])
            ->groupBy(fn (Donation $d) => $d->fund?->name ?? __('Unassigned'))
            ->map(fn (Collection $group, string $fund): array => [
                'fund' => $fund,
                'total_cents' => (int) $group->sum('amount_cents'),
            ])
            ->sortByDesc('total_cents')
            ->values();
    }

    /**
     * Every non-void grant with award, recognized-to-date, and deferred balance.
     *
     * @return Collection<int, array{grant_no: string, name: string, funder: string, award_cents: int, recognized_cents: int, deferred_cents: int, status: string}>
     */
    public function grantsSummary(Company $company): Collection
    {
        return Grant::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', '!=', GrantStatus::Void->value)
            ->with('funder:id,display_name')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Grant $g): array => [
                'grant_no' => $g->grant_no,
                'name' => $g->name,
                'funder' => $g->funder?->display_name ?? '—',
                'award_cents' => (int) $g->award_amount_cents,
                'recognized_cents' => (int) $g->recognized_to_date_cents,
                'deferred_cents' => $g->deferredBalanceCents(),
                'status' => $g->status->label(),
            ])
            ->values();
    }
}
