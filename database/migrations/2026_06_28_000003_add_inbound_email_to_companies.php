<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Email-to-inbox (P4.2). A company that opts in is minted a per-tenant
     * forwarding token; its forwarding address is `inbox+{token}@{domain}`. The
     * inbound-email webhook resolves the tenant from the token, allow-lists the
     * sender against active members, and stages each attachment as an inbox item.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Per-tenant, opaque routing token embedded in the forwarding address.
            // Unique + nullable: minted on first enable, rotatable, null when off.
            $table->string('inbound_email_token')->nullable()->unique()->after('settings');
            // Master switch for this tenant's inbound-email ingest. Default off.
            $table->boolean('inbound_email_enabled')->default(false)->after('inbound_email_token');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['inbound_email_token']);
            $table->dropColumn(['inbound_email_token', 'inbound_email_enabled']);
        });
    }
};
