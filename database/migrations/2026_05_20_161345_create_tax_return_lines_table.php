<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_line_id')->nullable()->constrained('journal_lines')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('bucket', 12); // collected|paid
            $table->bigInteger('amount_cents');
            $table->string('entry_no');
            $table->date('entry_date');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('doc_label');
            $table->boolean('is_reversal')->default(false);
            $table->unsignedSmallInteger('line_order')->default(0);
            $table->timestamps();

            $table->index(['tax_return_id', 'bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_return_lines');
    }
};
