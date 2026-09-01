<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The draft trial balance: one target per account. Targets are what the
     * books SHOULD show at the as-of date; the synchronizer nets them against
     * whatever is already posted and maintains a single opening journal entry
     * for the difference. AR/AP/Inventory targets are never posted directly —
     * they are satisfied by opening documents (invoices, bills, cheques,
     * deposits) so the sub-ledgers stay attributable per contact.
     */
    public function up(): void
    {
        Schema::create('opening_balance_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opening_balance_state_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('debit_cents')->default(0);
            $table->bigInteger('credit_cents')->default(0); // app enforces one side only
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['opening_balance_state_id', 'account_id'], 'obr_state_account_uq');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balance_rows');
    }
};
