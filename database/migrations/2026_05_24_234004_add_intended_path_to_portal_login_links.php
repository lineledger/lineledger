<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('portal_login_links', function (Blueprint $table) {
            // Server-generated relative path to redirect to after sign-in (e.g.
            // a specific invoice). Null = land on the dashboard.
            $table->string('intended_path')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('portal_login_links', function (Blueprint $table) {
            $table->dropColumn('intended_path');
        });
    }
};
