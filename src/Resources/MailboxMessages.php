<?php

declare(strict_types=1);

namespace SendClipper\Resources;

use SendClipper\DownloadedFile;
use SendClipper\Result;

final class MailboxMessages extends Resource
{
    /**
     * Mail filed under one address. Reading never marks anything read.
     *
     * @param array{folder?: string, search?: string, label?: string, limit?: int, cursor?: string} $params
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function list(string $mailboxId, array $params = [], array $options = []): Result
    {
        return $this->http->call('GET', '/mailboxes/' . self::seg($mailboxId) . '/messages', ['query' => $params], $options);
    }

    /**
     * @param array{folder?: string, search?: string, label?: string, limit?: int} $params
     * @param array<string, mixed> $options
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(string $mailboxId, array $params = [], array $options = []): \Generator
    {
        yield from Cursor::walk(fn (array $q) => $this->list($mailboxId, $q, $options), $params, 'items');
    }

    /**
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function get(string $mailboxId, string $messageId, array $options = []): Result
    {
        return $this->http->call('GET', '/mailboxes/' . self::seg($mailboxId) . '/messages/' . self::seg($messageId), [], $options);
    }

    /**
     * Download one attachment: `content` (bytes), `contentType` and `filename`.
     *
     * @param array<string, mixed> $options
     * @return Result<DownloadedFile>
     */
    public function attachment(string $mailboxId, string $messageId, string $attachmentId, array $options = []): Result
    {
        return $this->http->call(
            'GET',
            '/mailboxes/' . self::seg($mailboxId) . '/messages/' . self::seg($messageId) . '/parts/' . self::seg($attachmentId),
            ['query' => ['download' => 1], 'as' => 'bytes'],
            $options,
        );
    }
}
