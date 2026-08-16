<?php

namespace App\Services\Migration\Importers;

use App\Services\Migration\ImportContext;
use App\Services\Migration\ImportResult;

interface Importer
{
    /**
     * @return list<string>
     */
    public function templateHeaders(): array;

    /**
     * @return list<array<string, string>>
     */
    public function templateExampleRows(): array;

    /**
     * Parse + validate without persisting. The wizard calls this on upload to
     * give the user a preview and a chance to fix their CSV.
     */
    public function preview(string $csvPath, ImportContext $ctx): ImportResult;

    /**
     * Persist. Runs inside a DB::transaction so per-row failures roll back
     * the whole import. Callers should check ImportResult::isOk() afterwards.
     */
    public function commit(string $csvPath, ImportContext $ctx): ImportResult;
}
