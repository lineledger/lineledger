<?php

/*
|--------------------------------------------------------------------------
| Organization backup and restore
|--------------------------------------------------------------------------
|
| A backup carries the users who worked in the organization (users.json).
| Restoring it attributes their work to people on this server:
|
|   'instance'  (default) any user here whose email matches. Right for a
|               self-hosted server, where everyone on it works together.
|   'restorer'  only the person restoring, by their own email. Everyone else
|               in the backup is attributed to them, as an unmatched user
|               always is. Use it on a server shared by unrelated customers,
|               so a crafted backup can neither confirm that someone else has
|               an account here nor make them a member of the copy.
|
| See App\Services\Restore\UserRemapBuilder.
|
*/

return [

    'restore_user_matching' => env('RESTORE_USER_MATCHING', 'instance'),

];
