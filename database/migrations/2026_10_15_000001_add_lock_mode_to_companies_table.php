<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How the lock date treats a posting on or before it: 'block' refuses it
     * (Owners and Admins can override with their password), 'warn' asks for
     * confirmation and lets it through. Existing companies keep today's
     * hard block.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('lock_mode', 16)->default('block')->after('lock_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('lock_mode');
        });
    }
};
