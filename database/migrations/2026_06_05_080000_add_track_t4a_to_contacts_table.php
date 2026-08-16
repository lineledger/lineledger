<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flags a vendor as a T4A recipient (Canada only) — typically a contractor
     * paid fees for services. Year-end disbursements to flagged vendors are
     * summed by the T4A Summary report into Box 048. The Canadian analog of
     * track_1099.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('track_t4a')->default(false)->after('track_1099');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('track_t4a');
        });
    }
};
