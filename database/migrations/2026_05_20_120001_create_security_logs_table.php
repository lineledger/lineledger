<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('recorded_at', 6);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('attempted_email')->nullable();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('event', 64);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('metadata')->nullable();

            $table->index(['user_id', 'recorded_at']);
            $table->index(['event', 'recorded_at']);
            $table->index(['ip_address', 'recorded_at']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER security_logs_no_update
                BEFORE UPDATE ON security_logs
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'security_logs are immutable';
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER security_logs_no_delete
                BEFORE DELETE ON security_logs
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'security_logs are immutable';
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS security_logs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS security_logs_no_delete');
        }

        Schema::dropIfExists('security_logs');
    }
};
