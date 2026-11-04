<?php

namespace App\Support\Access;

use App\Enums\Activity;
use App\Enums\PermissionAction;
use App\Enums\Section;
use App\Services\Access\PermissionResolver;

/**
 * Everything one member may do in one company: a grant per activity, combined
 * across every role they hold (the most permissive wins), or everything for
 * the Owner. Immutable; resolve it with {@see PermissionResolver}.
 */
final class PermissionSet
{
    /**
     * @param  array<string, Grant>  $grants  activity value => grant (None omitted)
     */
    private function __construct(
        private readonly bool $owner,
        private readonly array $grants,
    ) {}

    /**
     * The company Owner: every action in every activity.
     */
    public static function owner(): self
    {
        return new self(true, []);
    }

    /**
     * No access at all (not a member).
     */
    public static function none(): self
    {
        return new self(false, []);
    }

    /**
     * @param  iterable<Activity|string, Grant>|array<string, Grant>  $grants
     */
    public static function fromGrants(iterable $grants): self
    {
        $clean = [];

        foreach ($grants as $activity => $grant) {
            $activity = $activity instanceof Activity ? $activity : Activity::tryFrom((string) $activity);

            if ($activity !== null && ! $grant->isNone()) {
                $clean[$activity->value] = isset($clean[$activity->value])
                    ? $clean[$activity->value]->union($grant)
                    : $grant;
            }
        }

        return new self(false, $clean);
    }

    /**
     * The combination of several sets — a member holding several roles.
     */
    public static function union(self ...$sets): self
    {
        $grants = [];

        foreach ($sets as $set) {
            if ($set->owner) {
                return self::owner();
            }

            foreach ($set->grants as $activity => $grant) {
                $grants[$activity] = isset($grants[$activity]) ? $grants[$activity]->union($grant) : $grant;
            }
        }

        return new self(false, $grants);
    }

    public function isOwner(): bool
    {
        return $this->owner;
    }

    public function grant(Activity $activity): Grant
    {
        if ($this->owner) {
            return Grant::full();
        }

        return $this->grants[$activity->value] ?? Grant::none();
    }

    public function allows(Activity $activity, PermissionAction $action): bool
    {
        if (! $activity->supports($action)) {
            return false;
        }

        return $this->owner || $this->grant($activity)->allows($activity, $action);
    }

    /**
     * Whether any one of the requirements is met.
     *
     * @param  iterable<Requirement>  $requirements
     */
    public function allowsAny(iterable $requirements): bool
    {
        foreach ($requirements as $requirement) {
            if ($this->allows($requirement->activity, $requirement->action)) {
                return true;
            }
        }

        return false;
    }

    public function canView(Activity $activity): bool
    {
        return $this->allows($activity, PermissionAction::View)
            || ($activity->actions() !== [] && ! $activity->supports(PermissionAction::View) && $this->hasAnyIn($activity));
    }

    public function canViewBalances(Activity $activity): bool
    {
        return $this->allows($activity, PermissionAction::ViewBalance);
    }

    /**
     * Whether the member can do anything at all in the activity.
     */
    public function hasAnyIn(Activity $activity): bool
    {
        return $this->owner || $this->grant($activity)->effectiveMask($activity) !== 0;
    }

    /**
     * Whether the member can open any activity in the area — what the sidebar
     * group and the legacy section checks ask.
     */
    public function canAccessArea(Section $area): bool
    {
        if ($this->owner) {
            return true;
        }

        foreach (Activity::forArea($area) as $activity) {
            if ($this->hasAnyIn($activity)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether payroll detail (employee names and wages on general-ledger
     * lines) may be shown: only to members who can open pay runs.
     */
    public function seesPayrollDetail(): bool
    {
        return $this->canView(Activity::PayRuns);
    }

    /**
     * Whether this member runs the organization (holds an administrative or
     * reserved permission beyond viewing), so an organization requiring
     * two-factor authentication requires it of them.
     */
    public function requiresTwoFactor(): bool
    {
        if ($this->owner) {
            return true;
        }

        foreach (Activity::cases() as $activity) {
            if (($activity->isAdministrative() || $activity->isReserved())
                && ($this->grant($activity)->effectiveMask($activity) & ~PermissionAction::View->bit()) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * What this set allows that the other doesn't — empty when the other
     * keeps everything. A non-empty result is a downgrade.
     *
     * @return list<Requirement>
     */
    public function lostIn(self $after): array
    {
        $lost = [];

        foreach (Activity::cases() as $activity) {
            foreach ($activity->actions() as $action) {
                if ($this->allows($activity, $action) && ! $after->allows($activity, $action)) {
                    $lost[] = new Requirement($activity, $action);
                }
            }
        }

        return $lost;
    }

    /**
     * Whether everything this set allows, the other allows too.
     */
    public function isSubsetOf(self $other): bool
    {
        return $this->lostIn($other) === [];
    }

    /**
     * Activity value => grant, for every activity with some access (None
     * omitted). The Owner's set lists every activity as Full.
     *
     * @return array<string, Grant>
     */
    public function grants(): array
    {
        if (! $this->owner) {
            return $this->grants;
        }

        $all = [];

        foreach (Activity::cases() as $activity) {
            $all[$activity->value] = Grant::full();
        }

        return $all;
    }
}
