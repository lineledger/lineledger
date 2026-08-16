<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_api_keys', function (Blueprint $table) {
            // Null = full access (backward compatible with existing keys). A
            // populated array restricts the key to the listed `{domain}:{action}`
            // scopes (see App\Enums\ApiAbility).
            $table->json('abilities')->nullable()->after('last_four');
        });
    }

    public function down(): void
    {
        Schema::table('company_api_keys', function (Blueprint $table) {
            $table->dropColumn('abilities');
        });
    }
};
