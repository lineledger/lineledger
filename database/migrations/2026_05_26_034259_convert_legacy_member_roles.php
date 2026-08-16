<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Section access was never enforced before, so legacy "member" rows could
     * reach every section except settings — the same reach the new Accountant
     * role grants. Convert them so behaviour is preserved.
     */
    public function up(): void
    {
        DB::table('company_members')->where('role', 'member')->update(['role' => 'accountant']);
        DB::table('company_invitations')->where('role', 'member')->update(['role' => 'accountant']);
    }

    public function down(): void
    {
        // The original "member" role no longer exists; nothing to reverse.
    }
};
