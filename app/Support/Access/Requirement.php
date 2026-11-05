<?php

namespace App\Support\Access;

use App\Enums\Activity;
use App\Enums\PermissionAction;

/**
 * One permission a page or action needs: an action in an activity.
 */
final readonly class Requirement
{
    public function __construct(
        public Activity $activity,
        public PermissionAction $action,
    ) {}

    public function __toString(): string
    {
        return $this->activity->value.':'.$this->action->value;
    }
}
