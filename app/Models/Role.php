<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\PredefinedRole;
use App\Support\Access\Grant;
use App\Support\Access\PermissionSet;
use App\Support\Locales;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named bundle of activity permissions a company assigns to its users
 * (QuickBooks Desktop Enterprise's Role List).
 *
 * A predefined role (`system_key` set) nobody has edited reads its grants from
 * the {@see PredefinedRole} catalogue; the first edit copies them into
 * `role_permissions` and stamps `customized_at`. Every other role reads its
 * rows.
 *
 * @property int $id
 * @property int $company_id
 * @property PredefinedRole|null $system_key
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $customized_at
 * @property int $catalog_version
 * @property string|null $legacy_key
 */
#[Fillable([
    'company_id', 'system_key', 'name', 'description', 'customized_at', 'catalog_version',
    'legacy_key', 'created_by_user_id', 'updated_by_user_id',
])]
class Role extends Model
{
    use BelongsToCompany;

    /**
     * @return HasMany<RolePermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /**
     * The memberships holding this role.
     *
     * @return BelongsToMany<Membership, $this>
     */
    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(Membership::class, 'company_member_roles', 'role_id', 'company_member_id')
            ->withTimestamps();
    }

    /**
     * Whether the role still follows the predefined catalogue.
     */
    public function followsCatalogue(): bool
    {
        return $this->system_key !== null && $this->customized_at === null;
    }

    /**
     * The role's grants, activity value => grant (None omitted).
     *
     * @return array<string, Grant>
     */
    public function grants(): array
    {
        if ($this->followsCatalogue()) {
            return $this->system_key->grants();
        }

        $rows = $this->relationLoaded('permissions')
            ? $this->permissions
            : $this->permissions()->withoutGlobalScopes()->get();

        $grants = [];

        foreach ($rows as $row) {
            $grant = $row->toGrant();

            if ($row->activity !== null && ! $grant->isNone()) {
                $grants[$row->activity->value] = $grant;
            }
        }

        return $grants;
    }

    public function permissionSet(): PermissionSet
    {
        return PermissionSet::fromGrants($this->grants());
    }

    /**
     * The name to show: a predefined role's untouched English name is
     * translated; a name someone typed is shown as typed.
     */
    public function displayName(): string
    {
        return $this->system_key !== null && $this->name === $this->system_key->defaultName()
            ? Locales::name($this->name)
            : $this->name;
    }

    public function displayDescription(): ?string
    {
        return $this->system_key !== null && $this->description === $this->system_key->defaultDescription()
            ? Locales::name((string) $this->description)
            : $this->description;
    }

    protected function casts(): array
    {
        return [
            'system_key' => PredefinedRole::class,
            'customized_at' => 'datetime',
            'catalog_version' => 'integer',
        ];
    }
}
