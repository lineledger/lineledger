<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-customer consent for outbound email. Both default off: LineLedger emails
     * a customer an invoice or a payment reminder only once someone has explicitly
     * turned it on for them. Adding the columns with a false default opts every
     * existing contact out, which is the behaviour we want on upgrade.
     *
     * These gate the *automated* senders only — the scheduled dunning sweep and
     * recurring "post and email" schedules. A human clicking Send still sends.
     *
     * `reminders_muted` is superseded by `reminder_emails_enabled` and is no longer
     * read anywhere, but the column stays: restore inserts a backup bundle's raw
     * columns, so dropping it would break every bundle taken before this release.
     * It can go the next time `config('version.schema')` is bumped.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('invoice_emails_enabled')->default(false)->after('reminders_muted');
            $table->boolean('reminder_emails_enabled')->default(false)->after('invoice_emails_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['invoice_emails_enabled', 'reminder_emails_enabled']);
        });
    }
};
