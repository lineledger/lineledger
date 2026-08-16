<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How an invoice schedule issues each occurrence: draft (default, current
     * behaviour), post automatically, or post and email. Existing schedules keep
     * generating drafts.
     */
    public function up(): void
    {
        Schema::table('recurring_documents', function (Blueprint $table) {
            $table->string('automation_mode')->default('draft')->after('document_type');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_documents', function (Blueprint $table) {
            $table->dropColumn('automation_mode');
        });
    }
};
