<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\McpProposalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staged, not-yet-committed write requested through the agentic MCP server.
 * It is the durable record of the propose -> confirm handshake; proposing writes
 * one of these rows and NOTHING to the ledger, and confirming replays
 * {@see $payload} through the matching Save action (+ Poster) exactly once.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $company_api_key_id
 * @property int|null $user_id
 * @property string $tool
 * @property array<string, mixed> $payload
 * @property string $preview
 * @property string $idempotency_key
 * @property McpProposalStatus $status
 * @property int|null $confirmed_journal_entry_id
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'company_id',
    'company_api_key_id',
    'user_id',
    'tool',
    'payload',
    'preview',
    'idempotency_key',
    'status',
    'confirmed_journal_entry_id',
    'expires_at',
])]
class McpWriteProposal extends Model
{
    use BelongsToCompany;

    /**
     * Whether this proposal can no longer be confirmed because its window has
     * elapsed (independent of the persisted status, which a sweeper may lag).
     */
    public function isExpired(): bool
    {
        return $this->status === McpProposalStatus::Expired
            || ($this->expires_at !== null && $this->expires_at->isPast());
    }

    /**
     * @return BelongsTo<CompanyApiKey, $this>
     */
    public function companyApiKey(): BelongsTo
    {
        return $this->belongsTo(CompanyApiKey::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function confirmedJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'confirmed_journal_entry_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => McpProposalStatus::class,
            'expires_at' => 'datetime',
        ];
    }
}
