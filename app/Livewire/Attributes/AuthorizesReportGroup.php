<?php

namespace App\Livewire\Attributes;

use App\Models\ReportGroup;
use Attribute;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportAttributes\Attribute as LivewireAttribute;

/**
 * Put on a combined report-group page to re-authorize it on every Livewire
 * request:
 *
 *     new #[AuthorizesReportGroup('update')] class extends Component
 *
 * Report groups span companies, so their routes sit outside the `{company}`
 * group and none of the persistent tenant middleware (membership, section
 * access) replays on a Livewire update. Each page authorizes in mount(); this
 * hydrate hook repeats the ReportGroupPolicy check before any later action or
 * re-render, so a viewer who loses Reports access — or is removed from a member
 * company — after the page loaded cannot keep reading or editing the group.
 *
 * The page holds the group in `public ReportGroup $reportGroup`; a page without
 * one is refused rather than left unchecked.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AuthorizesReportGroup extends LivewireAttribute
{
    /**
     * @param  string  $ability  the ReportGroupPolicy ability the page needs
     */
    public function __construct(public string $ability = 'view') {}

    public function hydrate(): void
    {
        $group = data_get($this->component, 'reportGroup');

        abort_unless($group instanceof ReportGroup, 403);

        Gate::authorize($this->ability, $group);
    }
}
