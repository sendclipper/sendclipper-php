<?php

declare(strict_types=1);

namespace SendClipper\Resources;

use SendClipper\Result;

final class Suppressions extends Resource
{
    /**
     * @param array{q?: string, reason?: string, cursor?: string, limit?: int} $params
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function list(array $params = [], array $options = []): Result
    {
        return $this->http->call('GET', '/suppressions', ['query' => $params], $options);
    }

    /**
     * @param array{q?: string, reason?: string, limit?: int} $params
     * @param array<string, mixed> $options
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = [], array $options = []): \Generator
    {
        yield from Cursor::walk(fn (array $q) => $this->list($q, $options), $params, 'data');
    }

    /**
     * Stop all mail to an address (reason `manual`). `created` is false when
     * the address was already suppressed; `row` is the entry either way.
     *
     * @param array{email: string, note?: string} $params
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function create(array $params, array $options = []): Result
    {
        return $this->http->call('POST', '/suppressions', ['body' => $params], $options);
    }

    /**
     * Lift a suppression. `$reason` (10+ characters) is recorded. Complaint and
     * provider suppressions cannot be removed (`suppression_not_removable`).
     *
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function remove(string $id, string $reason, array $options = []): Result
    {
        return $this->http->call('DELETE', '/suppressions/' . self::seg($id), ['body' => ['reason' => $reason]], $options);
    }

    /**
     * The list as CSV. `cursor` and `limit` are ignored: the export is the whole list.
     *
     * @param array<string, mixed> $params `q`, `reason`
     * @param array<string, mixed> $options
     * @return Result<string>
     */
    public function export(array $params = [], array $options = []): Result
    {
        unset($params['cursor'], $params['limit']);
        return $this->http->call('GET', '/suppressions/export', ['query' => $params, 'as' => 'text'], $options);
    }
}
