<?php

namespace App\Services\Migration\Importers;

class CustomersImporter extends AbstractContactImporter
{
    protected function role(): string
    {
        return 'customer';
    }
}
