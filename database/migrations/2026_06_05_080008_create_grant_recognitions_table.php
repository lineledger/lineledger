<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One recognition event against a deferred grant: DR deferred liability / CR
     * grant revenue. The GL-bearing ledger behind a grant's recognized-to-date.
     */
    public function up(): void
    {
        Schema::create('grant_recognitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grant_id')->constrained('grants')->cascadeOnDelete();
            $table->date('recognition_date');
            $table->bigInteger('amount_cents')->default(0);
            $table->text('memo')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'grant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grant_recognitions');
    }
};
