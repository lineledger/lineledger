<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\Activity;
use App\Enums\PermissionAction;
use App\Enums\PermissionLevel;
use App\Support\Access\Grant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A role's access to one activity: Full, or Partial with the actions ticked.
 * No row means None.
 *
 * @property int $role_id
 * @property Activity|null $activity
 * @property PermissionLevel $access
 * @property bool $can_view
 * @property bool $can_create
 * @property bool $can_modify
 * @property bool $can_delete
 * @property bool $can_print
 * @property bool $can_view_balance
 */
#[Fillable([
    'company_id', 'role_id', 'activity', 'access', 'can_view', 'can_create', 'can_modify',
    'can_delete', 'can_print', 'can_view_balance',
])]
class RolePermission extends Model
{
    use BelongsToCompany;

    /**
     * Action => the column holding it.
     */
    public const array COLUMNS = [
        'view' => 'can_view',
        'create' => 'can_create',
        'modify' => 'can_modify',
        'delete' => 'can_delete',
        'print' => 'can_print',
        'view_balance' => 'can_view_balance',
    ];

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function toGrant(): Grant
    {
        $mask = 0;

        foreach (PermissionAction::cases() as $action) {
            if ($this->{self::COLUMNS[$action->value]}) {
                $mask |= $action->bit();
            }
        }

        return Grant::fromStored($this->access, $mask);
    }

    /**
     * The row's attributes for a grant on an activity (columns only, no keys).
     *
     * @return array<string, mixed>
     */
    public static function attributesFor(Activity $activity, Grant $grant): array
    {
        $attributes = ['activity' => $activity->value, 'access' => $grant->level->value];

        foreach (PermissionAction::cases() as $action) {
            $attributes[self::COLUMNS[$action->value]] = $grant->allows($activity, $action);
        }

        return $attributes;
    }

    protected function casts(): array
    {
        return [
            'activity' => Activity::class,
            'access' => PermissionLevel::class,
            'can_view' => 'boolean',
            'can_create' => 'boolean',
            'can_modify' => 'boolean',
            'can_delete' => 'boolean',
            'can_print' => 'boolean',
            'can_view_balance' => 'boolean',
        ];
    }
}
