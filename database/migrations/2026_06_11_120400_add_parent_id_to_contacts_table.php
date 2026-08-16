<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sub-customers / jobs: a contact can nest under a parent contact (QuickBooks
     * sub-customers). Self-referential; null = a top-level contact.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('id')->constrained('contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
