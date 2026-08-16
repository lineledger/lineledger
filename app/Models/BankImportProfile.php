<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\BankStatementFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved column mapping for a particular bank's CSV / Excel export. Once a user
 * maps a bank's columns, the mapping is remembered and re-applied automatically to
 * future uploads that share the same header signature — so they never map twice.
 */
#[Fillable([
    'company_id',
    'account_id',
    'name',
    'source_format',
    'mapping',
    'header_signature',
    'usage_count',
    'last_used_at',
    'created_by_user_id',
])]
class BankImportProfile extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function markUsed(): void
    {
        $this->forceFill([
            'usage_count' => $this->usage_count + 1,
            'last_used_at' => now(),
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_format' => BankStatementFormat::class,
            'mapping' => 'array',
            'usage_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }
}
