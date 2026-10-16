<?php

declare(strict_types=1);

namespace SendClipper\Resources;

use SendClipper\Result;

final class TemplateAssets extends Resource
{
    /**
     * @param array<string, mixed> $options
     * @return Result<list<array<string, mixed>>>
     */
    public function list(array $options = []): Result
    {
        return $this->http->call('GET', '/templates/assets', [], $options);
    }

    /**
     * Upload an image a template can show and get its public URL.
     * Pass the file's bytes as `data` (base64-encoded for you), or an
     * already-encoded string as `base64`.
     *
     * @param array{mimeType: string, data?: string, base64?: string, name?: string} $params
     *        mimeType: image/png, image/jpeg, image/gif or image/webp
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function upload(array $params, array $options = []): Result
    {
        if (!isset($params['data']) && !isset($params['base64'])) {
            throw new \InvalidArgumentException('templates->assets->upload() needs `data` (the bytes) or `base64`.');
        }
        $body = [
            'mimeType' => $params['mimeType'],
            'base64' => $params['base64'] ?? base64_encode($params['data'] ?? ''),
        ];
        if (isset($params['name']) && $params['name'] !== '') {
            $body['name'] = $params['name'];
        }
        return $this->http->call('POST', '/templates/assets', ['body' => $body], $options);
    }
}
