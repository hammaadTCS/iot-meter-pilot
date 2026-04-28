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

            // QoS 1 asks the broker to acknowledge delivery to this consumer.
            // The processor remains idempotent through the device_id + ts key,
            // so redeliveries should not create duplicate historical rows.
            'subscribe_qos' => min(2, max(0, (int) env('MQTT_SUBSCRIBE_QOS', 1))),

            // Long-running consumers should usually keep their session so the
            // client can reconnect cleanly without losing broker-side state.
            'use_clean_session' => env('MQTT_CLEAN_SESSION', false),

            // Helpful while debugging your pilot.
            'enable_logging' => env('MQTT_ENABLE_LOGGING', true),

            'connection_settings' => [
                'auth' => [
                    'username' => env('MQTT_USERNAME'),
                    'password' => env('MQTT_PASSWORD'),
                ],
                'connect_timeout' => (int) env('MQTT_CONNECTION_TIMEOUT', 60),
                'socket_timeout' => (int) env('MQTT_SOCKET_TIMEOUT', 5),
                'resend_timeout' => (int) env('MQTT_RESEND_TIMEOUT', 10),
                'keep_alive_interval' => (int) env('MQTT_KEEP_ALIVE_INTERVAL', 30),
                'auto_reconnect' => [
                    'enabled' => env('MQTT_AUTO_RECONNECT_ENABLED', true),
                    'max_reconnect_attempts' => (int) env('MQTT_AUTO_RECONNECT_MAX_RECONNECT_ATTEMPTS', 10),
                    'delay_between_reconnect_attempts' => (int) env('MQTT_AUTO_RECONNECT_DELAY_BETWEEN_RECONNECT_ATTEMPTS', 1000),
                ],
            ],

            // The outer artisan command also reconnects if the MQTT client loop
            // exits. A small exponential backoff avoids hammering the broker
            // during outages while still recovering quickly from short drops.
            'retry' => [
                'base_delay_seconds' => max(1, (int) env('MQTT_RETRY_DELAY', 5)),
                'max_delay_seconds' => max(1, (int) env('MQTT_RETRY_MAX_DELAY', 60)),
            ],
        ],
    ],
];
