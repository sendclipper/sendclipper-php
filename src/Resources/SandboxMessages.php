<?php

declare(strict_types=1);

namespace SendClipper\Resources;

use SendClipper\ErrorResponse;
use SendClipper\Result;

final class SandboxMessages extends Resource
{
    /**
     * @param array<string, mixed> $params `search`, `limit`
     * @param array<string, mixed> $options
     * @return Result<list<array<string, mixed>>>
     */
    public function list(string $sandboxId, array $params = [], array $options = []): Result
    {
        return $this->http->call('GET', '/sandboxes/' . self::seg($sandboxId) . '/messages', ['query' => $params], $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function get(string $sandboxId, string $messageId, array $options = []): Result
    {
        return $this->http->call('GET', '/sandboxes/' . self::seg($sandboxId) . '/messages/' . self::seg($messageId), [], $options);
    }

    /**
     * The message's raw MIME source.
     *
     * @param array<string, mixed> $options
     * @return Result<string>
     */
    public function raw(string $sandboxId, string $messageId, array $options = []): Result
    {
        return $this->http->call(
            'GET',
            '/sandboxes/' . self::seg($sandboxId) . '/messages/' . self::seg($messageId) . '/raw',
            ['as' => 'text'],
            $options,
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return Result<null>
     */
    public function remove(string $sandboxId, string $messageId, array $options = []): Result
    {
        return $this->http->call('DELETE', '/sandboxes/' . self::seg($sandboxId) . '/messages/' . self::seg($messageId), [], $options);
    }

    /**
     * Empty the sandbox. `['deleted' => n]`.
     *
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function clear(string $sandboxId, array $options = []): Result
    {
        return $this->http->call('DELETE', '/sandboxes/' . self::seg($sandboxId) . '/messages', [], $options);
    }

    /**
     * `['updated' => n]`.
     *
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function markAllRead(string $sandboxId, array $options = []): Result
    {
        return $this->http->call('POST', '/sandboxes/' . self::seg($sandboxId) . '/read-all', [], $options);
    }

    /**
     * Poll until a message arrives that `$match` accepts (default: any message
     * trapped after this call started). Handy in end-to-end tests. `$timeout`
     * and `$interval` are seconds; on timeout `error->name` is `timeout`.
     *
     * @param (callable(array<string, mixed>): bool)|null $match
     * @return Result<array<string, mixed>>
     */
    public function waitFor(string $sandboxId, ?callable $match = null, float $timeout = 30.0, float $interval = 1.0): Result
    {
        $started = microtime(true);
        $deadline = $started + $timeout;
        $match ??= static function (array $m) use ($started): bool {
            $at = strtotime((string) ($m['trappedAt'] ?? ''));
            return $at !== false && $at >= $started - 1;
        };
        for (;;) {
            $page = $this->list($sandboxId, ['limit' => 50]);
            if ($page->error !== null) {
                return Result::failure($page->error, $page->headers);
            }
            foreach ($page->data as $m) {
                if ($match($m)) {
                    return $this->get($sandboxId, (string) $m['id']);
                }
            }
            if (microtime(true) >= $deadline) {
                return Result::failure(
                    new ErrorResponse('timeout', sprintf('No matching message reached sandbox %s within %s s.', $sandboxId, $timeout), null),
                );
            }
            if ($interval > 0) {
                usleep((int) ($interval * 1_000_000));
            }
        }
    }
}
