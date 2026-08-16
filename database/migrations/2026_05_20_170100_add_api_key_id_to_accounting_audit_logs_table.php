<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_audit_logs', function (Blueprint $table) {
            $table->foreignId('api_key_id')
                ->nullable()
                ->after('actor_user_id')
                ->constrained('company_api_keys')
                ->nullOnDelete();

            $table->index(['api_key_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::table('accounting_audit_logs', function (Blueprint $table) {
            $table->dropIndex(['api_key_id', 'recorded_at']);
            $table->dropConstrainedForeignId('api_key_id');
        });
    }
};
