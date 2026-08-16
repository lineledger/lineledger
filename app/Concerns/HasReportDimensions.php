<?php

namespace App\Concerns;

use App\Models\Classification;
use App\Models\Fund;
use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * Shared class/location dimension filters for reports. Only surfaced when the
 * company has the corresponding feature enabled. The effective* helpers return
 * the filter only when its feature is on, so a stale URL param can't leak a
 * dimension filter into a company that doesn't track it.
 *
 * The host component must expose a public Company $company.
 */
trait HasReportDimensions
{
    #[Url(as: 'class')]
    public ?int $classId = null;

    #[Url(as: 'location')]
    public ?int $locationId = null;

    #[Url(as: 'fund')]
    public ?int $fundId = null;

    /**
     * @return Collection<int, Classification>
     */
    #[Computed]
    public function classificationOptions(): Collection
    {
        return Classification::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return Collection<int, Location>
     */
    #[Computed]
    public function locationOptions(): Collection
    {
        return Location::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return Collection<int, Fund>
     */
    #[Computed]
    public function fundOptions(): Collection
    {
        return Fund::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'fund_type']);
    }

    #[Computed]
    public function tracksClasses(): bool
    {
        return (bool) $this->company->features_classes;
    }

    #[Computed]
    public function tracksLocations(): bool
    {
        return (bool) $this->company->features_locations;
    }

    #[Computed]
    public function tracksFunds(): bool
    {
        return $this->company->tracksFunds();
    }

    #[Computed]
    public function hasDimensions(): bool
    {
        return $this->tracksClasses || $this->tracksLocations || $this->tracksFunds;
    }

    public function effectiveClassId(): ?int
    {
        return $this->tracksClasses ? $this->classId : null;
    }

    public function effectiveLocationId(): ?int
    {
        return $this->tracksLocations ? $this->locationId : null;
    }

    public function effectiveFundId(): ?int
    {
        return $this->tracksFunds() ? $this->fundId : null;
    }
}
