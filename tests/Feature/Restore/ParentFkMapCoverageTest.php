<?php

use App\Services\Backup\BackupTableRegistry;
use App\Services\Restore\RowTransformer;
use Illuminate\Support\Facades\Schema;

/**
 * Arch test, registry → transformer direction: every table that
 * {@see BackupTableRegistry::tables()} exports must have each of its reference
 * columns accounted for by {@see RowTransformer}, or a restore copies the
 * SOURCE company's ids into the new company's rows — a cross-tenant pointer
 * when the old row still exists on this instance, an FK violation that aborts
 * the restore when it doesn't. `opening_balance_states` / `opening_balance_rows`
 * shipped exactly that way: registered for export, never remapped.
 *
 * `tests/Unit/Backup/RegistryOrderingTest` guards the other direction (every
 * mapped FK points at a table restored earlier).
 *
 * Two passes, because not every reference is a DB constraint:
 *   1. Schema-derived — `Schema::getForeignKeys()` on each exported table.
 *      Exact: the referenced table is known, so the map's target is checked too.
 *   2. Convention-derived — every `*_id` column with no constraint. Catches the
 *      soft references (`cheques.credit_memo_id`, `journal_lines.contact_id`)
 *      the schema pass cannot see, and polymorphic ids.
 *
 * Both mirror the transformer's own generic passes: `company_id` is swapped
 * wholesale, and user columns (`*_user_id` + {@see RowTransformer::USER_ID_EXACT})
 * go through the email-match user map, so neither belongs in PARENT_FK_MAP.
 */
beforeEach(function () {
    // Handled in RowTransformer::applyTableQuirks() rather than PARENT_FK_MAP:
    // the owner membership is skipped on import, so an unmapped value must
    // become null instead of violating the company_members FK.
    $this->quirkHandled = [
        'document_folders.created_by_member_id',
    ];

    // Plain values that merely end in `_id` — external handles, not row ids.
    $this->notReferences = [
        'companies.stripe_account_id', // Stripe Connect account handle
        'contacts.employee_id', // employee number, free text
        'journal_entries.source_external_id', // QuickBooks TxnID carried over by the migration
        'customer_receipts.stripe_payment_intent_id', // Stripe PaymentIntent handle
    ];

    // Polymorphic ids the transformer deliberately leaves alone. See the
    // matching comment on the table's BackupTableRegistry entry.
    $this->polymorphicNotRemapped = [
        'inbox_items.promoted_document_id',
    ];

    // Known un-remapped soft references — documented here, not endorsed.
    // Each points at a row the transformer cannot locate: no `_type` sibling
    // names the line table. Delete the entry when the column gains a remap.
    $this->knownGaps = [
        'stock_movements.source_line_id', // line of the polymorphic source doc (invoice_lines, bill_lines, …)
    ];

    $this->isUserColumn = fn (string $column): bool => in_array($column, RowTransformer::USER_ID_EXACT, true)
        || str_ends_with($column, '_user_id');
});

it('remaps every DB-level foreign key on every exported table', function () {
    $violations = [];
    $constraintCount = 0;

    foreach (BackupTableRegistry::tables() as $entry) {
        $table = $entry['table'];
        $mapped = RowTransformer::PARENT_FK_MAP[$table] ?? null;
        $deferred = RowTransformer::DEFERRED_FK_COLUMNS[$table] ?? [];

        foreach (Schema::getForeignKeys($table) as $fk) {
            foreach ($fk['columns'] as $column) {
                $constraintCount++;
                $target = $fk['foreign_table'];
                $ref = "{$table}.{$column}";

                if ($column === 'company_id') {
                    continue; // generic company swap
                }

                if ($target === 'users') {
                    if (! ($this->isUserColumn)($column)) {
                        $violations[] = "{$ref} -> users: not matched by the generic user remap (`*_user_id` or ".implode('/', RowTransformer::USER_ID_EXACT).'), so it would carry the source instance\'s user id.';
                    }

                    continue;
                }

                if (($this->isUserColumn)($column)) {
                    $violations[] = "{$ref} -> {$target}: named like a user column, so the generic user pass would remap it through the wrong map.";

                    continue;
                }

                if (in_array($ref, $this->quirkHandled, true)) {
                    continue;
                }

                if ($mapped === null) {
                    $violations[] = "{$ref} -> {$target}: table `{$table}` has no RowTransformer::PARENT_FK_MAP entry.";

                    continue;
                }

                $expected = $mapped[$column] ?? $deferred[$column] ?? null;

                if ($expected === null) {
                    $violations[] = "{$ref} -> {$target}: column is in neither PARENT_FK_MAP nor DEFERRED_FK_COLUMNS.";

                    continue;
                }

                if ($expected !== $target) {
                    $violations[] = "{$ref}: mapped to `{$expected}` but the constraint references `{$target}`.";
                }
            }
        }
    }

    expect($violations)->toBe([], "Exported FK columns RowTransformer would not remap:\n  - ".implode("\n  - ", $violations));

    // The scan must actually have seen constraints — if the driver returned no
    // FK metadata this test would silently assert nothing.
    expect($constraintCount)->toBeGreaterThan(0, 'Schema::getForeignKeys() returned no constraints; the FK pass is not running.');
});

