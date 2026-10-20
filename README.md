# SendClipper PHP SDK

The official PHP library for the [SendClipper](https://sendclipper.com) email API.

```bash
composer require sendclipper/sendclipper-php
```

PHP 8.1 or newer, with the `curl` and `json` extensions. No other dependencies.

## Setup

Create an API key in the dashboard under **API keys**.

```php
use SendClipper\SendClipper;

$sc = new SendClipper('sc_live_xxxxxxxxxxxxxxxx');
// or: new SendClipper()  reads SENDCLIPPER_API_KEY
```

## Send an email

```php
[$data, $error] = $sc->emails->send([
    'from' => 'hello@send.acme.io',
    'to' => 'ada@lumen.io',
    'subject' => 'Your receipt',
    'html' => '<p>Thanks for your order.</p>',
    'text' => 'Thanks for your order.',
]);

if ($error) {
    echo $error->name, ': ', $error->message; // e.g. domain_not_verified
} else {
    echo "Email {$data['id']} accepted";
}
```

Parameters and responses are plain arrays whose keys are the API's own field names (`templateId`, `sendClass`, ...), exactly as the [API reference](https://sendclipper.com/docs) writes them.

`from` must be a bare address on a domain you verified in SendClipper. Each message has one recipient in `to`; for several, use `batch->send`.

## Reply-To and attachments

```php
[$data, $error] = $sc->emails->send([
    'from' => 'bookings@acme.io',
    'to' => 'ada@lumen.io',
    'subject' => 'Your booking',
    'html' => '<p>Your confirmation is attached.</p>',
    'replyTo' => 'support@acme.io',
    'attachments' => [
        ['filename' => 'booking-42.pdf', 'content' => file_get_contents('booking-42.pdf')],
    ],
]);
```

`replyTo` is one address or a list of up to 10. `content` is the file's raw bytes, and the SDK base64-encodes it for you; if you already hold a base64 string, pass it as `contentBase64` instead and it is sent as given (one or the other, never both). `contentType` is guessed from the filename when you leave it out. Limits: 10 MiB per file, 25 MiB of files per message, and your sending provider's own. Attachments are not accepted on a batch item; send those emails on their own. `sandboxes->send()` takes the same two fields.

## Send with a template

Design and publish a template in the dashboard, then send it by id or by its send name:

```php
$sc->emails->send([
    'from' => 'hello@send.acme.io',
    'to' => 'ada@lumen.io',
    'templateId' => 'tpl_9f2b7c10ad42', // or 'template' => 'welcome'
    'variables' => ['first_name' => 'Ada'],
]);
```

A tag with no value and no `{{tag|fallback}}` is refused by name (`template_variables_missing`), never sent blank.

## Send a batch

Up to 500 messages in one request. Each item is accepted or refused on its own:

```php
[$data] = $sc->batch->send([
    ['from' => 'hello@send.acme.io', 'to' => 'ada@lumen.io', 'subject' => 'Hi Ada', 'html' => '<p>Hi</p>'],
    ['from' => 'hello@send.acme.io', 'to' => 'alan@lumen.io', 'templateId' => 'tpl_9f2b7c10ad42', 'variables' => ['first_name' => 'Alan']],
]);

foreach ($data['data'] as $item) {
    if ($item['status'] === 'failed') {
        echo $item['index'], ' ', $item['error']['code'], PHP_EOL;
    }
}
```

## Errors

API methods never throw for an API refusal or a network failure. They return a `SendClipper\Result` with `data`, `error` and `headers`, and exactly one of `data` and `error` is set. A `Result` destructures to `[$data, $error]`:

```php
$result = $sc->domains->list();
$result->data;     // the decoded response, or null
$result->error;    // a SendClipper\ErrorResponse, or null
$result->headers;  // response headers (lower-case names), or null when no answer arrived
$result->ok();     // error === null

$domains = $sc->domains->list()->unwrap(); // data, or throws SendClipper\Exception\ApiException
```

`ErrorResponse` has `name` (SendClipper's code: `validation_failed`, `rate_limited`, `network_error`, ...), `message` (a sentence for a person), `statusCode` (`null` when no answer arrived) and `detail` (code-specific facts).

The iterators (`emails->iterate()` and friends) throw an `ApiException` instead, since a loop has no `error` to hand back. `$e->apiCode` is the API's code and `$e->status` the HTTP status.

## Retries and idempotency

Network errors, `429` and `5xx` answers are retried twice with backoff, honouring `Retry-After`. Every JSON write carries an `Idempotency-Key`, and the API answers a repeated key with the first result for 24 hours. A retried send therefore never goes out twice.

To make your own retries safe as well (a queued job that runs again after a crash), pass a key that names the logical operation:

```php
$sc->emails->send($params, ['idempotencyKey' => "order-{$order->id}-receipt"]);
```

## Request options

Every method takes request options as its last argument:

```php
$sc->emails->list(['status' => 'bounced'], ['timeout' => 5, 'headers' => ['X-Trace-Id' => $traceId]]);
```

| Option | Meaning |
|---|---|
| `idempotencyKey` | Your own key for a write |
| `timeout` | Per-attempt timeout in seconds (default 30) |
| `maxRetries` | Retries for this call (default 2) |
| `headers` | Extra request headers |

Client options: `new SendClipper($key, ['baseUrl' => ..., 'timeout' => ..., 'maxRetries' => ..., 'defaultHeaders' => [...], 'transport' => ...])`. `baseUrl` also reads `SENDCLIPPER_BASE_URL`. `transport` replaces the curl sender (a proxy, a test double): implement `SendClipper\Transport\Transport`.

## Verify webhooks

SendClipper signs each delivery. Verify it against the **raw** body, before decoding:

```php
use SendClipper\Exception\WebhookVerificationException;
use SendClipper\Webhook;

try {
    $event = Webhook::verify(
        file_get_contents('php://input'), // the raw body, not json_decode()d
        getallheaders(),
        (string) getenv('SENDCLIPPER_WEBHOOK_SECRET'),
    );
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    exit($e->reason);
}
// $event['id'] is stable across retries: deduplicate on it
error_log($event['event'] . ' ' . $event['data']['to']);
http_response_code(200);
```

In Laravel or Symfony pass `$request->getContent()` and `$request->headers`; a PSR-7 request works as the headers argument too. During a secret rotation a delivery carries two signatures and either one is accepted. A delivery signed more than five minutes away from now is refused. `$sc->webhooks->verify(...)` is the same function.

To test your handler locally, `Webhook::signatureHeader($secret, $rawBody)` builds a valid header.

## Sandboxes

A sandbox traps mail while you develop: nothing is delivered.

```php
$box = $sc->sandboxes->create(['name' => 'my-app-dev'])->unwrap();
$creds = $sc->sandboxes->credentials($box['id'])->unwrap(); // SMTP host, port, username, password

// Or send through the sandbox's JSON door with its own token, using the same fields as emails->send():
$token = $sc->sandboxes->resetCredentials($box['id'])->unwrap();
$sc->sandboxes->send($token['apiToken'], ['from' => 'app@example.com', 'to' => 'test@example.com', 'subject' => 'Hi', 'html' => '<p>Hi</p>']);

// In a test: wait for the message to arrive
[$msg, $error] = $sc->sandboxes->messages->waitFor($box['id'], fn (array $m) => $m['subject'] === 'Hi');
```

## Everything else

| Resource | Methods |
|---|---|
| `emails` | `send`, `get`, `list`, `iterate`, `stats` |
| `batch` | `send` |
| `domains` | `list`, `create`, `records`, `verify`, `enableReceiving`, `disableReceiving`, `setRegion`, `deletionPreview`, `remove` |
| `templates` | `list`, `get`, `html`, `create`, `update`, `remove`, `duplicate`, `publish`, `unpublish`, `preview`, `setTags`, `projects` |
| `templates->tags` | `list`, `create`, `update`, `remove` |
| `templates->assets` | `list`, `upload` |
| `sandboxes` | `list`, `get`, `create`, `update`, `remove`, `credentials`, `resetCredentials`, `send` |
| `sandboxes->messages` | `list`, `get`, `raw`, `remove`, `clear`, `markAllRead`, `waitFor` |
| `webhooks` | `list`, `create`, `update`, `remove`, `rotateSecret`, `deliveries`, `verify` |
| `webhooks->dispatches` | `list`, `iterate`, `get`, `replay`, `replayAll` |
| `contacts` | `list`, `iterate`, `create`, `import`, `importPreview`, `export` |
| `audiences` | `list` |
| `suppressions` | `list`, `iterate`, `create`, `remove`, `export` |
| `mailboxes` | `list`, `get`, `create`, `update`, `archive` |
| `mailboxes->messages` | `list`, `iterate`, `get`, `attachment` |
| `automations` | `enroll` |
| `workspace` | `get` |
| `apiKeys` | `list` |

`contacts->import()` takes the file's contents (`file_get_contents('contacts.csv')`), and `templates->assets->upload()` the image's bytes. Management routes need a key with **Full access**. A sending-only key can call `emails->send` and `batch->send`.

Full API reference: <https://sendclipper.com/docs>.

## Other languages

SendClipper has an official SDK for each of these languages, all calling the same API with the same conventions.

| Language | Package | Registry | Repository |
|---|---|---|---|
| Node.js | `@sendclipper/node` | [npm](https://www.npmjs.com/package/@sendclipper/node) | [sendclipper/sendclipper-node](https://github.com/sendclipper/sendclipper-node) |
| Python | `sendclipper` | [PyPI](https://pypi.org/project/sendclipper/) | [sendclipper/sendclipper-python](https://github.com/sendclipper/sendclipper-python) |
| PHP | `sendclipper/sendclipper-php` | [Packagist](https://packagist.org/packages/sendclipper/sendclipper-php) | this library |
| Go | `github.com/sendclipper/sendclipper-go` | [pkg.go.dev](https://pkg.go.dev/github.com/sendclipper/sendclipper-go) | [sendclipper/sendclipper-go](https://github.com/sendclipper/sendclipper-go) |
| Ruby | `sendclipper` | [RubyGems](https://rubygems.org/gems/sendclipper) | [sendclipper/sendclipper-ruby](https://github.com/sendclipper/sendclipper-ruby) |
| Rust | `sendclipper` | [crates.io](https://crates.io/crates/sendclipper) | [sendclipper/sendclipper-rust](https://github.com/sendclipper/sendclipper-rust) |
| Java | `com.sendclipper:sendclipper-java` | [Maven Central](https://central.sonatype.com/artifact/com.sendclipper/sendclipper-java) | [sendclipper/sendclipper-java](https://github.com/sendclipper/sendclipper-java) |

Documentation for all of them: <https://sendclipper.com/docs>.

## License

MIT
