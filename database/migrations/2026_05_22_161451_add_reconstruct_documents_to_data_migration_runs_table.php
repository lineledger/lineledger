<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_migration_runs', function (Blueprint $table) {
            // Full-history only: reconstruct native documents (invoices, bills, cheques,
            // …) from recognised transaction types instead of leaving them as plain
            // journal entries.
            $table->boolean('reconstruct_documents')->default(false)->after('link_contact_names');
        });
    }

    public function down(): void
    {
        Schema::table('data_migration_runs', function (Blueprint $table) {
            $table->dropColumn('reconstruct_documents');
        });
    }
};
