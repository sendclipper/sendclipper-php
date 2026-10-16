<?php

declare(strict_types=1);

namespace SendClipper\Resources;

use SendClipper\Result;

/** @internal Walks a `{ <items>, page: { cursor, hasMore } }` list. */
final class Cursor
{
    /**
     * @param callable(array<string, mixed>): Result<mixed> $fetch
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public static function walk(callable $fetch, array $params, string $itemsKey): \Generator
    {
        unset($params['cursor']);
        for (;;) {
            /** @var array<string, mixed> $res */
            $res = $fetch($params)->unwrap();
            /** @var list<array<string, mixed>> $items */
            $items = $res[$itemsKey] ?? [];
            yield from $items;
            /** @var array{cursor?: ?string, hasMore?: bool} $page */
            $page = $res['page'] ?? [];
            if (empty($page['hasMore']) || empty($page['cursor'])) {
                return;
            }
            $params['cursor'] = $page['cursor'];
        }
    }
}
