<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which disk each backup ZIP was written to, mirroring `attachments.disk`.
 *
 * Without it, a deployment that moves backups to object storage can no longer
 * find — or prune — the archives it produced beforehand. Existing rows default
 * to `local`, which is where they actually are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_backups', function (Blueprint $table): void {
            $table->string('disk')->default('local')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('company_backups', function (Blueprint $table): void {
            $table->dropColumn('disk');
        });
    }
};
