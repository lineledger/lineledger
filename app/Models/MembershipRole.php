<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One role held by one member.
 *
 * @property int $company_member_id
 * @property int $role_id
 */
#[Fillable(['company_id', 'company_member_id', 'role_id', 'assigned_by_user_id'])]
class MembershipRole extends Model
{
    use BelongsToCompany;

    protected $table = 'company_member_roles';

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'company_member_id');
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
