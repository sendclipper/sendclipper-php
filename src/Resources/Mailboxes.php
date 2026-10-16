<?php

declare(strict_types=1);

namespace SendClipper\Resources;

use SendClipper\HttpClient;
use SendClipper\Result;

final class Mailboxes extends Resource
{
    public readonly MailboxMessages $messages;

    public function __construct(HttpClient $http)
    {
        parent::__construct($http);
        $this->messages = new MailboxMessages($http);
    }

    /**
     * `['includeArchived' => true]` lists archived addresses too.
     *
     * @param array{includeArchived?: bool} $params
     * @param array<string, mixed> $options
     * @return Result<list<array<string, mixed>>>
     */
    public function list(array $params = [], array $options = []): Result
    {
        return $this->http->call('GET', '/mailboxes', ['query' => $params], $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function get(string $id, array $options = []): Result
    {
        return $this->http->call('GET', '/mailboxes/' . self::seg($id), [], $options);
    }

    /**
     * A new address on a receiving domain.
     *
     * @param array{domainId: string, localPart: string, displayName?: string, color?: string, catchAll?: bool} $params
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function create(array $params, array $options = []): Result
    {
        return $this->http->call('POST', '/mailboxes', ['body' => $params], $options);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function update(string $id, array $params, array $options = []): Result
    {
        return $this->http->call('PATCH', '/mailboxes/' . self::seg($id), ['body' => $params], $options);
    }

    /**
     * Archives the address. Its mail is kept.
     *
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function archive(string $id, array $options = []): Result
    {
        return $this->http->call('DELETE', '/mailboxes/' . self::seg($id), [], $options);
    }
}
