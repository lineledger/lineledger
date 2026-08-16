<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // Optional bcrypt-hashed password for the employee self-service
            // ("my-pay") portal. Null until the employee sets one via the
            // portal; magic-link sign-in keeps working either way.
            $table->string('portal_password')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('portal_password');
        });
    }
};
