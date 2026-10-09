<?php

/**
 * MikroTik Router Configuration
 *
 * Each entry in the 'routers' array represents one physical MikroTik router
 * that the NOC console monitors via the RouterOS API.
 *
 * Two API protocols are supported:
 *
 *   1. Binary API (default) — TCP port 8728 (plain) or 8729 (TLS)
 *      This is the native RouterOS API protocol. It uses a custom binary
 *      wire format with length-prefixed words and sentences.
 *      Enable on the router: /ip/service/set api disabled=no
 *      For TLS: /ip/service/set api-ssl disabled=no
 *
 *   2. REST API (fallback) — HTTPS port 443
 *      HTTP GET/POST to https://<router>/rest/<path>
 *      Enable on the router: /ip/service/set www-ssl disabled=no
 *
 * Set 'api_protocol' per router or globally:
 *   'auto'   — Try binary API first, fall back to REST (recommended)
 *   'binary' — Use binary API only
 *   'rest'   — Use REST API only
 *
 * Requirements on each router:
 *   1. Enable the API service:
 *      /ip/service/set api disabled=no
 *      For TLS (recommended): /ip/service/set api-ssl disabled=no
 *      For REST fallback: /ip/service/set www-ssl disabled=no
 *
 *   2. Create a read-only user:
 *      /user/group/add name=api-read policy=read,api,rest-api
 *      /user/add name=monitoring group=api-read password="your-password"
 *
 *   3. Restrict access to the Laravel server's IP:
 *      /user/set monitoring address=<laravel-server-ip>/32
 *      /ip/service/set api address=<laravel-server-ip>/32
 *      /ip/service/set api-ssl address=<laravel-server-ip>/32
 *
 * Security: Store api_password in .env, never commit real passwords.
 */

return [

    'routers' => [],

    /**
     * Default API protocol for all routers.
     * Override per-router with the 'api_protocol' key.
     */
    'api_protocol' => env('MIKROTIK_API_PROTOCOL', 'auto'),

    /**
     * The provider must query real RouterOS devices configured in Laravel.
     */
    'provider' => env('ROUTER_PROVIDER', 'mikrotik'),

    /**
     * API timeout in seconds (applies to both binary and REST).
     */
    'timeout' => env('MIKROTIK_TIMEOUT', 10),
];
