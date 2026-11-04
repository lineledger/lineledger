<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A role's access to one activity: Full, or Partial with the actions ticked.
 * No row means None. One boolean per action (not a JSON column) so reports
 * and checks can filter on them in plain WHERE clauses on MySQL and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('activity', 60);
            $table->string('access', 10);
            $table->boolean('can_view')->default(false);
            $table->boolean('can_create')->default(false);
            $table->boolean('can_modify')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->boolean('can_print')->default(false);
            $table->boolean('can_view_balance')->default(false);
            $table->timestamps();

            $table->unique(['role_id', 'activity']);
            $table->index(['company_id', 'activity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
