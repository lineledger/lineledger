<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // In-app support tickets. Platform-level (submitted BY a user TO the
        // Site Admin operator), so — unlike almost every other table — these are
        // NOT company-scoped: no BelongsToCompany trait, no CompanyScope, and
        // deliberately excluded from per-company backup/restore. `company_id` is
        // kept only as triage context (which org the user was in), nullable.
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();

            $table->string('subject');
            // general | bug | feature
            $table->string('type')->default('general');
            // open | answered | resolved
            $table->string('status')->default('open');

            $table->timestamp('last_activity_at')->nullable();

            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
        });

        // One message in a ticket thread. `from_admin` distinguishes a Site Admin
        // reply from the ticket owner's message; `read_at` is set when the *other*
        // party reads it, which drives the unread badges on both sides.
        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->boolean('from_admin')->default(false);
            $table->text('body');
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index('support_ticket_id');
            $table->index(['from_admin', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
