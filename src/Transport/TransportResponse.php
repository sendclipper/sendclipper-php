<?php

declare(strict_types=1);

namespace SendClipper\Transport;

final class TransportResponse
{
    /**
     * @param array<string, string> $headers lower-case names
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }
}
