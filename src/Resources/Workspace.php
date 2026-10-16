<?php

declare(strict_types=1);

namespace SendClipper\Resources;

use SendClipper\Result;

final class Workspace extends Resource
{
    /**
     * The workspace this key belongs to.
     *
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function get(array $options = []): Result
    {
        return $this->http->call('GET', '/workspace', [], $options);
    }
}
