<?php

declare(strict_types=1);

namespace SendClipper\Resources;

use SendClipper\Result;

final class WebhookDispatches extends Resource
{
    /**
     * Undelivered events: dead letters by default, or `['state' => 'retrying']`.
     *
     * @param array{state?: string, endpointId?: string, cursor?: string, limit?: int} $params
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function list(array $params = [], array $options = []): Result
    {
        return $this->http->call('GET', '/webhook-dispatches', ['query' => $params], $options);
    }

    /**
     * @param array{state?: string, endpointId?: string, limit?: int} $params
     * @param array<string, mixed> $options
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = [], array $options = []): \Generator
    {
        yield from Cursor::walk(fn (array $q) => $this->list($q, $options), $params, 'data');
    }

    /**
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function get(string $id, array $options = []): Result
    {
        return $this->http->call('GET', '/webhook-dispatches/' . self::seg($id), [], $options);
    }

    /**
     * One more immediate attempt for a dead-lettered event.
     *
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function replay(string $id, array $options = []): Result
    {
        return $this->http->call('POST', '/webhook-dispatches/' . self::seg($id) . '/replay', [], $options);
    }

    /**
     * Replay every dead letter (optionally of one endpoint), up to the server's cap.
     *
     * @param array{state?: string, endpointId?: string} $params
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function replayAll(array $params = [], array $options = []): Result
    {
        return $this->http->call('POST', '/webhook-dispatches/replay-all', ['body' => $params], $options);
    }
}
