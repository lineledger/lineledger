<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\BankStatementFormat;
use App\Enums\BankStatementImportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded bank statement file. Tracks the file through parse → match →
 * review → commit, holds the parser diagnostics, and links to both the source
 * Attachment and the BankReconciliation it ultimately pre-fills.
 */
#[Fillable([
    'company_id',
    'account_id',
    'bank_reconciliation_id',
    'bank_import_profile_id',
    'attachment_id',
    'source_format',
    'original_filename',
    'status',
    'statement_begin_date',
    'statement_end_date',
    'statement_begin_balance_cents',
    'statement_end_balance_cents',
    'mapping',
    'parse_meta',
    'line_count',
    'matched_count',
    'created_count',
    'duplicate_count',
    'error_message',
    'created_by_user_id',
])]
class BankStatementImport extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<BankReconciliation, $this>
     */
    public function bankReconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class);
    }

    /**
     * @return BelongsTo<BankImportProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(BankImportProfile::class, 'bank_import_profile_id');
    }

    /**
     * @return BelongsTo<Attachment, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<BankStatementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    public function isCommitted(): bool
    {
        return $this->status === BankStatementImportStatus::Committed;
    }

    public function isFailed(): bool
    {
        return $this->status === BankStatementImportStatus::Failed;
    }

    public function needsMapping(): bool
    {
        return $this->status === BankStatementImportStatus::NeedsMapping;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_format' => BankStatementFormat::class,
            'status' => BankStatementImportStatus::class,
            'statement_begin_date' => 'date:Y-m-d',
            'statement_end_date' => 'date:Y-m-d',
            'statement_begin_balance_cents' => 'integer',
            'statement_end_balance_cents' => 'integer',
            'mapping' => 'array',
            'parse_meta' => 'array',
            'line_count' => 'integer',
            'matched_count' => 'integer',
            'created_count' => 'integer',
            'duplicate_count' => 'integer',
        ];
    }
}
