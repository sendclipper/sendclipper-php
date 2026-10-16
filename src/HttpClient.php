<?php

declare(strict_types=1);

namespace SendClipper;

use SendClipper\Exception\ApiException;
use SendClipper\Exception\ConnectionException;
use SendClipper\Transport\CurlTransport;
use SendClipper\Transport\Transport;
use SendClipper\Transport\TransportException;
use SendClipper\Transport\TransportRequest;
use SendClipper\Transport\TransportResponse;

/**
 * @internal Reach the API through `SendClipper`'s resources.
 */
final class HttpClient
{
    public const DEFAULT_BASE_URL = 'https://sendclipper.com';

    private const RETRYABLE_STATUS = [408, 429, 500, 502, 503, 504];
    private const MAX_RETRY_AFTER_SECONDS = 60.0;
    private const OPTION_KEYS = ['idempotencyKey', 'timeout', 'maxRetries', 'headers'];

    public readonly string $baseUrl;
    private readonly ?string $apiKey;
    private readonly float $timeout;
    private readonly int $maxRetries;
    /** @var array<string, string> */
    private readonly array $defaultHeaders;
    private readonly Transport $transport;
    /** @var callable(float): void */
    private $sleep;

    /**
     * @param array{baseUrl?: string, timeout?: float|int, maxRetries?: int, defaultHeaders?: array<string, string>, transport?: Transport, sleep?: callable(float): void} $options
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        $envKey = getenv('SENDCLIPPER_API_KEY');
        $this->apiKey = $apiKey ?? ($envKey !== false && $envKey !== '' ? $envKey : null);
        $envUrl = getenv('SENDCLIPPER_BASE_URL');
        $raw = $options['baseUrl'] ?? ($envUrl !== false && $envUrl !== '' ? $envUrl : self::DEFAULT_BASE_URL);
        $this->baseUrl = (string) preg_replace('#/v1$#', '', rtrim($raw, '/'));
        $this->timeout = (float) ($options['timeout'] ?? 30.0);
        $this->maxRetries = max(0, $options['maxRetries'] ?? 2);
        $this->defaultHeaders = $options['defaultHeaders'] ?? [];
        $this->transport = $options['transport'] ?? new CurlTransport();
        $this->sleep = $options['sleep'] ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) ($seconds * 1_000_000));
            }
        };
    }

    /**
     * The public face of every API method: never throws for an API refusal or
     * a network failure, it answers a `Result`.
     *
     * @param array{query?: array<string, mixed>, body?: mixed, form?: array{string, string}, as?: 'json'|'text'|'bytes', bearer?: string} $req
     * @param array<string, mixed> $options
     * @return Result<mixed>
     */
    public function call(string $method, string $path, array $req = [], array $options = []): Result
    {
        try {
            [$data, $headers] = $this->request($method, $path, $req, $options);
            return new Result($data, null, $headers);
        } catch (ApiException $e) {
            return Result::failure(
                new ErrorResponse($e->apiCode, $e->getMessage(), $e->status, $e->detail),
                $e->headers !== [] ? $e->headers : null,
            );
        } catch (ConnectionException $e) {
            return Result::failure(new ErrorResponse('network_error', $e->getMessage(), null));
        }
    }

    /**
     * Like `call()`, but throws `ApiException` / `ConnectionException`.
     *
     * @param array{query?: array<string, mixed>, body?: mixed, form?: array{string, string}, as?: 'json'|'text'|'bytes', bearer?: string} $req
     * @param array<string, mixed> $options
     * @return array{mixed, array<string, string>}
     */
    public function request(string $method, string $path, array $req = [], array $options = []): array
    {
        foreach (array_keys($options) as $key) {
            if (!in_array($key, self::OPTION_KEYS, true)) {
                throw new \InvalidArgumentException(
                    "Unknown request option \"$key\". Allowed: " . implode(', ', self::OPTION_KEYS) . '.',
                );
            }
        }

        $token = $req['bearer'] ?? $this->apiKey;
        if ($token === null || $token === '') {
            throw new ApiException(
                401,
                'api_key_missing',
                'No SendClipper API key. Pass one to new SendClipper("sc_live_...") or set SENDCLIPPER_API_KEY.',
            );
        }

        $url = $this->baseUrl . '/v1' . (str_starts_with($path, '/') ? $path : '/' . $path);
        $pairs = [];
        foreach ($req['query'] ?? [] as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode(self::queryValue($v));
        }
        if ($pairs !== []) {
            $url .= '?' . implode('&', $pairs);
        }

        $as = $req['as'] ?? 'json';
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => $as === 'json' ? 'application/json' : '*/*',
            'User-Agent' => 'sendclipper-php/' . Version::VERSION,
        ];
        foreach ($this->defaultHeaders as $k => $v) {
            $headers[$k] = $v;
        }
        /** @var array<string, string> $extra */
        $extra = $options['headers'] ?? [];
        foreach ($extra as $k => $v) {
            $headers[$k] = $v;
        }

