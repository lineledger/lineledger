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

    // The countries a new organization may be created in, as comma-separated
    // codes (CA, US, GB). Empty allows all three. An operator running one site
    // per country (APP_URL_CA / _US / _GB) sets each site to its own: the setup
    // wizard and the organizations page then offer only those, a restore
    // refuses a backup of an organization from anywhere else, and both point
    // to the sibling site that hosts the country. Organizations already here
    // are unaffected. See App\Support\Companies\NewCompanyCountries.
    'countries' => env('COMPANY_COUNTRIES', ''),

];
