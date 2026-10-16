<?php

declare(strict_types=1);

namespace SendClipper\Resources;

use SendClipper\HttpClient;
use SendClipper\Result;

final class Batch extends Resource
{
    /**
     * Up to 500 messages in one request (one recipient each). Every item is
     * accepted or refused on its own; a refused item costs its siblings nothing.
     *
     * @param list<array<string, mixed>> $emails
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function send(array $emails, array $options = []): Result
    {
        return self::post($this->http, $emails, $options);
    }

    /**
     * @param array<array<string, mixed>> $emails
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     * @internal
     */
    public static function post(HttpClient $http, array $emails, array $options): Result
    {
        $items = array_map(static fn (array $e): array => self::body($e), array_values($emails));
        return $http->call('POST', '/emails/batch', ['body' => ['emails' => $items]], $options);
    }
}
