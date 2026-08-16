<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a general business email plus a dedicated print/document logo (separate
     * from the sidebar branding logo) and a user-set max-height for that logo on
     * printed documents.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('email')->nullable()->after('website');
            $table->string('document_logo_path')->nullable()->after('logo_path');
            $table->unsignedSmallInteger('document_logo_max_height')->default(64)->after('document_logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'email',
                'document_logo_path',
                'document_logo_max_height',
            ]);
        });
    }
};
