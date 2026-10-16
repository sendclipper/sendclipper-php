<?php

declare(strict_types=1);

namespace SendClipper\Resources;

use SendClipper\Result;

final class Domains extends Resource
{
    /**
     * @param array<string, mixed> $options
     * @return Result<list<array<string, mixed>>>
     */
    public function list(array $options = []): Result
    {
        return $this->http->call('GET', '/domains', [], $options);
    }

    /**
     * Register a sending domain. Next: `records()`, publish them, then `verify()`.
     *
     * @param array{name: string, region: string} $params region: `us-east-1`, `eu-west-1` or `us-west-2`
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function create(array $params, array $options = []): Result
    {
        return $this->http->call('POST', '/domains', ['body' => $params], $options);
    }

    /**
     * The DNS records to publish, each with its current status.
     *
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function records(string $id, array $options = []): Result
    {
        return $this->http->call('GET', '/domains/' . self::seg($id) . '/records', [], $options);
    }

    /**
     * Check DNS now instead of waiting for the background check.
     *
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function verify(string $id, array $options = []): Result
    {
        return $this->http->call('POST', '/domains/' . self::seg($id) . '/verify', [], $options);
    }

    /**
     * Start receiving mail on this domain. Adds receiving records; never blocks sending.
     *
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function enableReceiving(string $id, array $options = []): Result
    {
        return $this->http->call('POST', '/domains/' . self::seg($id) . '/receiving', [], $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function disableReceiving(string $id, array $options = []): Result
    {
        return $this->http->call('DELETE', '/domains/' . self::seg($id) . '/receiving', [], $options);
    }

    /**
     * Move a domain to another region. Refused (`region_immutable`) once it holds messages.
     *
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function setRegion(string $id, string $region, array $options = []): Result
    {
        return $this->http->call('PATCH', '/domains/' . self::seg($id) . '/region', ['body' => ['region' => $region]], $options);
    }

    /**
     * What deleting the domain would take with it.
     *
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function deletionPreview(string $id, array $options = []): Result
    {
        return $this->http->call('GET', '/domains/' . self::seg($id) . '/deletion', [], $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return Result<null>
     */
    public function remove(string $id, array $options = []): Result
    {
        return $this->http->call('DELETE', '/domains/' . self::seg($id), [], $options);
    }
}
