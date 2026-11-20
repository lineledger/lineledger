<?php

use App\Enums\DeletableDocumentType;
use App\Services\Deletion\DocumentReferenceRegistry;
use App\Services\Restore\RowTransformer;
use Illuminate\Support\Facades\Schema;

/*
 * No reference into a deletable transaction goes unconsidered. The restore
 * maps in RowTransformer list every reference column, polymorphic pointer and
 * JSON id in the schema (their own tests hold them to it, including the
 * unconstrained ones with no foreign key), so every one that points at a
 * deletable table, or at journal entries and lines, must have a decision in
 * DocumentReferenceRegistry: block, owned, reset, owner or history.
 */

/** @return list<string> */
function documentReferenceTargets(): array
{
    $targets = array_map(fn (DeletableDocumentType $t): string => (new ($t->modelClass()))->getTable(), DeletableDocumentType::cases());

    return [...$targets, 'journal_entries', 'journal_lines'];
}

it('declares every reference into a deletable table and into journal entries and lines', function () {
    $targets = documentReferenceTargets();
    $declared = DocumentReferenceRegistry::references();
    $missing = [];

    $check = function (string $target, string $reference) use ($declared, &$missing): void {
        if (! isset($declared[$target][$reference])) {
            $missing[] = $target.' ← '.$reference;
        }
    };

    foreach ([RowTransformer::PARENT_FK_MAP, RowTransformer::DEFERRED_FK_COLUMNS] as $map) {
        foreach ($map as $table => $columns) {
            foreach ($columns as $column => $target) {
                if (in_array($target, $targets, true)) {
                    $check($target, $table.'.'.$column);
                }
            }
        }
    }

    foreach (RowTransformer::JSON_ID_PATHS as $table => $paths) {
        foreach ($paths as $path) {
            if (in_array($path['table'], $targets, true)) {
                $check($path['table'], $table.'.'.$path['column']);
            }
        }
    }

    foreach (RowTransformer::INBOX_PROMOTED_DOCUMENT_TABLES as $target) {
        if (in_array($target, $targets, true)) {
            $check($target, 'inbox_items.promoted_document_id');
        }
    }

    expect($missing)->toBe([], 'Undeclared references (add each to DocumentReferenceRegistry::references() with what a delete does about it): '.implode('; ', $missing));
});

it('declares every polymorphic pointer, which can name any transaction', function () {
    $declared = DocumentReferenceRegistry::polymorphic();

    foreach (RowTransformer::POLYMORPHIC_PAIRS as $table => $pairs) {
        foreach ($pairs as $pair) {
            expect($declared)->toHaveKey($table.'.'.$pair['type'].'/'.$pair['id']);
        }
    }

    // Edit locks are not backed up, so the restore maps don't list them.
    expect($declared)->toHaveKey('edit_locks.lockable_type/lockable_id');
});

it('covers every deletable table and names only real columns', function () {
    $references = DocumentReferenceRegistry::references();

    foreach (documentReferenceTargets() as $target) {
        expect($references)->toHaveKey($target);
    }

    foreach ($references as $target => $columns) {
        foreach ($columns as $reference => $disposition) {
            [$table, $column] = explode('.', $reference);

            expect(Schema::hasColumn($table, $column))->toBeTrue("{$reference} (into {$target}) does not exist.")
                ->and($disposition)->toBeIn(['blocks', 'owned', 'reset', 'owner', 'history']);
        }
    }

    foreach (DocumentReferenceRegistry::polymorphic() as $pair => $disposition) {
        [$table, $columns] = explode('.', $pair);
        [$type, $id] = explode('/', $columns);

        expect(Schema::hasColumn($table, $type) && Schema::hasColumn($table, $id))->toBeTrue("{$pair} does not exist.");
    }
});
