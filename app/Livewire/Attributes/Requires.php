<?php

namespace App\Livewire\Attributes;

use App\Enums\Activity;
use App\Enums\PermissionAction;
use App\Livewire\Permissions\MethodPermissionGate;
use Attribute;

/**
 * Declares what calling a Livewire method needs, checked before the method
 * runs by {@see MethodPermissionGate}:
 *
 *     #[Requires(Activity::Invoices, PermissionAction::Delete)]
 *     public function void(InvoicePoster $poster): void
 *
 * A null activity means the page's own (the activity its route belongs to),
 * for methods shared through a trait. Several activities or actions mean any
 * one of them will do (a quick-added contact is a customer or a vendor);
 * repeat the attribute when the method needs more than one thing (converting
 * an estimate needs Modify on estimates AND Create on invoices).
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Requires
{
    /** @var list<Activity> empty: the page's own */
    public array $activities;

    /** @var list<PermissionAction> */
    public array $actions;

    /**
     * @param  Activity|list<Activity>|null  $activity
     * @param  PermissionAction|list<PermissionAction>  $action
     */
    public function __construct(
        Activity|array|null $activity,
        PermissionAction|array $action,
    ) {
        $this->activities = $activity === null ? [] : (is_array($activity) ? $activity : [$activity]);
        $this->actions = is_array($action) ? $action : [$action];
    }
}
