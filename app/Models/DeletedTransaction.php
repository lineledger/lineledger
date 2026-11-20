<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\DeletableDocumentType;
use App\Enums\DeletedTransactionState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The record of a deleted transaction (a tombstone). TransactionDeleter writes
 * one in the same database transaction that removes the document, its lines
 * and its journal entries, next to the hash-chained audit row whose auditable
 * it is. It backs the Voided/Deleted Transactions report and the deleted
 * transaction's own page, and reserves the document's number and journal
 * entry numbers so auto-numbering never hands them out again.
 *
 * `original_id` is the deleted row's id, kept as history: nothing references
 * it and a restore leaves it as it was. The snapshot is self-contained
 * (numbers, names, codes and amounts), so it reads the same after a restore,
 * when the ids inside it name nothing.
 *
 * Read-only once written.
 *
 * @property DeletableDocumentType $document_type
 * @property DeletedTransactionState $state_at_deletion
 * @property Carbon|null $document_date
 * @property array<string, mixed> $snapshot
 */
#[Fillable([
    'company_id', 'document_type', 'original_id', 'document_table', 'document_number',
    'bank_account_id', 'max_entry_seq', 'document_date', 'contact_name', 'total_cents',
    'currency_code', 'state_at_deletion', 'reason', 'deleted_by_user_id', 'deleted_via',
    'snapshot',
])]
class DeletedTransaction extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('A deleted transaction record is read-only.');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }

    /**
     * The number as it read on the document, with its type: "Invoice INV-000105".
     */
    public function title(): string
    {
        $label = $this->document_type->label();

        return $this->document_number !== null && $this->document_number !== ''
            ? $label.' '.$this->document_number
            : $label;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => DeletableDocumentType::class,
            'state_at_deletion' => DeletedTransactionState::class,
            'document_date' => 'date:Y-m-d',
            'total_cents' => 'integer',
            'max_entry_seq' => 'integer',
            'snapshot' => 'array',
        ];
    }
}
