<?php

declare(strict_types=1);

namespace SendClipper\Resources;

use SendClipper\Result;

final class Emails extends Resource
{
    /**
     * Send one message. Returns once SendClipper accepted it (status `queued`);
     * delivery is reported later through `emails->get()` and webhooks.
     *
     * Every call carries an `Idempotency-Key`, so the SDK's own retries never
     * send twice. Pass `['idempotencyKey' => ...]` to make YOUR retries safe too.
     *
     * @param array<string, mixed> $params `from`, `to`, `subject`, `html`, `text`, or `templateId`/`template` + `variables`, ...
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function send(array $params, array $options = []): Result
    {
        return $this->http->call('POST', '/emails', ['body' => self::body($params)], $options);
    }

    /**
     * Same as `batch->send()`.
     *
     * @param list<array<string, mixed>> $emails
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function sendBatch(array $emails, array $options = []): Result
    {
        return Batch::post($this->http, $emails, $options);
    }

    /**
     * One message with its body and event timeline.
     *
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function get(string $id, array $options = []): Result
    {
        return $this->http->call('GET', '/emails/' . self::seg($id), [], $options);
    }

    /**
     * One page of the sent-mail log, newest first.
     *
     * @param array<string, mixed> $params `search`, `status`, `domain`, `broadcastId`, `from`, `to`, `page`, `pageSize`
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function list(array $params = [], array $options = []): Result
    {
        return $this->http->call('GET', '/emails', ['query' => $params], $options);
    }

    /**
     * Every message matching `$params`, page after page. Throws `ApiException`
     * on a refusal, since a loop has no error to return.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = [], array $options = []): \Generator
    {
        $pageSize = (int) ($params['pageSize'] ?? 100);
        for ($page = 1; ; $page++) {
            /** @var array{items: list<array<string, mixed>>, total: int} $res */
            $res = $this->list(['page' => $page, 'pageSize' => $pageSize] + $params, $options)->unwrap();
            yield from $res['items'];
            if (count($res['items']) < $pageSize || $page * $pageSize >= $res['total']) {
                return;
            }
        }
    }

    /**
     * Counts per status.
     *
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function stats(array $options = []): Result
    {
        return $this->http->call('GET', '/emails/stats/summary', [], $options);
    }
}
