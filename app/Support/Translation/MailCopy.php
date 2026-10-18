<?php

namespace App\Support\Translation;

/**
 * Strings from Laravel's own notification and mail templates
 * (vendor/laravel/framework/.../Notifications/resources/views/email.blade.php
 * and Mail/resources/views/{html,text}/message.blade.php). KeyExtractor only
 * scans app/ and resources/, so they're registered here for lang/fr.json.
 */
final class MailCopy
{
    /**
     * @return list<string>
     */
    public static function strings(): array
    {
        return [
            __('Hello!'),
            __('Whoops!'),
            __('Regards,'),
            __("If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\ninto your web browser:"),
            __('All rights reserved.'),
        ];
    }
}
