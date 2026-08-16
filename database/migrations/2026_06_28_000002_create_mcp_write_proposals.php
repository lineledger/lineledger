<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staging table for the agentic (write-enabled) MCP server's propose -> confirm
     * flow. Because MCP has no UI, a write tool cannot show a draft for the user to
     * approve; instead a Propose* tool persists the normalized, validated payload
     * here (writing NOTHING to the ledger) and returns a token. A single
     * ConfirmProposal tool later replays the stored payload through the real Save
     * action (+ Poster), making the commit explicit, auditable, and idempotent.
     *
     * The row is the only durable artifact of a proposal; it never touches the GL.
     * `confirmed_journal_entry_id` records the posted entry so a re-confirm (replay)
     * is a no-op that returns the prior result instead of double-posting.
     */
    public function up(): void
    {
        Schema::create('mcp_write_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // The API-key path binds a key; the OAuth path binds a user. Either (or
            // in rare cases both) identifies who proposed the write — recorded for
            // the audit trail. Both nullable so a proposal survives key/user removal.
            $table->foreignId('company_api_key_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool');                 // invoice | bill | journal_entry | expense
            $table->json('payload');                // the cents-normalized $data for the Save action
            $table->text('preview');                // human-readable summary shown to the LLM/user
            $table->string('idempotency_key')->unique(); // the token returned to the caller
            $table->string('status')->default('pending'); // pending | confirmed | expired | rejected
            $table->foreignId('confirmed_journal_entry_id')
                ->nullable()
                ->constrained('journal_entries')
                ->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_write_proposals');
    }
};
