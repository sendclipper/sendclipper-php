<?php

declare(strict_types=1);

namespace SendClipper;

use SendClipper\Exception\WebhookVerificationException;

/**
 * Verify SendClipper webhook deliveries.
 *
 * SendClipper signs `"{timestamp}.{rawBody}"` with HMAC-SHA256 and sends
 * `X-SendClipper-Signature-V2: t=<seconds>,v1=<hex>[,v1=<hex>]`. During a
 * secret rotation there are two `v1=` values; any match is accepted.
 */
final class Webhook
{
    /** SendClipper's documented tolerance: five minutes. */
    public const TOLERANCE_SECONDS = 300;

    public const SIGNATURE_HEADER = 'x-sendclipper-signature-v2';

    /**
     * Verify a delivery and return its parsed event.
     *
     * @param mixed $payload a string: the body EXACTLY as received: `$request->getContent()` in Laravel/Symfony,
     *                        `file_get_contents('php://input')` in plain PHP. Never re-encoded JSON.
     * @param mixed $headers the request's headers (`getallheaders()`, `$_SERVER`, Laravel/Symfony
     *                       `$request->headers`, a PSR-7 request), or just the signature header's value
     * @return array<string, mixed>
     * @throws WebhookVerificationException when any check fails
     */
    public static function verify(
        mixed $payload,
        mixed $headers,
        string $secret,
        int $toleranceSeconds = self::TOLERANCE_SECONDS,
        ?int $now = null,
    ): array {
        if ($secret === '') {
            throw new WebhookVerificationException('missing_header', 'No webhook signing secret was given.');
        }
        if (!is_string($payload)) {
            throw new WebhookVerificationException(
                'invalid_payload',
                'payload must be the raw request body as a string, not decoded JSON. '
                . 'Use $request->getContent() in Laravel or Symfony, or file_get_contents(\'php://input\').',
            );
        }

        $header = self::headerOf($headers);
        if ($header === null || $header === '') {
            throw new WebhookVerificationException('missing_header', 'The request carries no X-SendClipper-Signature-V2 header.');
        }
        [$timestamp, $signatures] = self::parseSignatureHeader($header);
        if ($timestamp === null || $signatures === []) {
            throw new WebhookVerificationException('malformed_header', 'X-SendClipper-Signature-V2 has no t= or no v1= value.');
        }

        $current = $now ?? time();
        $drift = abs($current - $timestamp);
        if ($drift > $toleranceSeconds) {
            throw new WebhookVerificationException(
                'timestamp_out_of_tolerance',
                "The delivery was signed $drift s from now, outside the $toleranceSeconds s tolerance.",
            );
        }

        $expected = self::sign($secret, $timestamp, $payload);
        // ANY match, and every candidate is compared: a rotation sends two.
        $matched = false;
        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                $matched = true;
            }
        }
        if (!$matched) {
            throw new WebhookVerificationException('signature_mismatch', 'The signature does not match this secret and body.');
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            throw new WebhookVerificationException('invalid_payload', 'The signed body is not a JSON object.');
        }
        /** @var array<string, mixed> $event */
        return $event;
    }

    /** `hex(HMAC-SHA256(secret, "{timestamp}.{rawBody}"))`. Exported for tests of your own handler. */
    public static function sign(string $secret, int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }

    /** Build a valid header for a body, e.g. to test your endpoint locally. */
    public static function signatureHeader(string $secret, string $rawBody, ?int $timestamp = null): string
    {
        $t = $timestamp ?? time();
        return "t=$t,v1=" . self::sign($secret, $t, $rawBody);
    }

    /**
     * @return array{?int, list<string>} the timestamp and the signatures
     */
    public static function parseSignatureHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $eq = strpos($part, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($part, 0, $eq));
            $value = trim(substr($part, $eq + 1));
            if ($key === 't' && preg_match('/^[0-9]+$/', $value) === 1) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }
        return [$timestamp, $signatures];
    }

    private static function headerOf(mixed $source): ?string
    {
        if (is_string($source)) {
            return $source;
        }
        if (is_object($source)) {
            if (method_exists($source, 'getHeaderLine')) { // PSR-7
                $line = $source->getHeaderLine(self::SIGNATURE_HEADER);
                return is_string($line) && $line !== '' ? $line : null;
            }
            if (method_exists($source, 'get')) { // Symfony / Laravel HeaderBag
                $value = $source->get(self::SIGNATURE_HEADER);
                return is_string($value) ? $value : null;
            }
        }
        if (!is_iterable($source)) {
            return null;
        }
        $found = [];
        foreach ($source as $name => $value) {
            // $_SERVER spells it HTTP_X_SENDCLIPPER_SIGNATURE_V2.
            $normalised = str_replace('_', '-', strtolower((string) $name));
            if ($normalised === self::SIGNATURE_HEADER || $normalised === 'http-' . self::SIGNATURE_HEADER) {
                foreach (is_array($value) ? $value : [$value] as $v) {
                    if (is_scalar($v)) {
                        $found[] = (string) $v;
                    }
                }
            }
        }
        return $found === [] ? null : implode(',', $found);
    }
}
