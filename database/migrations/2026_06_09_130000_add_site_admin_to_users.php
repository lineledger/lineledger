<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A platform-level (not company-scoped) operator who can reach the
            // site admin portal: toggle site-wide features, browse every user
            // and company, and grant the role to others. The first user to
            // register is granted this automatically (see CreateNewUser).
            // Default false so the flag is only ever set deliberately.
            $table->boolean('site_admin')->default(false)->after('calculator_mode');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('site_admin');
        });
    }
};
