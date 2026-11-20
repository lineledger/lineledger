<?php

use App\Enums\DeletableDocumentType;
use App\Models\AccountingAuditLog;
use App\Models\AssetDepreciationEntry;
use App\Models\JournalLine;
use App\Models\McpWriteProposal;
use App\Models\StockMovement;
use App\Models\TaxReturnLine;
use App\Services\Deletion\DeletableDocuments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/*
 * Every record that owns a journal entry is either deletable (a handler in
 * DeletableDocuments) or deliberately void-only, with the reason. A new
 * posting document fails here until someone decides which.
 */

/** Holders of a journal entry id that are not transactions themselves. */
function deletionCoverageNotDocuments(): array
{
    return [
        JournalLine::class => 'a line of the entry itself',
        StockMovement::class => 'a stock movement of its transaction',
        TaxReturnLine::class => 'a filed return\'s snapshot line',
        AccountingAuditLog::class => 'audit history',
        AssetDepreciationEntry::class => 'the depreciation schedule\'s link to its entry',
        McpWriteProposal::class => 'the proposal that created the entry',
    ];
}

it('lists every record that owns a journal entry as deletable or void-only', function () {
    $columns = ['journal_entry_id', 'award_journal_entry_id', 'adjustment_journal_entry_id', 'service_charge_entry_id'];
    $deletable = array_map(fn (DeletableDocumentType $t): string => $t->modelClass(), DeletableDocumentType::cases());
    $known = [...$deletable, ...array_keys(DeletableDocuments::voidOnly()), ...array_keys(deletionCoverageNotDocuments())];
    $missing = [];

    foreach (glob(app_path('Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        $table = (new $class)->getTable();

        if (! Schema::hasTable($table)) {
            continue;
        }

        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column) && ! in_array($class, $known, true)) {
                $missing[] = $class.' ('.$table.'.'.$column.')';
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([], 'These own a journal entry but are neither deletable (DeletableDocuments::handlers()) nor listed in DeletableDocuments::voidOnly(): '.implode(', ', $missing));
});

it('has a handler for every deletable type, and no type is also void-only', function () {
    foreach (DeletableDocumentType::cases() as $type) {
        expect(DeletableDocuments::handlers())->toHaveKey($type->value)
            ->and(DeletableDocuments::voidOnly())->not->toHaveKey($type->modelClass());
    }
});
