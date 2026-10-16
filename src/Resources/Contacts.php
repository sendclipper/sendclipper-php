<?php

declare(strict_types=1);

namespace SendClipper\Resources;

use SendClipper\HttpClient;
use SendClipper\Result;

final class Contacts extends Resource
{
    /**
     * @param array{audienceId?: string, status?: string, page?: int, pageSize?: int} $params
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function list(array $params = [], array $options = []): Result
    {
        return $this->http->call('GET', '/contacts', ['query' => $params], $options);
    }

    /**
     * Every contact, page after page. Throws `ApiException` on a refusal.
     *
     * @param array{audienceId?: string, status?: string, pageSize?: int} $params
     * @param array<string, mixed> $options
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = [], array $options = []): \Generator
    {
        $pageSize = (int) ($params['pageSize'] ?? 200);
        for ($page = 1; ; $page++) {
            /** @var array{items: list<array<string, mixed>>, totalPages: int} $res */
            $res = $this->list(['page' => $page, 'pageSize' => $pageSize] + $params, $options)->unwrap();
            yield from $res['items'];
            if ($page >= $res['totalPages'] || $res['items'] === []) {
                return;
            }
        }
    }

    /**
     * @param array{name: string, email: string, audienceId?: string, status?: string} $params
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function create(array $params, array $options = []): Result
    {
        return $this->http->call('POST', '/contacts', ['body' => $params], $options);
    }

    /**
     * Import a CSV or vCard file. Importing never suppresses anyone.
     *
     * @param array{file: string, filename?: string, audienceId?: string, newAudience?: string, createAudiences?: bool, onDuplicate?: 'skip'|'update', mapping?: array<string, mixed>} $params
     *        file: the file's CONTENTS (e.g. file_get_contents('contacts.csv')); filename's extension picks the parser
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function import(array $params, array $options = []): Result
    {
        return $this->http->call('POST', '/contacts/import', ['form' => self::importForm($params)], $options);
    }

    /**
     * What an import would do, without importing.
     *
     * @param array{file: string, filename?: string} $params
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function importPreview(array $params, array $options = []): Result
    {
        return $this->http->call('POST', '/contacts/import/preview', ['form' => self::importForm($params)], $options);
    }

    /**
     * Every contact as CSV (re-importable) or vCard 4.0 (`['format' => 'vcf']`).
     *
     * @param array{format?: 'csv'|'vcf', audience?: string} $params
     * @param array<string, mixed> $options
     * @return Result<string>
     */
    public function export(array $params = [], array $options = []): Result
    {
        return $this->http->call('GET', '/contacts/export', ['query' => $params, 'as' => 'text'], $options);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{string, string}
     */
    private static function importForm(array $params): array
    {
        $name = (string) ($params['filename'] ?? 'contacts.csv');
        $type = preg_match('/\.vcf$/i', $name) === 1 ? 'text/vcard' : 'text/csv';
        $fields = [];
        foreach (['audienceId', 'newAudience', 'onDuplicate'] as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $fields[] = [$key, (string) $params[$key]];
            }
        }
        if (isset($params['createAudiences'])) {
            $fields[] = ['createAudiences', $params['createAudiences'] ? 'true' : 'false'];
        }
        if (!empty($params['mapping'])) {
            $fields[] = ['mapping', json_encode($params['mapping'], JSON_THROW_ON_ERROR)];
        }
        return HttpClient::multipart($fields, [['file', $name, $type, (string) $params['file']]]);
    }
}
