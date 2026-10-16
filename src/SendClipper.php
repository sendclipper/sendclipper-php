<?php

declare(strict_types=1);

namespace SendClipper;

use SendClipper\Resources\ApiKeys;
use SendClipper\Resources\Audiences;
use SendClipper\Resources\Automations;
use SendClipper\Resources\Batch;
use SendClipper\Resources\Contacts;
use SendClipper\Resources\Domains;
use SendClipper\Resources\Emails;
use SendClipper\Resources\Mailboxes;
use SendClipper\Resources\Sandboxes;
use SendClipper\Resources\Suppressions;
use SendClipper\Resources\Templates;
use SendClipper\Resources\Webhooks;
use SendClipper\Resources\Workspace;
use SendClipper\Transport\Transport;

/**
 * The SendClipper API client.
 *
 *     $sc = new SendClipper\SendClipper('sc_live_...'); // or new SendClipper(): reads SENDCLIPPER_API_KEY
 *     [$data, $error] = $sc->emails->send([
 *         'from' => 'hello@acme.io',
 *         'to' => 'ada@lumen.io',
 *         'subject' => 'Hi',
 *         'html' => '<p>Hi</p>',
 *     ]);
 */
final class SendClipper
{
    public readonly Emails $emails;
    public readonly Batch $batch;
    public readonly Domains $domains;
    public readonly Templates $templates;
    public readonly Sandboxes $sandboxes;
    public readonly Webhooks $webhooks;
    public readonly Contacts $contacts;
    public readonly Audiences $audiences;
    public readonly Suppressions $suppressions;
    public readonly Mailboxes $mailboxes;
    public readonly Automations $automations;
    public readonly Workspace $workspace;
    public readonly ApiKeys $apiKeys;

    /** The API origin requests go to, without `/v1`. */
    public readonly string $baseUrl;

    /**
     * @param string|null $apiKey a `sc_live_...` key; defaults to SENDCLIPPER_API_KEY
     * @param array{baseUrl?: string, timeout?: float|int, maxRetries?: int, defaultHeaders?: array<string, string>, transport?: Transport, sleep?: callable(float): void} $options
     *        baseUrl defaults to SENDCLIPPER_BASE_URL, then https://sendclipper.com (no /v1);
     *        timeout is per attempt, in seconds (default 30); maxRetries defaults to 2;
     *        transport replaces the ext-curl sender (a proxy, a test double)
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        $http = new HttpClient($apiKey, $options);
        $this->baseUrl = $http->baseUrl;
        $this->emails = new Emails($http);
        $this->batch = new Batch($http);
        $this->domains = new Domains($http);
        $this->templates = new Templates($http);
        $this->sandboxes = new Sandboxes($http);
        $this->webhooks = new Webhooks($http);
        $this->contacts = new Contacts($http);
        $this->audiences = new Audiences($http);
        $this->suppressions = new Suppressions($http);
        $this->mailboxes = new Mailboxes($http);
        $this->automations = new Automations($http);
        $this->workspace = new Workspace($http);
        $this->apiKeys = new ApiKeys($http);
    }
}
