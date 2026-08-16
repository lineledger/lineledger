<?php

namespace App\Models;

use Database\Factories\SupportTicketMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message in a support ticket thread. `from_admin` marks a Site Admin reply
 * (versus the ticket owner's message); `read_at` is set when the recipient reads
 * it, driving the unread badges. Platform-level — not company-scoped.
 *
 * @property int $id
 * @property int $support_ticket_id
 * @property int|null $user_id
 * @property bool $from_admin
 * @property string $body
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['support_ticket_id', 'user_id', 'from_admin', 'body', 'read_at'])]
class SupportTicketMessage extends Model
{
    /** @use HasFactory<SupportTicketMessageFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<SupportTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_admin' => 'boolean',
            'read_at' => 'datetime',
        ];
    }
}
