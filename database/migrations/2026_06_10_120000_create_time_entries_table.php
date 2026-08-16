<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A logged block of work by an employee on a day. It feeds payroll (the
        // employee's hours) and/or invoicing (billable time to a customer). The
        // nullable pay_run_id / invoice_id are "consumed" markers so the two flows
        // are independent and idempotent — an entry can be paid AND billed, never twice.
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete(); // the employee who worked
            $table->date('date_worked');
            $table->decimal('hours', 8, 2)->default(0);
            $table->text('description')->nullable();

            // Billing
            $table->boolean('billable')->default(false);
            $table->foreignId('customer_id')->nullable()->constrained('contacts')->nullOnDelete(); // bill-to
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete(); // service/labour item
            $table->integer('billable_rate_cents')->nullable(); // override; else item default price

            // Reporting dimensions (Classification = QuickBooks-style "class"/project)
            $table->foreignId('class_id')->nullable()->constrained('classifications')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            // Approval gate + consumption markers
            $table->string('status', 20)->default('approved'); // pending | approved | rejected
            $table->foreignId('pay_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'contact_id', 'date_worked']);
            $table->index(['company_id', 'customer_id', 'status']);
            $table->index(['company_id', 'pay_run_id']);
            $table->index(['company_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
