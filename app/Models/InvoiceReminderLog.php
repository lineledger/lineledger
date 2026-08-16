<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records that a given reminder tier was sent for a given invoice. The unique
 * (invoice_id, reminder_tier_id) index makes each tier fire at most once per
 * invoice — the idempotency guard for the scheduled dunning run.
 */
#[Fillable([
    'company_id',
    'invoice_id',
    'reminder_tier_id',
    'sent_to',
    'sent_at',
])]
class InvoiceReminderLog extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<ReminderTier, $this>
     */
    public function reminderTier(): BelongsTo
    {
        return $this->belongsTo(ReminderTier::class);
    }
}
