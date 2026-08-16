<?php

namespace App\Models;

use App\Console\Commands\VerifyAccountingAuditCommand;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How far {@see VerifyAccountingAuditCommand} has verified
 * a company's audit hash chain. Derived state — see the migration for why.
 *
 * Deliberately does NOT use BelongsToCompany. This is operator infrastructure
 * written from the console (where nothing is bound), and the trait's creating
 * guard would overwrite an explicit company_id with whatever tenant happened to
 * be bound — which, in a verify-all run, is the wrong company. Queries here name
 * their company_id explicitly instead.
 */
#[Fillable([
    'company_id',
    'last_verified_sequence',
    'last_verified_row_hash',
    'verified_at',
])]
class AuditChainCheckpoint extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_verified_sequence' => 'integer',
            'verified_at' => 'datetime',
        ];
    }
}
