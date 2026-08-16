<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('industry', 40)->nullable()->after('is_personal');
            $table->string('organization_type', 40)->nullable()->after('industry');
            $table->timestamp('setup_completed_at')->nullable()->after('organization_type');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['industry', 'organization_type', 'setup_completed_at']);
        });
    }
};