        $body = null;
        if (isset($req['form'])) {
            [$body, $headers['Content-Type']] = $req['form'];
        } elseif (array_key_exists('body', $req)) {
            $headers['Content-Type'] = 'application/json';
            $body = self::encodeJson($req['body']);
        }

        $isRead = $method === 'GET' || $method === 'HEAD';
        $idempotencyKey = $options['idempotencyKey'] ?? null;
        // The API de-duplicates every JSON mutation on this header for 24 hours.
        // A multipart body is not fingerprinted server-side, so it gets no key
        // and is never retried.
        if (!$isRead && !isset($req['form'])) {
            $headers['Idempotency-Key'] = is_string($idempotencyKey) && $idempotencyKey !== '' ? $idempotencyKey : 'sc-php-' . self::uuid4();
        } elseif (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        $canRetry = $isRead || isset($headers['Idempotency-Key']);
        $retries = $canRetry ? max(0, (int) ($options['maxRetries'] ?? $this->maxRetries)) : 0;
        $timeout = (float) ($options['timeout'] ?? $this->timeout);

        for ($attempt = 0; ; $attempt++) {
            try {
                $res = $this->transport->send(new TransportRequest($method, $url, $headers, $body, $timeout));
            } catch (TransportException $e) {
                if ($attempt < $retries) {
                    ($this->sleep)(self::backoff($attempt));
                    continue;
                }
                $pathOnly = (string) parse_url($url, PHP_URL_PATH);
                throw new ConnectionException(
                    $e->timedOut
                        ? sprintf('SendClipper did not answer within %s s (%s %s).', self::num($timeout), $method, $pathOnly)
                        : sprintf('Could not reach SendClipper at %s: %s', $this->baseUrl, $e->getMessage()),
                    0,
                    $e,
                );
            }

            $resHeaders = array_change_key_case($res->headers, CASE_LOWER);
            $ok = $res->status >= 200 && $res->status < 300;

            if (!$ok && in_array($res->status, self::RETRYABLE_STATUS, true) && $attempt < $retries) {
                ($this->sleep)(self::retryAfter($resHeaders) ?? self::backoff($attempt));
                continue;
            }

            if (!$ok) {
                $err = self::toApiError($res->status, $res->body, $resHeaders);
                // Our own earlier attempt is still running server-side (a timeout
                // that was not a failure). Its result is replayed once it finishes.
                if ($err->apiCode === 'idempotency_key_in_flight' && $attempt < $retries) {
                    ($this->sleep)(self::retryAfter($resHeaders) ?? self::backoff($attempt));
                    continue;
                }
                throw $err;
            }

            return [self::parse($res, $resHeaders, $as), $resHeaders];
        }
    }

    /**
     * The API answers refusals in two shapes: the house envelope
     * `{ error: { code, message, detail? } }` and Nest's default
     * `{ statusCode, message: string | string[], error }` (request validation).
     *
     * @param array<string, string> $headers
     */
    public static function toApiError(int $status, string $text, array $headers = []): ApiException
    {
        $parsed = json_decode($text, true);
        $obj = is_array($parsed) ? $parsed : [];

        $env = $obj['error'] ?? null;
        if (is_array($env)) {
            return new ApiException(
                $status,
                self::str($env['code'] ?? null) ?? self::codeForStatus($status),
                self::str($env['message'] ?? null) ?? "HTTP $status",
                $env['detail'] ?? $env['details'] ?? null,
                $headers,
            );
        }
        $msg = $obj['message'] ?? null;
        if (is_array($msg) && isset($msg['error']) && is_array($msg['error'])) {
            $inner = $msg['error'];
            return new ApiException(
                $status,
                self::str($inner['code'] ?? null) ?? self::codeForStatus($status),
                self::str($inner['message'] ?? null) ?? "HTTP $status",
                $inner['detail'] ?? null,
                $headers,
            );
        }
        if (is_array($msg) && array_is_list($msg)) {
            return new ApiException($status, 'validation_failed', implode('; ', array_map('strval', $msg)), $msg, $headers);
        }
        if (is_string($msg)) {
            return new ApiException($status, self::codeForStatus($status), $msg, null, $headers);
        }
        $snippet = mb_substr(trim($text), 0, 300);
        return new ApiException($status, self::codeForStatus($status), $snippet !== '' ? $snippet : "HTTP $status", null, $headers);
    }

