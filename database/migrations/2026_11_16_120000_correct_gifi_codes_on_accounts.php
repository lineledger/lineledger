<?php

use App\Support\Gifi\GifiCodeCorrections;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Moves every account off a GIFI code the first catalog mislabelled, onto
     * the code CRA uses for the label the member picked (see
     * GifiCodeCorrections). The corrections chain (8620 → 8690 → 8710), so the
     * ids on each old code are read before anything moves; an account is then
     * updated by id, exactly once.
     */
    public function up(): void
    {
        $idsByOldCode = [];

        foreach (array_keys(GifiCodeCorrections::MAP) as $oldCode) {
            $idsByOldCode[(string) $oldCode] = DB::table('accounts')
                ->where('gifi_code', (string) $oldCode)
                ->pluck('id')
                ->all();
        }

        foreach ($idsByOldCode as $oldCode => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table('accounts')
                    ->whereIn('id', $chunk)
                    ->update(['gifi_code' => GifiCodeCorrections::MAP[$oldCode]]);
            }
        }
    }

    /**
     * Not reversed: the old codes meant something else to the CRA, and the
     * corrected and the untouched accounts can no longer be told apart.
     */
    public function down(): void {}
};
