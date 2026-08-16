<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->timestamp('recorded_at', 6);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_ip', 45)->nullable();
            $table->string('actor_user_agent', 512)->nullable();
            $table->string('action', 64);
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->json('payload');
            $table->mediumText('hash_input');
            $table->char('previous_hash', 64);
            $table->char('row_hash', 64);

            $table->unique(['company_id', 'sequence']);
            $table->unique('row_hash');
            $table->index(['company_id', 'recorded_at']);
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['actor_user_id', 'recorded_at']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER accounting_audit_logs_no_update
                BEFORE UPDATE ON accounting_audit_logs
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'accounting_audit_logs are immutable';
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER accounting_audit_logs_no_delete
                BEFORE DELETE ON accounting_audit_logs
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'accounting_audit_logs are immutable';
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS accounting_audit_logs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS accounting_audit_logs_no_delete');
        }

        Schema::dropIfExists('accounting_audit_logs');
    }
};
