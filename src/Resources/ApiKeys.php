<?php

declare(strict_types=1);

namespace SendClipper\Resources;

use SendClipper\Result;

final class ApiKeys extends Resource
{
    /**
     * The workspace's keys, masked. Keys are created and revoked in the dashboard only.
     *
     * @param array<string, mixed> $options
     * @return Result<list<array<string, mixed>>>
     */
    public function list(array $options = []): Result
    {
        return $this->http->call('GET', '/api-keys', [], $options);
    }
}
