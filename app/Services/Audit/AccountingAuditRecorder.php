<?php

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Models\AccountingAuditLog;
use App\Models\CompanyApiKey;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AccountingAuditRecorder
{
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        int $companyId,
        AuditAction $action,
        Model $auditable,
        array $payload = [],
        ?JournalEntry $journalEntry = null,
    ): AccountingAuditLog {
        return DB::transaction(function () use ($companyId, $action, $auditable, $payload, $journalEntry) {
            $previous = AccountingAuditLog::query()
                ->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->first();

            $sequence = $previous ? (int) $previous->sequence + 1 : 1;
            $previousHash = $previous?->row_hash ?? self::GENESIS_HASH;
            $recordedAt = now()->format('Y-m-d H:i:s.u');
            $apiKey = self::currentApiKey();

            $contents = [
                'company_id' => $companyId,
                'sequence' => $sequence,
                'recorded_at' => $recordedAt,
                'actor_user_id' => self::actorUserId(),
                'api_key_id' => $apiKey?->id,
                'actor_ip' => self::actorIp(),
                'actor_user_agent' => self::actorUserAgent(),
                'action' => $action->value,
                'auditable_type' => $auditable->getMorphClass(),
                'auditable_id' => (int) $auditable->getKey(),
                'journal_entry_id' => $journalEntry?->id,
                'payload' => $payload,
                'previous_hash' => $previousHash,
            ];

            $hashInput = CanonicalJson::encode($contents);
            $rowHash = hash('sha256', $previousHash.$hashInput);

            return AccountingAuditLog::query()->create([
                ...$contents,
                'hash_input' => $hashInput,
                'row_hash' => $rowHash,
            ]);
        });
    }

    /**
     * Recompute a row_hash from a previous_hash and the exact byte string
     * that was canonicalized at recording time. The verifier passes the
     * stored hash_input column verbatim so there is no chance of a
     * deserialization-driven mismatch.
     */
    public static function hashFromInput(string $previousHash, string $hashInput): string
    {
        return hash('sha256', $previousHash.$hashInput);
    }

    /**
     * Deterministic snapshot of a journal entry + its lines, suitable for
     * inclusion in an audit payload.
     *
     * @return array<string, mixed>
     */
    public static function snapshotJournalEntry(JournalEntry $entry): array
    {
        $entry->loadMissing('lines');

        return [
            'entry' => [
                'id' => (int) $entry->id,
                'entry_no' => $entry->entry_no,
                'entry_date' => optional($entry->entry_date)->toDateString(),
                'memo' => $entry->memo,
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id !== null ? (int) $entry->source_id : null,
                'is_posted' => (bool) $entry->is_posted,
                'posted_at' => optional($entry->posted_at)->format('Y-m-d H:i:s.u'),
                'posted_by_user_id' => $entry->posted_by_user_id,
                'voided_at' => optional($entry->voided_at)->format('Y-m-d H:i:s.u'),
                'voided_by_user_id' => $entry->voided_by_user_id,
                'reversed_by_entry_id' => $entry->reversed_by_entry_id,
                'reverses_entry_id' => $entry->reverses_entry_id,
            ],
            'lines' => $entry->lines
                ->sortBy('line_order')
                ->values()
                ->map(fn ($l): array => [
                    'id' => (int) $l->id,
                    'account_id' => (int) $l->account_id,
                    'debit_cents' => (int) $l->debit_cents,
                    'credit_cents' => (int) $l->credit_cents,
                    'memo' => $l->memo,
                    'contact_id' => $l->contact_id,
                    'tax_code_id' => $l->tax_code_id,
                    'line_order' => (int) $l->line_order,
                ])
                ->all(),
        ];
    }

    /**
     * The acting staff user, if any. Portal sessions authenticate a Contact
     * (the "customer" guard), whose id must not land in the users FK; those
     * actions are attributed via actor_ip/user_agent and the SecurityLog.
     */
    protected static function actorUserId(): ?int
    {
        $actor = Auth::user();

        return $actor instanceof User ? (int) $actor->getAuthIdentifier() : null;
    }

    protected static function currentApiKey(): ?CompanyApiKey
    {
        if (! app()->bound('current_api_key')) {
            return null;
        }

        $key = app('current_api_key');

        return $key instanceof CompanyApiKey ? $key : null;
    }

    protected static function actorIp(): ?string
    {
        return self::currentRequest()?->ip();
    }

    protected static function actorUserAgent(): ?string
    {
        $ua = self::currentRequest()?->userAgent();

        if ($ua === null) {
            return null;
        }

        return mb_substr($ua, 0, 512);
    }

    protected static function currentRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request ? $request : null;
    }
}
