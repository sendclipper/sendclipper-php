<?php

declare(strict_types=1);

namespace SendClipper\Exception;

/**
 * The API answered with a non-2xx status. `$code` (the API's machine-readable
 * reason, e.g. `domain_not_verified`) is the field to branch on; the message
 * is a sentence meant for a person. The HTTP status is `$status`.
 */
class ApiException extends SendClipperException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $apiCode,
        string $message,
        public readonly mixed $detail = null,
        public readonly array $headers = [],
    ) {
        parent::__construct($message, $status);
    }
}
