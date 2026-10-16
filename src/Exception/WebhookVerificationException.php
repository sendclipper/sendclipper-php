<?php

declare(strict_types=1);

namespace SendClipper\Exception;

/**
 * `Webhook::verify()` refused a delivery. `$reason` says which check failed:
 * `missing_header`, `malformed_header`, `timestamp_out_of_tolerance`,
 * `signature_mismatch` or `invalid_payload`.
 */
class WebhookVerificationException extends SendClipperException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
