<?php

declare(strict_types=1);

namespace SendClipper\Resources;

use SendClipper\Result;

final class Audiences extends Resource
{
    /**
     * `['audiences' => [...], 'totalContacts' => n]`.
     *
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function list(array $options = []): Result
    {
        return $this->http->call('GET', '/audiences', [], $options);
    }
}
