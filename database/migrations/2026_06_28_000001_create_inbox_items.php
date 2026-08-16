<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One inbound document (uploaded receipt/bill, or — later — an emailed
        // attachment) staged for OCR, review and promotion into a draft bill or
        // expense. The async OCR job fills `extracted`/`status`; the user reviews
        // and promotes. Source file lives as a polymorphic Attachment.
        Schema::create('inbox_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // upload | email
            $table->string('source')->default('upload');
            // pending | processing | needs_review | promoted | dismissed | failed
            $table->string('status')->default('pending');

            $table->foreignId('attachment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_filename')->nullable();
            $table->string('mime')->nullable();
            $table->string('sender_email')->nullable();

            // OCR output: { vendor, amount_cents, currency, date, line_items[] }.
            $table->json('extracted')->nullable();

            $table->foreignId('suggested_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            // bill | expense | reimbursement
            $table->string('suggested_document_type')->nullable();

            $table->string('promoted_document_type')->nullable();
            $table->unsignedBigInteger('promoted_document_id')->nullable();

            $table->text('ocr_error')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_items');
    }
};
