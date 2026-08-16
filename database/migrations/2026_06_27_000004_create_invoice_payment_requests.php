<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone / deposit payment requests on an invoice (e.g. "50% deposit",
     * "$2,000 on completion"). Purely a presentation/tracking layer over the
     * invoice's single AR balance — no separate general-ledger posting — so the
     * resolved amount is stored in cents and the schedule is informational.
     */
    public function up(): void
    {
        Schema::create('invoice_payment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('request_type');           // percent | fixed
            $table->decimal('percent', 7, 4)->nullable();
            $table->integer('amount_cents');
            $table->date('due_date')->nullable();
            $table->string('status')->default('requested');  // requested | cancelled (paid is derived)
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payment_requests');
    }
};
