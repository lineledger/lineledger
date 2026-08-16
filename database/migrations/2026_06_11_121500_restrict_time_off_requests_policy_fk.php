<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Databases migrated before the create-table migration was corrected
        // carry a cascadeOnDelete policy FK — deleting a policy would silently
        // erase the absence approval history. Tighten it to restrict. Fresh
        // databases (and SQLite CI) already get restrict from the corrected
        // create migration; SQLite can't alter FKs in place, so skip there.
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('time_off_requests', function (Blueprint $table) {
            $table->dropForeign(['time_off_policy_id']);
            $table->foreign('time_off_policy_id')->references('id')->on('time_off_policies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('time_off_requests', function (Blueprint $table) {
            $table->dropForeign(['time_off_policy_id']);
            $table->foreign('time_off_policy_id')->references('id')->on('time_off_policies')->cascadeOnDelete();
        });
    }
};
