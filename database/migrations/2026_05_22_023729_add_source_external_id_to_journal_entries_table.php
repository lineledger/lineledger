<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            // Deterministic per-transaction key for idempotent imports (e.g. QBD full-history
            // GL replay). Lets a re-run of the same source file skip already-imported entries.
            $table->string('source_external_id')->nullable()->after('source_id');

            $table->unique(['company_id', 'source_external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'source_external_id']);
            $table->dropColumn('source_external_id');
        });
    }
};
