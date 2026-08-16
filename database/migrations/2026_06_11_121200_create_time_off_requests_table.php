<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An employee's request for time off (vacation, sick, banked, unpaid …),
        // walked through a two-level approval: the designated approver (or any
        // payroll user) accepts the ABSENCE, then payroll confirms the PAY
        // treatment — which generates the matching Approved time entries that
        // the pay-run pull consumes and draws balances from.
        Schema::create('time_off_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            // restrict: requests are the approval/audit record of absences —
            // deleting a policy must fail loud, never silently erase history.
            $table->foreignId('time_off_policy_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('hours_per_day', 5, 2);
            $table->decimal('total_hours', 7, 2);
            $table->text('employee_note')->nullable();
            $table->string('status', 20)->default('pending');
            // Step 1: the manager/approver accepts or denies the absence.
            $table->foreignId('manager_decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('manager_decided_at')->nullable();
            $table->text('manager_note')->nullable();
            // Step 2: payroll confirms the pay treatment (or denies/cancels).
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'start_date', 'end_date']);
        });

        // Entries generated from an approved request carry the link so a later
        // deny/cancel can remove exactly the unconsumed ones.
        Schema::table('time_entries', function (Blueprint $table) {
            $table->foreignId('time_off_request_id')->nullable()->after('invoice_id')->constrained()->nullOnDelete();
        });

        // The employee's designated absence approver (step 1). Null = any
        // payroll-section user handles both steps.
        Schema::table('employee_payroll_profiles', function (Blueprint $table) {
            $table->foreignId('approver_user_id')->nullable()->after('banked_overtime_multiplier_bp')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_payroll_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approver_user_id');
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('time_off_request_id');
        });

        Schema::dropIfExists('time_off_requests');
    }
};
