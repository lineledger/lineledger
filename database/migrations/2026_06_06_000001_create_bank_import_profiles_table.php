<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A saved, reusable column mapping for a bank's CSV / Excel export. Matched
        // to a new upload by header_signature so the same bank never needs remapping.
        Schema::create('bank_import_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('source_format');
            $table->json('mapping');
            $table->string('header_signature')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'account_id']);
            $table->index(['company_id', 'header_signature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_import_profiles');
    }
};
