<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default MQTT Connection
    |--------------------------------------------------------------------------
    |
    | We keep a single default connection for this pilot.
    | Laravel will use these values when our consumer command connects.
    |
    */

    'default_connection' => 'default',

    /*
    |--------------------------------------------------------------------------
    | MQTT Connections
    |--------------------------------------------------------------------------
    */

    'connections' => [
        'default' => [
            'host' => env('MQTT_HOST', '127.0.0.1'),
            'port' => (int) env('MQTT_PORT', 1883),
            'client_id' => env('MQTT_CLIENT_ID', 'laravel-meter-pilot'),

            // Clean session false is safer for long-running consumers.
            'use_clean_session' => true,

            // Helpful while debugging your pilot.
            'enable_logging' => true,

            'connection_settings' => [
                'username' => env('MQTT_USERNAME'),
                'password' => env('MQTT_PASSWORD'),
                'connect_timeout' => (int) env('MQTT_CONNECTION_TIMEOUT', 60),
                'keep_alive_interval' => (int) env('MQTT_KEEP_ALIVE_INTERVAL', 30),
            ],
        ],
    ],
];
