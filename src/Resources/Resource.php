<?php

declare(strict_types=1);

namespace SendClipper\Resources;

use SendClipper\HttpClient;

/**
 * Every method takes request options as its last argument:
 * `['idempotencyKey' => ..., 'timeout' => seconds, 'maxRetries' => n, 'headers' => [...]]`.
 */
abstract class Resource
{
    public function __construct(protected readonly HttpClient $http)
    {
    }

    protected static function seg(string $value): string
    {
        return HttpClient::seg($value);
    }

    /**
     * Drop null values and turn an empty `variables` map into a JSON object.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected static function body(array $params): array
    {
        $out = array_filter($params, static fn ($v) => $v !== null);
        if (array_key_exists('variables', $out) && $out['variables'] === []) {
            $out['variables'] = new \stdClass();
        }
        return $out;
    }
}
