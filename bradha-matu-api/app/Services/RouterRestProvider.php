<?php

namespace App\Services;

/**
 * RouterProvider implementation for the server-selected HTTPS REST transport.
 *
 * The response mapping is shared with MikroTikRouterProvider so both
 * transports retain the same application contract.
 */
class RouterRestProvider extends MikroTikRouterProvider implements RouterProvider {}
