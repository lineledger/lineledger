<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-company default for copying the business's own email on every invoice
     * sent (and, later, automated reminders) — a paper trail of exactly what
     * customers receive. Off by default so nothing changes for existing senders.
     */
    public function up(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table) {
            $table->boolean('email_cc_self')->default(false)->after('email_default_message');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table) {
            $table->dropColumn('email_cc_self');
        });
    }
};
