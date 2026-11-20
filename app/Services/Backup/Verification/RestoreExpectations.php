<?php

namespace App\Services\Backup\Verification;

use App\Services\Backup\JsonlTableWriter;
use App\Services\Restore\CompanyImporter;
use App\Services\Restore\RowTransformer;

/**
 * The columns a restore changes on purpose, so the verifier doesn't report
 * them as differences: everything else must come back exactly as the bundle
 * holds it (ids mapped onto the copy's own rows).
 *
 * Each mirrors a rule in {@see RowTransformer} or
 * {@see CompanyImporter}; why each one changes is
 * documented there.
 */
final class RestoreExpectations
{
    /** Not compared (a fresh value, or checked another way). */
    public const IGNORE = 'ignore';

    /** Restored as null. */
    public const NULL = 'null';

    /** Restored as false. */
    public const FALSE = 'false';

    /** Kept when set; a missing value is filled in (any value will do). */
    public const FILLED = 'filled';

    /** `pending` comes back `expired`; anything else is kept. */
    public const PENDING_EXPIRES = 'pending-expires';

    /**
     * @var array<string, array<string, string>>
     */
    public const RESTORE = [
        'companies' => [
            'slug' => self::IGNORE,               // minted fresh for the copy
            'logo_path' => self::IGNORE,          // a fresh file, compared by content
            'document_logo_path' => self::IGNORE,
            'inbound_email_token' => self::NULL,  // the original's forwarding address
            'inbound_email_enabled' => self::FALSE,
            'updated_at' => self::IGNORE,
            'purge_at' => self::NULL,             // a copy starts out live
            'deleted_at' => self::NULL,
        ],
        'company_api_keys' => [
            'token_hash' => self::IGNORE,         // a hash nobody holds
            'revoked_at' => self::FILLED,         // restored revoked
        ],
        'company_invitations' => [
            'code' => self::IGNORE,               // a code nobody holds
            'expires_at' => self::IGNORE,         // an open invitation comes back expired
        ],
        'mcp_write_proposals' => [
            'idempotency_key' => self::IGNORE,
            'status' => self::PENDING_EXPIRES,
        ],
        'customer_receipts' => [
            'stripe_payment_intent_id' => self::NULL,
        ],
        'accounting_audit_logs' => [
            // Re-hashed over the copy's ids; `audit:verify` proves the new chain.
            'previous_hash' => self::IGNORE,
            'hash_input' => self::IGNORE,
            'row_hash' => self::IGNORE,
        ],
        'attachments' => [
            // A fresh path on this instance's disk; the file is compared by content.
            'path' => self::IGNORE,
            'disk' => self::IGNORE,
        ],
    ];

    /**
     * Columns the exporter rewrites or leaves out, for comparing a live
     * organization against its own bundle.
     *
     * @var array<string, array<string, string>>
     */
    public const EXPORT = [
        'companies' => [
            'logo_path' => self::IGNORE,          // the file's path inside the bundle
            'document_logo_path' => self::IGNORE,
        ],
        'attachments' => [
            'path' => self::IGNORE,               // the file's path inside the bundle
        ],
    ];

    /**
     * Whether the exporter deliberately leaves `$table.$column` out of a bundle.
     */
    public static function notExported(string $table, string $column): bool
    {
        return ($table === 'companies' && str_starts_with($column, 'stripe_'))
            || (JsonlTableWriter::HIDDEN_COLUMNS[$table][$column] ?? null) === false;
    }
}
