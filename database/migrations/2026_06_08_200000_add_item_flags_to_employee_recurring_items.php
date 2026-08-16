<?php

use App\Support\Payroll\EarningTypeCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_recurring_items', function (Blueprint $table) {
            // Type classification (e.g. Allowances, Overtime, Pension) — drives the
            // default flags + T4 box when picked, but the flags are editable per item.
            $table->string('type', 60)->nullable()->after('kind');

            // Accrual calculation basis (accruals only): hours | dollars | units |
            // miles | percent_of_earnings | cents_per_hour | percent_of_hours.
            $table->string('calc_basis', 30)->nullable()->after('calc_type');

            // Per-item tax-treatment flags. Defaults match the prior all-pensionable/
            // insurable/taxable wage behaviour; the engine reads these going forward.
            $table->boolean('taxable_federal')->default(true);
            $table->boolean('taxable_provincial')->default(true);
            $table->boolean('cpp_qpp')->default(true);
            $table->boolean('qpip')->default(true);
            $table->boolean('ei_insurable_earnings')->default(true);
            $table->boolean('ei_insurable_hours')->default(true);
            $table->boolean('wcb_eligible')->default(true);
            $table->boolean('tax_as_bonus')->default(false);
            $table->boolean('primary_earnings')->default(false);
            $table->boolean('add_to_net_pay_only')->default(false);
            $table->boolean('subtract_from_salary')->default(false);
            $table->boolean('stat_holiday_eligible')->default(false);
            $table->boolean('stat_holiday_payout')->default(false);
            $table->boolean('pre_tax_federal')->default(false);
            $table->boolean('pre_tax_provincial')->default(false);
        });

        // Backfill existing rows so behaviour is unchanged: earnings take their
        // flags from the code catalogue; deductions map reduces_taxable → pre-tax.
        foreach (DB::table('employee_recurring_items')->get() as $item) {
            $update = [];

            if ($item->kind === 'earning') {
                $flags = EarningTypeCatalogue::flags((string) $item->code);
                $update = [
                    'taxable_federal' => $flags['taxable'],
                    'taxable_provincial' => $flags['taxable'],
                    'cpp_qpp' => $flags['pensionable'],
                    'qpip' => $flags['insurable'],
                    'ei_insurable_earnings' => $flags['insurable'],
                    'add_to_net_pay_only' => $item->code === 'reimbursement',
                ];
            } elseif ($item->kind === 'deduction') {
                $update = [
                    'pre_tax_federal' => (bool) $item->reduces_taxable,
                    'pre_tax_provincial' => (bool) $item->reduces_taxable,
                ];
            }

            if ($update !== []) {
                DB::table('employee_recurring_items')->where('id', $item->id)->update($update);
            }
        }
    }

    public function down(): void
    {
        Schema::table('employee_recurring_items', function (Blueprint $table) {
            $table->dropColumn([
                'type', 'calc_basis',
                'taxable_federal', 'taxable_provincial', 'cpp_qpp', 'qpip',
                'ei_insurable_earnings', 'ei_insurable_hours', 'wcb_eligible', 'tax_as_bonus',
                'primary_earnings', 'add_to_net_pay_only', 'subtract_from_salary',
                'stat_holiday_eligible', 'stat_holiday_payout', 'pre_tax_federal', 'pre_tax_provincial',
            ]);
        });
    }
};
