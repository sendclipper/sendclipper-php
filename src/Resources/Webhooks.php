<?php

declare(strict_types=1);

namespace SendClipper\Resources;

use SendClipper\HttpClient;
use SendClipper\Result;
use SendClipper\Webhook;

final class Webhooks extends Resource
{
    public readonly WebhookDispatches $dispatches;

    public function __construct(HttpClient $http)
    {
        parent::__construct($http);
        $this->dispatches = new WebhookDispatches($http);
    }

    /**
     * @param array<string, mixed> $options
     * @return Result<list<array<string, mixed>>>
     */
    public function list(array $options = []): Result
    {
        return $this->http->call('GET', '/webhook-endpoints', [], $options);
    }

    /**
     * The response carries the signing `secret` once. Store it.
     *
     * @param array{url: string, events: list<string>, allowInsecureHttp?: bool} $params
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function create(array $params, array $options = []): Result
    {
        return $this->http->call('POST', '/webhook-endpoints', ['body' => $params], $options);
    }

    /**
     * @param array{url?: string, events?: list<string>, active?: bool, allowInsecureHttp?: bool} $params
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function update(string $id, array $params, array $options = []): Result
    {
        return $this->http->call('PATCH', '/webhook-endpoints/' . self::seg($id), ['body' => $params], $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return Result<null>
     */
    public function remove(string $id, array $options = []): Result
    {
        return $this->http->call('DELETE', '/webhook-endpoints/' . self::seg($id), [], $options);
    }

    /**
     * A new secret. The old one keeps signing alongside it for the grace window.
     *
     * @param array<string, mixed> $options
     * @return Result<array<string, mixed>>
     */
    public function rotateSecret(string $id, array $options = []): Result
    {
        return $this->http->call('POST', '/webhook-endpoints/' . self::seg($id) . '/rotate-secret', [], $options);
    }

    /**
     * Recent delivery attempts.
     *
     * @param array{endpointId?: string, finalOnly?: bool, limit?: int} $params
     * @param array<string, mixed> $options
     * @return Result<list<array<string, mixed>>>
     */
    public function deliveries(array $params = [], array $options = []): Result
    {
        return $this->http->call('GET', '/webhook-deliveries', ['query' => $params], $options);
    }

    /**
     * Verify a delivery's signature and return the parsed event. Makes no request.
     * Same as `Webhook::verify()`.
     *
     * @return array<string, mixed>
     * @throws \SendClipper\Exception\WebhookVerificationException
     */
    public function verify(mixed $payload, mixed $headers, string $secret, int $toleranceSeconds = Webhook::TOLERANCE_SECONDS, ?int $now = null): array
    {
        return Webhook::verify($payload, $headers, $secret, $toleranceSeconds, $now);
    }
}
