<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flags a previously-working Stripe Connect link whose access was revoked or
     * is otherwise unreachable with the platform key. When set (alongside a
     * non-null stripe_account_id), the portal stops offering card payments and
     * company settings prompts the owner to reconnect. Cleared on (re)connect and
     * on a manual disconnect.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('stripe_disconnected_at')->nullable()->after('stripe_connected_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('stripe_disconnected_at');
        });
    }
};
