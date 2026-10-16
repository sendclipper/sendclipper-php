<?php

declare(strict_types=1);

namespace SendClipper;

/** What an API refusal or a network failure looks like. */
final class ErrorResponse
{
    public function __construct(
        /** SendClipper's machine-readable code: `domain_not_verified`, `validation_failed`, `rate_limited`, `network_error`, ... */
        public readonly string $name,
        /** A sentence meant for a person. */
        public readonly string $message,
        /** The HTTP status, or null when no answer arrived (network, timeout). */
        public readonly ?int $statusCode,
        /** Extra, code-specific facts (e.g. the missing template tags). */
        public readonly mixed $detail = null,
    ) {
    }
}
