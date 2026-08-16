<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A membership record: one per contact per company. Tier, term dates, and dues
     * override live here; the effective status (Active/Lapsed/Expired/Cancelled) is
     * derived from expires_on + cancelled_at, never persisted. Dues are billed as
     * invoices; auto_renew members get a linked recurring_document.
     */
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_level_id')->nullable()->constrained('membership_levels')->nullOnDelete();
            $table->string('member_no', 40)->nullable();
            $table->date('joined_on')->nullable();
            $table->date('started_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->unsignedBigInteger('dues_cents')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->foreignId('recurring_document_id')->nullable()->constrained('recurring_documents')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'contact_id']);
            $table->index(['company_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
