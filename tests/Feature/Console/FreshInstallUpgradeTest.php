<?php

/*
| app:upgrade runs on every Docker boot (MIGRATE_ON_BOOT defaults to true) and
| from the Forge deploy script, so it must succeed on a brand-new install that
| has no organizations yet — the entrypoint runs under `set -e`, and a failing
| step would keep the app container from ever starting. And a dry run with
| migrations still pending must not run the data steps against a schema those
| migrations have not created yet.
*/

it('runs cleanly on a fresh install that has no organizations yet', function () {
    $this->artisan('app:upgrade', ['--verify' => true])
        ->expectsOutputToContain('No companies yet; nothing to backfill.')
        ->assertSuccessful();
});

it('treats no organizations at all as nothing to backfill, but a named one that is missing as an error', function (string $command) {
    $this->artisan($command)->expectsOutputToContain('No companies yet')->assertSuccessful();
    $this->artisan($command, ['company' => 'no-such-organization'])->assertFailed();
})->with(['banking:backfill-line-memos', 'banking:backfill-reconciliation-stamps']);

it('reports the data steps instead of running them while migrations are pending', function () {
    $dir = sys_get_temp_dir().'/app-upgrade-pending-'.uniqid();
    mkdir($dir);
    $file = $dir.'/2099_01_01_000000_app_upgrade_pending_probe.php';
    file_put_contents($file, "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class extends Migration\n{\n    public function up(): void {}\n};\n");
    app('migrator')->path($dir);

    try {
        $this->artisan('app:upgrade', ['--dry-run' => true, '--verify' => true])
            ->expectsOutputToContain('1 pending; a live run would apply them.')
            ->expectsOutputToContain('Runs after the 1 pending migration(s)')
            ->doesntExpectOutputToContain('would rewrite')
            ->assertSuccessful();
    } finally {
        unlink($file);
        rmdir($dir);
    }
});
