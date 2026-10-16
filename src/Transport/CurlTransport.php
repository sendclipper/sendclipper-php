<?php

declare(strict_types=1);

namespace SendClipper\Transport;

/** The default transport: ext-curl, no dependencies. Redirects are not followed. */
final class CurlTransport implements Transport
{
    public function send(TransportRequest $request): TransportResponse
    {
        $method = $request->method;
        if ($method === '') {
            throw new TransportException('No HTTP method.');
        }
        $ch = curl_init($request->url);
        if ($ch === false) {
            throw new TransportException('curl_init failed');
        }
        $headerLines = [];
        foreach ($request->headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        // An empty Expect stops curl from waiting for "100 Continue" on large bodies.
        $headerLines[] = 'Expect:';

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => (int) ceil($request->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil(min($request->timeout, 10.0) * 1000),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    $responseHeaders[$name] = isset($responseHeaders[$name]) ? $responseHeaders[$name] . ', ' . $value : $value;
                } elseif (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = []; // a new response (e.g. after 100 Continue)
                }
                return strlen($line);
            },
        ]);
        if ($request->body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $request->body);
        }

        $body = curl_exec($ch);
        if ($body === false || !is_string($body)) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            throw new TransportException($error !== '' ? $error : "curl error $errno", $errno === CURLE_OPERATION_TIMEDOUT);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return new TransportResponse($status, $responseHeaders, $body);
    }
}
