<?php

use App\Models\PushSubscription;

/*
| Web push (laravel-notification-channels/webpush), see documentation/NOTIFICATIONS.md. The keys
| come only from the environment: without them push is off and Stocks sends mails only. Create them
| once with "php artisan webpush:vapid" and keep them; new keys end every subscription.
*/

return [

    'vapid' => [
        // contact of the instance for the push services, empty: APP_URL
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    // subscriptions of members and of borrower pages, with device name and last use
    'model' => PushSubscription::class,

    'table_name' => 'push_subscriptions',

    // the default connection of the instance
    'database_connection' => null,

    'client_options' => [],

    'automatic_padding' => env('WEBPUSH_AUTOMATIC_PADDING', true),

];
