<?php

namespace App\Support\Access;

use App\Enums\Activity;
use App\Enums\PermissionAction;
use App\Enums\PermissionLevel;

/**
 * A role's access to one activity: None, Full, or Partial with the actions
 * ticked. Any ticked action implies View, as in QuickBooks Desktop Enterprise
 * (a role that may create invoices may also open them).
 */
final readonly class Grant
{
    private function __construct(
        public PermissionLevel $level,
        public int $mask,
    ) {}

    public static function none(): self
    {
        return new self(PermissionLevel::None, 0);
    }

    public static function full(): self
    {
        return new self(PermissionLevel::Full, 0);
    }

    public static function partial(PermissionAction ...$actions): self
    {
        $mask = 0;

        foreach ($actions as $action) {
            $mask |= $action->bit();
        }

        return $mask === 0 ? self::none() : new self(PermissionLevel::Partial, $mask | PermissionAction::View->bit());
    }

    /**
     * Rebuild a grant from its stored level and mask.
     */
    public static function fromStored(PermissionLevel $level, int $mask): self
    {
        return match ($level) {
            PermissionLevel::None => self::none(),
            PermissionLevel::Full => self::full(),
            PermissionLevel::Partial => $mask === 0
                ? self::none()
                : new self(PermissionLevel::Partial, $mask | PermissionAction::View->bit()),
        };
    }

    /**
     * Whether this grant lets the holder take the action in the activity. An
     * action the activity doesn't offer is never allowed.
     */
    public function allows(Activity $activity, PermissionAction $action): bool
    {
        if (! $activity->supports($action)) {
            return false;
        }

        return match ($this->level) {
            PermissionLevel::None => false,
            PermissionLevel::Full => true,
            PermissionLevel::Partial => ($this->mask & $action->bit()) !== 0,
        };
    }

    /**
     * The actions this grant allows in the activity, in display order.
     *
     * @return list<PermissionAction>
     */
    public function actionsFor(Activity $activity): array
    {
        return array_values(array_filter($activity->actions(), fn (PermissionAction $action): bool => $this->allows($activity, $action)));
    }

    /**
     * The mask of actions actually allowed in the activity (Full expanded).
     */
    public function effectiveMask(Activity $activity): int
    {
        return match ($this->level) {
            PermissionLevel::None => 0,
            PermissionLevel::Full => $activity->fullMask(),
            PermissionLevel::Partial => $this->mask & $activity->fullMask(),
        };
    }

    /**
     * The more permissive combination of two grants, for a member holding
     * several roles: Full wins, otherwise the actions add up.
     */
    public function union(self $other): self
    {
        if ($this->level === PermissionLevel::Full || $other->level === PermissionLevel::Full) {
            return self::full();
        }

        $mask = $this->mask | $other->mask;

        return $mask === 0 ? self::none() : new self(PermissionLevel::Partial, $mask);
    }

    public function isNone(): bool
    {
        return $this->level === PermissionLevel::None;
    }
}