it('remaps or documents every unconstrained *_id column on every exported table', function () {
    $violations = [];

    foreach (BackupTableRegistry::tables() as $entry) {
        $table = $entry['table'];
        $columns = Schema::getColumnListing($table);
        $constrained = collect(Schema::getForeignKeys($table))->pluck('columns')->flatten()->all();
        $mapped = RowTransformer::PARENT_FK_MAP[$table] ?? [];
        $deferred = RowTransformer::DEFERRED_FK_COLUMNS[$table] ?? [];
        $polymorphicIds = array_column(RowTransformer::POLYMORPHIC_PAIRS[$table] ?? [], 'id');

        foreach ($columns as $column) {
            if ($column === 'id' || $column === 'company_id' || ! str_ends_with($column, '_id')) {
                continue;
            }

            if (in_array($column, $constrained, true) || ($this->isUserColumn)($column)) {
                continue; // the schema pass / generic user pass own these
            }

            $ref = "{$table}.{$column}";

            if (in_array($ref, $this->notReferences, true)
                || in_array($ref, $this->polymorphicNotRemapped, true)
                || in_array($ref, $this->knownGaps, true)) {
                continue;
            }

            if (isset($mapped[$column]) || isset($deferred[$column])) {
                continue;
            }

            $typeColumn = substr($column, 0, -3).'_type';

            if (in_array($typeColumn, $columns, true)) {
                if (! in_array($column, $polymorphicIds, true)) {
                    $violations[] = "{$ref}: polymorphic (`{$typeColumn}` sibling) but not in RowTransformer::POLYMORPHIC_PAIRS.";
                }

                continue;
            }

            $violations[] = "{$ref}: unconstrained `_id` column with no PARENT_FK_MAP / DEFERRED_FK_COLUMNS entry — add the remap, or list it in this test as a non-reference.";
        }
    }

    expect($violations)->toBe([], "Unconstrained reference columns RowTransformer would not remap:\n  - ".implode("\n  - ", $violations));
});

it('keeps the documented exceptions honest', function () {
    // Every allow-listed column must still exist and must still be handled
    // outside the map — otherwise the entry is stale and hides nothing.
    $exceptions = array_merge($this->quirkHandled, $this->notReferences, $this->polymorphicNotRemapped, $this->knownGaps);

    foreach ($exceptions as $ref) {
        [$table, $column] = explode('.', $ref, 2);

        expect(Schema::hasColumn($table, $column))->toBeTrue("{$ref} is listed as an exception but no longer exists.");
        expect(RowTransformer::PARENT_FK_MAP[$table][$column] ?? null)->toBeNull("{$ref} is both remapped and listed as an exception; drop the exception.");
        expect(RowTransformer::DEFERRED_FK_COLUMNS[$table][$column] ?? null)->toBeNull("{$ref} is both deferred and listed as an exception; drop the exception.");
    }
});

it('covers the opening-balance workspace tables that were missing from the map', function () {
    // Regression pin for the bug this guard was written against — the two
    // generic tests above would catch it, but a named failure is clearer.
    expect(RowTransformer::PARENT_FK_MAP['opening_balance_states'] ?? null)->toBe([
        'journal_entry_id' => 'journal_entries',
    ]);

    expect(RowTransformer::PARENT_FK_MAP['opening_balance_rows'] ?? null)->toBe([
        'opening_balance_state_id' => 'opening_balance_states',
        'account_id' => 'accounts',
    ]);
});
