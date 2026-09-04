<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A bank rule may also name the payee, so a matching outflow is recorded as
     * an expense to that vendor rather than a bare journal entry.
     */
    public function up(): void
    {
        Schema::table('bank_rules', function (Blueprint $table) {
            $table->foreignId('action_contact_id')->nullable()->after('action_account_id')->constrained('contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('action_contact_id');
        });
    }
};
