<?php

namespace App\Actions\Lists;

use App\Enums\AuditAction;
use App\Exceptions\Deletion\DeletionBlockedException;
use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\Attachment;
use App\Models\Classification;
use App\Models\Contact;
use App\Models\Fund;
use App\Models\Item;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Deletion\DeletionBlocker;
use App\Services\EditLocks\EditLockManager;
use App\Services\Merge\AccountReferenceRegistry;
use App\Services\Merge\ContactReferenceRegistry;
use App\Services\References\AssetCategoryReferenceRegistry;
use App\Services\References\ClassificationReferenceRegistry;
use App\Services\References\FundReferenceRegistry;
use App\Services\References\ItemReferenceRegistry;
use App\Services\References\LocationReferenceRegistry;
use App\Services\References\PaymentMethodReferenceRegistry;
use App\Services\References\PaymentTermReferenceRegistry;
use App\Services\References\RecordReferences;
use App\Services\References\TaxCodeReferenceRegistry;
use App\Services\Restore\JsonIdPaths;
use App\Services\Restore\RowTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Deletes a list record nothing uses (QuickBooks Desktop deletes a list entry
 * only when no transaction names it, and offers Make inactive otherwise).
 *
 * A record can go only when it is unreferenced (its reference registry finds
 * no row naming it, and no saved report or reconciliation default holds its
 * id) and is not one the app relies on (a system account, the default fund).
 * The web app also asks that it be made inactive first, so a delete is
 * always two deliberate steps; the API, whose calls are deliberate already,
 * skips that.
 *
 * The record is removed for real (no soft delete) with its own attachments,
 * and the audit trail keeps a copy of what it was.
 */
final class DeleteListRecord
{
    public function __construct(
        private readonly AccountingAuditRecorder $audit,
        private readonly EditLockManager $locks,
    ) {}

    /**
     * Why the record can't be deleted, if anything stops it.
     *
     * @return list<DeletionBlocker>
     */
    public function blockers(Model $record, bool $requireInactive = true): array
    {
        $kind = $this->kind($record);
        $blockers = [];

        if ($requireInactive && $record->getAttribute('is_active')) {
            $blockers[] = new DeletionBlocker(__('Make it inactive first. Deleting is for a record you no longer use.'));
        }

        if ($this->isSystem($record)) {
            $blockers[] = new DeletionBlocker(__('The app relies on this record, so it can\'t be deleted.'));
        }

        $reference = RecordReferences::firstReference($kind['registry'], (int) $record->getKey(), $kind['ignore']);

        if ($reference !== null) {
            $blockers[] = new DeletionBlocker(__('It is still used (:where). Make it inactive instead, or merge it into another record where that is offered.', ['where' => RecordReferences::describe($reference)]));
        } elseif (($where = $this->jsonReference($record)) !== null) {
            $blockers[] = new DeletionBlocker(__('It is still used (:where). Make it inactive instead, or merge it into another record where that is offered.', ['where' => $where]));
        }

        return $blockers;
    }

