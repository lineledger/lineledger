<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * BC and Yukon companies were defaulted to America/Los_Angeles, and the
     * settings picker offered no Canadian Pacific zone to change it to. BC stays
     * on UTC−7 from 2026-11-01 (Yukon has since 2020) while Los Angeles drops
     * to UTC−8 each winter, which would put the company's "today" an hour behind
     * its own clocks. Only the old default moves: any other zone an owner chose
     * is left alone.
     */
    public function up(): void
    {
        foreach (['BC' => 'America/Vancouver', 'YT' => 'America/Whitehorse'] as $region => $timezone) {
            DB::table('companies')
                ->where('address_country', 'CA')
                ->where('address_region', $region)
                ->where('timezone', 'America/Los_Angeles')
                ->update(['timezone' => $timezone]);
        }
    }

    public function down(): void
    {
        // A company may have chosen these zones itself; nothing to reverse.
    }
};
