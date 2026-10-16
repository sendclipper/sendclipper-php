<?php

declare(strict_types=1);

namespace SendClipper\Resources;

use SendClipper\HttpClient;
use SendClipper\Result;

final class Templates extends Resource
{
    public readonly TemplateTags $tags;
    public readonly TemplateAssets $assets;

    public function __construct(HttpClient $http)
    {
        parent::__construct($http);
        $this->tags = new TemplateTags($http);
        $this->assets = new TemplateAssets($http);
    }

    /**
     * @param array<string, mixed> $params `tag` (a tag id), `project` (a domain id)
     * @param array<string, mixed> $options
     * @return Result<list<array<string, mixed>>>
     */
    public function list(array $params = [], array $options = []): Result
    {
        return $this->http->call('GET', '/templates', ['query' => $params], $options);
    }

    /**
     * By uuid or public id (`tpl_...`).
     *
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function get(string $id, array $options = []): Result
    {
        return $this->http->call('GET', '/templates/' . self::seg($id), [], $options);
    }

    /**
     * The rendered HTML, tags left in place.
     *
     * @param array<string, mixed> $options
     * @return Result<string>
     */
    public function html(string $id, array $options = []): Result
    {
        return $this->http->call('GET', '/templates/' . self::seg($id) . '/html', ['as' => 'text'], $options);
    }

    /**
     * A new draft. Publish it before sending with it.
     *
     * @param array<string, mixed> $params `name`, `subject`, `preheader`, `blocks`, `projectDomainId`, `key`
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function create(array $params = [], array $options = []): Result
    {
        return $this->http->call('POST', '/templates', ['body' => $params], $options);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function update(string $id, array $params, array $options = []): Result
    {
        return $this->http->call('PATCH', '/templates/' . self::seg($id), ['body' => $params], $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return Result<null>
     */
    public function remove(string $id, array $options = []): Result
    {
        return $this->http->call('DELETE', '/templates/' . self::seg($id), [], $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function duplicate(string $id, array $options = []): Result
    {
        return $this->http->call('POST', '/templates/' . self::seg($id) . '/duplicate', [], $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function publish(string $id, array $options = []): Result
    {
        return $this->http->call('POST', '/templates/' . self::seg($id) . '/publish', [], $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function unpublish(string $id, array $options = []): Result
    {
        return $this->http->call('POST', '/templates/' . self::seg($id) . '/unpublish', [], $options);
    }

    /**
     * Render with `$variables` without sending anything.
     *
     * @param array<string, string|int|float|bool> $variables
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function preview(string $id, array $variables = [], array $options = []): Result
    {
        return $this->http->call('POST', '/templates/' . self::seg($id) . '/preview', ['body' => self::body(['variables' => $variables])], $options);
    }

    /**
     * Replace the template's tags with these tag ids.
     *
     * @param array<string> $tagIds
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function setTags(string $id, array $tagIds, array $options = []): Result
    {
        return $this->http->call('PUT', '/templates/' . self::seg($id) . '/tags', ['body' => ['tagIds' => array_values($tagIds)]], $options);
    }

    /**
     * The projects (domains) templates can belong to.
     *
     * @param array<string, mixed> $options
     * @return Result<list<array<string, mixed>>>
     */
    public function projects(array $options = []): Result
    {
        return $this->http->call('GET', '/templates/projects', [], $options);
    }
}
