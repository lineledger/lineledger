<?php

/*
|--------------------------------------------------------------------------
| Organization lifecycle
|--------------------------------------------------------------------------
|
| When an owner deletes an organization, members lose access immediately but
| its data is kept for a grace period, during which a site admin can restore
| it. Once the grace period ends, companies:purge-deleted (daily) erases every
| row and file it owns. See App\Actions\Companies\DeleteCompany / PurgeCompany.
|
*/

return [

    // Days between an owner deleting an organization and its permanent purge.
    'purge_after_days' => (int) env('COMPANY_PURGE_AFTER_DAYS', 30),

];
