<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | Written by hand (Requirement 4): install:broadcasting edits bootstrap/app.php, which is frozen.
    | "reverb" is the real-time server (the reverb service in docker-compose.yml). The names of the
    | settings below are the ones read by Illuminate\Broadcasting\BroadcastManager.
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            // How PHP reaches the reverb container. The browser uses http://localhost:8080 through nginx instead.
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            // Guzzle options. Short timeouts, so a dead Reverb never stalls an order request
            // (the defaults are 10 seconds to connect and 30 seconds in total).
            'client_options' => [
                'connect_timeout' => 1,
                'timeout' => 2,
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
