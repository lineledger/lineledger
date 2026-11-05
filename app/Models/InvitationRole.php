<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One role an invitation grants once accepted.
 *
 * @property int $company_invitation_id
 * @property int $role_id
 */
#[Fillable(['company_id', 'company_invitation_id', 'role_id'])]
class InvitationRole extends Model
{
    use BelongsToCompany;

    protected $table = 'company_invitation_roles';

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
