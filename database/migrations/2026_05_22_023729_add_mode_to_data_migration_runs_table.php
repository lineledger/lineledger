<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_migration_runs', function (Blueprint $table) {
            // opening_balance (standard conversion-date model) | full_history (GL replay)
            $table->string('mode', 20)->default('opening_balance')->after('status');
            // Full-history only: earliest source transaction date the user intends to bring over.
            $table->date('history_start_date')->nullable()->after('conversion_date');
            // Full-history only: create GL accounts encountered in the source that don't exist yet.
            $table->boolean('auto_create_accounts')->default(false)->after('open_bills_use_original_date');
            // Full-history only: link journal lines to contacts by matching the source name.
            $table->boolean('link_contact_names')->default(true)->after('auto_create_accounts');
        });
    }

    public function down(): void
    {
        Schema::table('data_migration_runs', function (Blueprint $table) {
            $table->dropColumn(['mode', 'history_start_date', 'auto_create_accounts', 'link_contact_names']);
        });
    }
};
