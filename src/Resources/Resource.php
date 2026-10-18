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

    /**
     * The body of a send (`emails->send()`, each `batch->send()` item,
     * `sandboxes->send()`): `body()` plus the one attachment rule.
     *
     * Each attachment's `content` is the file's RAW bytes (for example
     * `file_get_contents('invoice.pdf')`) and is always base64-encoded here.
     * A caller who already holds base64 passes it as `contentBase64` instead;
     * it is sent as `content` exactly as given. Exactly one of the two.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected static function sendBody(array $params): array
    {
        $out = self::body($params);
        if (isset($out['attachments']) && is_array($out['attachments'])) {
            $files = [];
            foreach (array_values($out['attachments']) as $i => $a) {
                $files[] = is_array($a) ? self::attachment($a, $i) : $a;
            }
            $out['attachments'] = $files;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private static function attachment(array $a, int $index): array
    {
        $hasRaw = isset($a['content']);
        $hasB64 = isset($a['contentBase64']);
        if ($hasRaw === $hasB64) {
            throw new \InvalidArgumentException(
                "attachments[{$index}] needs exactly one of `content` (the file's raw bytes) or `contentBase64` (already base64).",
            );
        }
        $content = $hasRaw ? $a['content'] : $a['contentBase64'];
        if (!is_string($content)) {
            throw new \InvalidArgumentException("attachments[{$index}]: `" . ($hasRaw ? 'content' : 'contentBase64') . '` must be a string.');
        }
        $out = ['filename' => $a['filename'] ?? null, 'content' => $hasRaw ? base64_encode($content) : $content];
        if (isset($a['contentType'])) {
            $out['contentType'] = $a['contentType'];
        }
        return array_filter($out, static fn ($v) => $v !== null);
    }
}
