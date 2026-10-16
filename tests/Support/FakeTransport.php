<?php

declare(strict_types=1);

namespace SendClipper\Tests\Support;

use SendClipper\SendClipper;
use SendClipper\Transport\Transport;
use SendClipper\Transport\TransportRequest;
use SendClipper\Transport\TransportResponse;

/** Records each call and answers from a queue. */
final class FakeTransport implements Transport
{
    /** @var list<TransportRequest> */
    public array $calls = [];
    /** @var list<float> */
    public array $slept = [];

    /** @param list<TransportResponse|\Throwable|callable(TransportRequest): TransportResponse> $replies */
    public function __construct(private array $replies = [])
    {
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $this->calls[] = $request;
        if ($this->replies === []) {
            throw new \LogicException("unexpected request {$request->method} {$request->url}");
        }
        $next = array_shift($this->replies);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next instanceof TransportResponse ? $next : $next($request);
    }

    /**
     * @param list<TransportResponse|\Throwable|callable(TransportRequest): TransportResponse> $replies
     * @param array<string, mixed> $options
     * @return array{SendClipper, FakeTransport}
     */
    public static function client(array $replies, array $options = []): array
    {
        $t = new self($replies);
        $sc = new SendClipper($options['apiKey'] ?? 'sc_live_test', $options + [
            'baseUrl' => 'https://api.test',
            'transport' => $t,
            'sleep' => static function (float $s) use ($t): void {
                $t->slept[] = $s;
            },
        ]);
        return [$sc, $t];
    }

    /** @param array<string, string> $headers */
    public static function json(int $status, mixed $body, array $headers = []): TransportResponse
    {
        return new TransportResponse(
            $status,
            ['content-type' => 'application/json'] + array_change_key_case($headers, CASE_LOWER),
            $body === null ? '' : (string) json_encode($body),
        );
    }

    public static function path(TransportRequest $r): string
    {
        return (string) parse_url($r->url, PHP_URL_PATH);
    }

    /** @return array<string, string> */
    public static function query(TransportRequest $r): array
    {
        parse_str((string) parse_url($r->url, PHP_URL_QUERY), $q);
        /** @var array<string, string> $q */
        return $q;
    }

    public static function body(TransportRequest $r): mixed
    {
        return $r->body === null ? null : json_decode($r->body, true);
    }
}
