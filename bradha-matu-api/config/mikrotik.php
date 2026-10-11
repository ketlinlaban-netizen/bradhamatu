<?php

return [
    'provider' => env('ROUTER_PROVIDER', 'mikrotik'),
    'connect_timeout' => (int) env('MIKROTIK_CONNECT_TIMEOUT', env('MIKROTIK_TIMEOUT', 3)),
    'query_timeout' => (int) env('MIKROTIK_QUERY_TIMEOUT', env('MIKROTIK_TIMEOUT', 3)),
    'allowed_ports' => array_map('intval', explode(',', (string) env('MIKROTIK_ALLOWED_PORTS', '8728,8729'))),
    'allowed_hosts' => env('MIKROTIK_ALLOWED_HOSTS', ''),
    'tls_ca_file' => env('MIKROTIK_TLS_CA_FILE'),
    'rest_port' => (int) env('MIKROTIK_REST_PORT', 443),
    'rest_allowed_ports' => array_map(
        'intval',
        explode(',', (string) env('MIKROTIK_REST_ALLOWED_PORTS', env('MIKROTIK_REST_PORT', 443))),
    ),
];
