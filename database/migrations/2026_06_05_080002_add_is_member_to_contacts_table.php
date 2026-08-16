<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Member role on contacts (parallel to is_customer / is_vendor / is_employee /
     * is_donor). The cheap "is this contact a member" filter; the richness lives on
     * the members table. A member is always also a customer so dues can be invoiced.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('is_member')->default(false)->after('donor_type');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('is_member');
        });
    }
};