    /**
     * @throws DeletionBlockedException
     */
    public function handle(Model $record, ?User $actor = null, bool $requireInactive = true): void
    {
        $kind = $this->kind($record);

        // Never inside a transaction (EditLockManager is compare-and-set).
        $this->locks->assertWritable($record, $actor);

        DB::transaction(function () use ($record, $kind, $requireInactive): void {
            $locked = $record->newQueryWithoutScopes()->whereKey($record->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $blockers = $this->blockers($locked, $requireInactive);

            if ($blockers !== []) {
                throw new DeletionBlockedException($blockers);
            }

            $snapshot = collect($locked->getAttributes())->except(['password', 'portal_password'])->all();
            $blobs = [];

            AuditMute::silence(function () use ($locked, $kind, &$blobs): void {
                foreach ($kind['reset'] as $reference) {
                    [$table, $column] = explode('.', $reference);
                    DB::table($table)->where($column, $locked->getKey())->update([$column => null]);
                }

                foreach ($kind['owned'] as $reference) {
                    [$table, $column] = explode('.', $reference);
                    DB::table($table)->where($column, $locked->getKey())->delete();
                }

                foreach (Attachment::withoutGlobalScopes()->where('attachable_type', $locked::class)->where('attachable_id', $locked->getKey())->get() as $attachment) {
                    $blobs[] = [(string) $attachment->disk, (string) $attachment->path];
                    DB::table('attachments')->where('id', $attachment->id)->delete();
                }

                DB::table('edit_locks')->where('lockable_type', $locked::class)->where('lockable_id', $locked->getKey())->delete();

                $locked->forceDelete();
            });

            $this->audit->record((int) $locked->getAttribute('company_id'), $kind['audit'], $locked, [
                'kind' => 'list_record',
                'type' => class_basename($locked),
                'record' => $snapshot,
            ]);

            DB::afterCommit(function () use ($blobs): void {
                foreach ($blobs as [$disk, $path]) {
                    Storage::disk($disk)->delete($path);
                }
            });
        });
    }

    /**
     * The record's reference registry and audit action, plus the references
     * that don't stop a delete: `owned` rows go with the record (a bundle's
     * own components, an employee's payroll setup), `reset` columns only
     * remember a suggestion or a convenience and are cleared.
     *
     * @return array{registry: class-string, owned: list<string>, reset: list<string>, ignore: list<string>, audit: AuditAction}
     */
    private function kind(Model $record): array
    {
        [$registry, $owned, $reset, $ignore, $audit] = match (true) {
            $record instanceof Account => [AccountReferenceRegistry::class, [], ['bank_statement_lines.suggested_account_id', 'deleted_transactions.bank_account_id'], [], AuditAction::AccountDeleted],
            // A portal sign-in link is not a use of the contact; the FK removes it.
            $record instanceof Contact => [ContactReferenceRegistry::class, ['employee_payroll_profiles.contact_id'], ['bank_statement_lines.suggested_contact_id', 'inbox_items.suggested_contact_id'], ['portal_login_links.contact_id'], AuditAction::ContactDeleted],
            $record instanceof Item => [ItemReferenceRegistry::class, ['item_components.item_id'], [], [], AuditAction::ItemDeleted],
            $record instanceof TaxCode => [TaxCodeReferenceRegistry::class, [], [], [], AuditAction::TaxCodeDeleted],
            $record instanceof PaymentMethod => [PaymentMethodReferenceRegistry::class, [], [], [], AuditAction::PaymentMethodDeleted],
            $record instanceof PaymentTerm => [PaymentTermReferenceRegistry::class, [], [], [], AuditAction::PaymentTermDeleted],
            $record instanceof Classification => [ClassificationReferenceRegistry::class, [], [], [], AuditAction::ListRecordDeleted],
            $record instanceof Location => [LocationReferenceRegistry::class, [], [], [], AuditAction::ListRecordDeleted],
            $record instanceof Fund => [FundReferenceRegistry::class, [], [], [], AuditAction::ListRecordDeleted],
            $record instanceof AssetCategory => [AssetCategoryReferenceRegistry::class, [], [], [], AuditAction::ListRecordDeleted],
            default => throw new InvalidArgumentException(class_basename($record).' is not a list record.'),
        };

        return [
            'registry' => $registry,
            'owned' => $owned,
            'reset' => $reset,
            'ignore' => array_merge($owned, $reset, $ignore),
            'audit' => $audit,
        ];
    }

    private function isSystem(Model $record): bool
    {
        return (bool) $record->getAttribute('is_system') || ($record instanceof Fund && (bool) $record->getAttribute('is_default'));
    }

    /**
     * An id held inside a JSON column (a saved report's filters, a remembered
     * reconciliation account) that a plain column scan can't see. The bank
     * account a screen last showed is a convenience, not a use.
     */
    private function jsonReference(Model $record): ?string
    {
        $table = $record->getTable();
        $id = (int) $record->getKey();

        foreach (RowTransformer::JSON_ID_PATHS as $sourceTable => $paths) {
            if ($sourceTable === 'user_preferences') {
                continue;
            }

            foreach ($paths as $path) {
                if ($path['table'] !== $table) {
                    continue;
                }

                // Ids are unique across a table, so a table without company_id
                // (a slip filing's lines) can be scanned whole.
                $rows = DB::table($sourceTable)
                    ->when($sourceTable === 'companies', fn ($q) => $q->where('id', $record->getAttribute('company_id')))
                    ->when($sourceTable !== 'companies' && Schema::hasColumn($sourceTable, 'company_id'), fn ($q) => $q->where('company_id', $record->getAttribute('company_id')))
                    ->whereNotNull($path['column'])
                    ->pluck($path['column']);

                foreach ($rows as $json) {
                    $value = is_string($json) ? json_decode($json, true) : $json;

                    if (in_array($id, JsonIdPaths::ids($value, $path['path']), true)) {
                        return str_replace('_', ' ', $sourceTable);
                    }
                }
            }
        }

        return null;
    }
}
