<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which disk each uploaded restore bundle was staged on, mirroring
 * `company_backups.disk`.
 *
 * The upload is stored by the web request and read by the queue worker, which
 * on a multi-container host (DigitalOcean App Platform) has its own filesystem.
 * Bundles now go to the backups disk, so the worker can reach them; existing
 * rows default to `local`, which is where they actually are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_restores', function (Blueprint $table): void {
            $table->string('disk')->default('local')->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('company_restores', function (Blueprint $table): void {
            $table->dropColumn('disk');
        });
    }
};
