<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-controlled account lockout, set from the site admin portal.
 *
 * A disabled user cannot sign in and any live session is torn down on their
 * next request. Deliberately separate from deleting the account: it is fully
 * reversible, and their companies keep working for every other member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('disabled_at')->nullable()->index();
            $table->string('disabled_by')->nullable();
            $table->string('disabled_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['disabled_at']);
            $table->dropColumn(['disabled_at', 'disabled_by', 'disabled_reason']);
        });
    }
};
