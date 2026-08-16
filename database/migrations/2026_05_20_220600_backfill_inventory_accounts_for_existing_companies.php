<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\NormalBalance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $companies = DB::table('companies')->select('id')->get();

        foreach ($companies as $company) {
            $inventoryId = DB::table('accounts')
                ->where('company_id', $company->id)
                ->where('subtype', AccountSubtype::Inventory->value)
                ->value('id');

            if (! $inventoryId) {
                $inventoryId = DB::table('accounts')->insertGetId([
                    'company_id' => $company->id,
                    'code' => '1400',
                    'name' => 'Inventory Asset',
                    'type' => AccountType::Asset->value,
                    'subtype' => AccountSubtype::Inventory->value,
                    'normal_balance' => NormalBalance::Debit->value,
                    'is_system' => true,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('accounts')
                ->where('company_id', $company->id)
                ->where('subtype', AccountSubtype::CostOfGoodsSold->value)
                ->update(['is_system' => true]);

            $cogsId = DB::table('accounts')
                ->where('company_id', $company->id)
                ->where('subtype', AccountSubtype::CostOfGoodsSold->value)
                ->value('id');

            DB::table('companies')->where('id', $company->id)->update([
                'default_inventory_asset_account_id' => $inventoryId,
                'default_cogs_account_id' => $cogsId,
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive backfill — no automatic rollback.
    }
};
