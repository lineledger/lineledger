<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles: named bundles of activity permissions a company assigns to its users
 * (QuickBooks Desktop Enterprise's Role List). Every company starts with the
 * predefined roles (`system_key` set); people can edit them and add their own.
 *
 * A predefined role is copy-on-write: until someone edits it
 * (`customized_at` null) its permissions come from the code catalogue, so it
 * picks up new activities in later releases on its own. `legacy_key` marks a
 * role generated from a Custom member's section ticks when roles arrived, so
 * members with the same ticks share it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('system_key', 40)->nullable();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->timestamp('customized_at')->nullable();
            $table->unsignedSmallInteger('catalog_version')->default(1);
            $table->string('legacy_key')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'system_key']);
            $table->index(['company_id', 'legacy_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
