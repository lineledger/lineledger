<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_audit_logs', function (Blueprint $table): void {
            $table->dropUnique(['row_hash']);
            $table->unique(['company_id', 'row_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('accounting_audit_logs', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'row_hash']);
            $table->unique('row_hash');
        });
    }
};
