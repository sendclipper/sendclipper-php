<?php

declare(strict_types=1);

namespace SendClipper\Resources;

use SendClipper\Result;

final class TemplateTags extends Resource
{
    /**
     * Every tag, each with its `templateCount`.
     *
     * @param array<string, mixed> $options
     * @return Result<list<array<string, mixed>>>
     */
    public function list(array $options = []): Result
    {
        return $this->http->call('GET', '/templates/tags', [], $options);
    }

    /**
     * @param array{name: string, color: string} $params color: gray, red, orange, amber, green, teal, blue, purple, pink
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function create(array $params, array $options = []): Result
    {
        return $this->http->call('POST', '/templates/tags', ['body' => $params], $options);
    }

    /**
     * @param array{name?: string, color?: string} $params
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function update(string $id, array $params, array $options = []): Result
    {
        return $this->http->call('PATCH', '/templates/tags/' . self::seg($id), ['body' => $params], $options);
    }

    /**
     * `['deleted' => true, 'templateCount' => n]`: the tag is taken off n templates.
     *
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function remove(string $id, array $options = []): Result
    {
        return $this->http->call('DELETE', '/templates/tags/' . self::seg($id), [], $options);
    }
}
