<?php

use App\Enums\AccountSubtype;
use App\Support\Gifi\GifiCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the GIFI (CRA General Index of Financial Information) line code to each
     * account. Drives the GIFI Statement report for Canadian companies. Existing
     * accounts are backfilled with a sensible default derived from their subtype
     * so the report is useful out of the box; the mapping is fully editable after.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('gifi_code', 4)->nullable()->after('subtype');
            $table->index(['company_id', 'gifi_code']);
        });

        foreach (AccountSubtype::cases() as $subtype) {
            $default = GifiCatalog::defaultForSubtype($subtype);

            if ($default === null) {
                continue;
            }

            DB::table('accounts')
                ->where('subtype', $subtype->value)
                ->whereNull('gifi_code')
                ->update(['gifi_code' => $default]);
        }
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'gifi_code']);
            $table->dropColumn('gifi_code');
        });
    }
};
