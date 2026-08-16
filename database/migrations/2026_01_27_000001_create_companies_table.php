<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_personal')->default(false);
            $table->string('legal_name')->nullable();
            $table->char('currency_code', 3)->default('CAD');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->date('lock_date')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('address_city')->nullable();
            $table->string('address_region')->nullable();
            $table->string('address_postal_code')->nullable();
            $table->string('address_country', 2)->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('company_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
        });

        Schema::create('company_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role');
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_invitations');
        Schema::dropIfExists('company_members');
        Schema::dropIfExists('companies');
    }
};
