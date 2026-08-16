<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('companies')
            ->whereNull('address_country')
            ->update(['address_country' => 'CA']);

        Schema::table('companies', function (Blueprint $table) {
            $table->string('address_country', 2)->default('CA')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('address_country', 2)->nullable()->default(null)->change();
        });
    }
};
