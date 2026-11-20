<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When an owner deletes an organization it is soft-deleted at once and
     * permanently purged once this moment passes (companies:purge-deleted).
     * Null on a live organization and on one a site admin marked deleted,
     * which stays held until the admin restores or purges it by hand.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('purge_at')->nullable()->after('deleted_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['purge_at']);
            $table->dropColumn('purge_at');
        });
    }
};
