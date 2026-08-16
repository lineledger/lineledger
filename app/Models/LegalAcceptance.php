<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single record that a user agreed to a specific version of a legal document.
 * Rows are immutable: a new version of a document produces a new row rather than
 * updating an existing one, so the full acceptance history is retained.
 */
#[Fillable(['user_id', 'document_key', 'version', 'accepted_at', 'ip_address', 'user_agent'])]
class LegalAcceptance extends Model
{
    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
