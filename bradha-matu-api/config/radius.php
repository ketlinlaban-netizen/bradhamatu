<?php

return [

    'enabled' => env('RADIUS_ENABLED', false),

    'default_group' => env('RADIUS_DEFAULT_GROUP', 'bm-default'),

    'nas_secret' => env('RADIUS_NAS_SECRET', 'bradha-matu-radius-secret'),

    'nas_type' => env('RADIUS_NAS_TYPE', 'mikrotik'),

    'auth_type' => env('RADIUS_AUTH_TYPE', 'Cleartext-Password'),

    'session_timeout_attribute' => env('RADIUS_SESSION_TIMEOUT_ATTR', 'Max-All-Session'),

    'rate_limit_attribute' => env('RADIUS_RATE_LIMIT_ATTR', 'Mikrotik-Rate-Limit'),

    'interim_interval' => env('RADIUS_INTERIM_INTERVAL', 300),

    'idle_timeout' => env('RADIUS_IDLE_TIMEOUT', 600),

    'nas_identifier' => env('RADIUS_NAS_IDENTIFIER', 'bradha-matu'),

    'auto_register_nas' => env('RADIUS_AUTO_REGISTER_NAS', true),

    'ip_pool_name' => env('RADIUS_IP_POOL_NAME', 'main_pool'),
];
