<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Watermark of how far each company's audit hash chain has been verified.
 *
 * Verifying from genesis every night is O(all history ever written), and the
 * chain is append-only — nothing prunes it. This table lets `audit:verify`
 * resume from the last clean row instead, making the nightly cost O(new rows).
 *
 * Derived state, never authoritative: deleting a row simply forces the next run
 * to re-walk that company from genesis and write it again. That is also why the
 * table is deliberately NOT immutable the way accounting_audit_logs is — the
 * watermark advances by design.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_chain_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_verified_sequence');

            // The row_hash at last_verified_sequence, re-checked before the
            // watermark is trusted. A checkpoint that no longer matches the
            // chain it describes is discarded rather than believed.
            $table->char('last_verified_row_hash', 64);
            $table->timestamp('verified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_checkpoints');
    }
};
