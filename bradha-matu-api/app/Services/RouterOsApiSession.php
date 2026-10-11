<?php

namespace App\Services;

interface RouterOsApiSession
{
    /**
     * Execute a single command from the provider's fixed read-only allowlist.
     *
     * @return array<int, array<string, string>>
     */
    public function print(string $command, array $args = [], array $queries = []): array;
}
