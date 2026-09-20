<?php

return [

    /*
    |--------------------------------------------------------------------------
    | In-app support tickets
    |--------------------------------------------------------------------------
    |
    | On by default: users raise tickets from the Support entry in the account
    | menu, and site admins answer them from the admin portal.
    |
    | Turn it off on a deployment whose support runs somewhere else — an inbox,
    | an internal help desk, the team down the hall. The Support entry leaves
    | both account menus and /support stops answering. Nothing is deleted:
    | tickets already raised stay in the database and stay readable in the admin
    | portal, so switching it back on loses nothing.
    |
    */

    'enabled' => (bool) env('SUPPORT_ENABLED', true),

];
