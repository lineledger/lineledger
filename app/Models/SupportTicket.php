<?php

namespace App\Models;

use App\Enums\SupportTicketStatus;
use App\Enums\SupportTicketType;
use Database\Factories\SupportTicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An in-app support ticket a user raises with the Site Admin operator.
 * Platform-level and intentionally NOT company-scoped (no BelongsToCompany):
 * a ticket belongs to a user, is visible to every Site Admin, and is excluded
 * from per-company backup/restore. `company_id` is triage context only.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $company_id
 * @property string $subject
 * @property SupportTicketType $type
 * @property SupportTicketStatus $status
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'company_id', 'subject', 'type', 'status', 'last_activity_at'])]
class SupportTicket extends Model
{
    /** @use HasFactory<SupportTicketFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The company the user was in when they opened the ticket, kept for triage.
     * A plain relation — no global scope — so Site Admins can read it cross-tenant.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<SupportTicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->oldest();
    }

    /**
     * Mark the messages the given viewer hasn't read yet as read. A viewer only
     * "receives" messages from the other side: the owner reads admin messages;
     * a Site Admin reads the owner's messages.
     */
    public function markReadFor(User $viewer): void
    {
        $viewerIsOwner = $viewer->getKey() === $this->user_id;

        $this->messages()
            ->where('from_admin', $viewerIsOwner)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SupportTicketType::class,
            'status' => SupportTicketStatus::class,
            'last_activity_at' => 'datetime',
        ];
    }
}
