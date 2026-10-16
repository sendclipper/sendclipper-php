<?php

declare(strict_types=1);

namespace SendClipper\Resources;

use SendClipper\HttpClient;
use SendClipper\Result;

final class Sandboxes extends Resource
{
    public readonly SandboxMessages $messages;

    public function __construct(HttpClient $http)
    {
        parent::__construct($http);
        $this->messages = new SandboxMessages($http);
    }

    /**
     * @param array<string, mixed> $options
     * @return Result<list<array<string, mixed>>>
     */
    public function list(array $options = []): Result
    {
        return $this->http->call('GET', '/sandboxes', [], $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function get(string $id, array $options = []): Result
    {
        return $this->http->call('GET', '/sandboxes/' . self::seg($id), [], $options);
    }

    /**
     * @param array{name: string, projectLabel?: string, color?: string} $params
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function create(array $params, array $options = []): Result
    {
        return $this->http->call('POST', '/sandboxes', ['body' => $params], $options);
    }

    /**
     * Renaming never changes the address or the credentials.
     *
     * @param array{name?: string, projectLabel?: string, color?: string} $params
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function update(string $id, array $params, array $options = []): Result
    {
        return $this->http->call('PATCH', '/sandboxes/' . self::seg($id), ['body' => $params], $options);
    }

    /**
     * Deletes the sandbox and every message in it.
     *
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function remove(string $id, array $options = []): Result
    {
        return $this->http->call('DELETE', '/sandboxes/' . self::seg($id), [], $options);
    }

    /**
     * SMTP host, port, username and password to point a project at.
     *
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function credentials(string $id, array $options = []): Result
    {
        return $this->http->call('GET', '/sandboxes/' . self::seg($id) . '/credentials', [], $options);
    }

    /**
     * Replace BOTH the SMTP password and the `sc_sbx_` token. The token is shown only here.
     *
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function resetCredentials(string $id, array $options = []): Result
    {
        return $this->http->call('POST', '/sandboxes/' . self::seg($id) . '/credentials', [], $options);
    }

    /**
     * Send to a sandbox through its JSON door, with the sandbox's own
     * `sc_sbx_...` token. Same fields as `emails->send()`; nothing is delivered.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function send(string $sandboxToken, array $params, array $options = []): Result
    {
        return $this->http->call('POST', '/sandbox/emails', ['body' => self::body($params), 'bearer' => $sandboxToken], $options);
    }
}
