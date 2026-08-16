<?php

namespace App\Services\Migration\Importers;

class VendorsImporter extends AbstractContactImporter
{
    protected function role(): string
    {
        return 'vendor';
    }
}
