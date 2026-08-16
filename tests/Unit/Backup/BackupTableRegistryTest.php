<?php

use App\Services\Backup\BackupTableRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// Unit tests are not auto-bound to Laravel's TestCase by Pest.php (only Feature is),
// so we opt-in here because we need both the application container (for Schema) and
// migrations (so the schema is populated regardless of local DB state / CI driver).
uses(TestCase::class, RefreshDatabase::class);

test('every company_id-bearing table is either in the registry or explicitly excluded', function () {
    $registryTables = collect(BackupTableRegistry::tables())
        ->pluck('table')
        ->all();

    $excludedTables = collect(BackupTableRegistry::excludedTables())
        ->pluck('table')
        ->all();

    $knownTables = array_merge($registryTables, $excludedTables);

    // Pull every table on the current connection (portable across MySQL + SQLite).
    $tables = collect(Schema::getTables())
        ->pluck('name')
        ->all();

    $tablesWithCompanyId = [];
    foreach ($tables as $table) {
        if (in_array('company_id', Schema::getColumnListing($table), true)) {
            $tablesWithCompanyId[] = $table;
        }
    }

    $missing = array_diff($tablesWithCompanyId, $knownTables);

    expect($missing)->toBe([], sprintf(
        'Tables with `company_id` are missing from BackupTableRegistry::tables() AND excludedTables(): %s. '.
        'Add them to one of the two lists.',
        implode(', ', $missing)
    ));
});

test('every registry entry resolves to a real, instantiable model class with the expected table name', function () {
    foreach (BackupTableRegistry::tables() as $entry) {
        expect($entry)->toHaveKeys(['table', 'model', 'group']);

        $model = $entry['model'];
        $table = $entry['table'];

        expect(class_exists($model))->toBeTrue("Model class [{$model}] does not exist for table [{$table}]");

        $instance = new $model;
        expect($instance)->toBeInstanceOf(Model::class, "[{$model}] must be an Eloquent model");
        expect($instance->getTable())->toBe(
            $table,
            "Model [{$model}] reports table [{$instance->getTable()}] but registry says [{$table}]"
        );
    }
});