    /** @param array<string, string> $headers */
    private static function parse(TransportResponse $res, array $headers, string $as): mixed
    {
        if ($as === 'bytes') {
            return new DownloadedFile($res->body, $headers['content-type'] ?? null, self::filenameOf($headers['content-disposition'] ?? null));
        }
        if ($as === 'text') {
            return $res->body;
        }
        if ($res->body === '') {
            return null;
        }
        $decoded = json_decode($res->body, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $res->body;
    }

    private static function encodeJson(mixed $body): string
    {
        // A request body is always a JSON object: PHP's empty array would
        // otherwise travel as `[]`.
        if ($body === []) {
            return '{}';
        }
        return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function queryValue(mixed $v): string
    {
        // The API reads booleans the way JavaScript writes them.
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_scalar($v)) {
            return (string) $v;
        }
        throw new \InvalidArgumentException('Query values must be strings, numbers or booleans.');
    }

    public static function backoff(int $attempt): float
    {
        $base = min(8.0, 0.5 * (2 ** $attempt));
        return $base / 2 + (mt_rand() / mt_getrandmax()) * ($base / 2);
    }

    /** @param array<string, string> $headers */
    private static function retryAfter(array $headers): ?float
    {
        $raw = $headers['retry-after'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_numeric($raw) && (float) $raw >= 0) {
            return min(self::MAX_RETRY_AFTER_SECONDS, (float) $raw);
        }
        $at = strtotime($raw);
        if ($at === false) {
            return null;
        }
        return min(self::MAX_RETRY_AFTER_SECONDS, max(0.0, (float) ($at - time())));
    }

    private static function filenameOf(?string $disposition): ?string
    {
        if ($disposition === null || $disposition === '') {
            return null;
        }
        if (preg_match("/filename\\*\\s*=\\s*(?:UTF-8'')?([^;]+)/i", $disposition, $m) === 1) {
            $decoded = rawurldecode(trim($m[1], " \t\""));
            if (mb_check_encoding($decoded, 'UTF-8')) {
                return $decoded;
            }
        }
        if (preg_match('/filename\s*=\s*"?([^";]+)"?/i', $disposition, $m) === 1) {
            return trim($m[1]);
        }
        return null;
    }

    private static function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthorized',
            403 => 'forbidden',
            404 => 'not_found',
            409 => 'conflict',
            413 => 'payload_too_large',
            429 => 'rate_limited',
            default => "http_$status",
        };
    }

    private static function str(mixed $v): ?string
    {
        return is_scalar($v) && (string) $v !== '' ? (string) $v : null;
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(sprintf('%.3f', $n), '0'), '.');
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** Percent-encode one path segment. */
    public static function seg(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * `multipart/form-data`.
     *
     * @param array<array{string, string}> $fields (name, value)
     * @param array<array{string, string, string, string}> $files (name, filename, content type, bytes)
     * @return array{string, string} body and content type
     */
    public static function multipart(array $fields, array $files): array
    {
        $boundary = '----sendclipper' . bin2hex(random_bytes(12));
        $q = static fn (string $s): string => str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', ' ', ' '], $s);
        $out = '';
        foreach ($fields as [$name, $value]) {
            $out .= "--$boundary\r\nContent-Disposition: form-data; name=\"{$q($name)}\"\r\n\r\n$value\r\n";
        }
        foreach ($files as [$name, $filename, $type, $bytes]) {
            $out .= "--$boundary\r\nContent-Disposition: form-data; name=\"{$q($name)}\"; filename=\"{$q($filename)}\"\r\n"
                . "Content-Type: $type\r\n\r\n$bytes\r\n";
        }
        $out .= "--$boundary--\r\n";
        return [$out, "multipart/form-data; boundary=$boundary"];
    }
}
