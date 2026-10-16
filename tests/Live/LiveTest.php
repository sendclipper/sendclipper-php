<?php

declare(strict_types=1);

namespace SendClipper\Tests\Live;

use PHPUnit\Framework\TestCase;
use SendClipper\Result;
use SendClipper\SendClipper;
use SendClipper\Webhook;

/**
 * Live end-to-end: this package against a real SendClipper API, with an
 * `sc_live_` key minted for the run.
 *
 *     LIVE_API_URL=http://localhost:4000 composer test:live
 *
 * It refuses anything that is not localhost: it creates and deletes data and
 * sends mail through whatever transport the API has, which must be the local
 * SES emulator (checked on /v1/health first). Skipped when LIVE_API_URL is unset.
 */
final class LiveTest extends TestCase
{
    private static string $api = '';
    private static string $jwt = '';
    private static string $keyId = '';
    private static string $run = '';
    private static string $from = '';
    private static ?SendClipper $sc = null;
    /** @var list<callable(): mixed> */
    private array $cleanup = [];

    public static function setUpBeforeClass(): void
    {
        self::$api = (string) getenv('LIVE_API_URL');
        if (self::$api === '') {
            return;
        }
        self::assertContains(parse_url(self::$api, PHP_URL_HOST), ['localhost', '127.0.0.1'], 'the live test runs against a local API only');
        $backends = self::raw('GET', '/health')['backends'] ?? [];
        self::assertTrue(($backends['sesSend'] ?? '') === 'local' && ($backends['objectStore'] ?? '') === 'filesystem', 'refusing a non-local API: ' . json_encode($backends));

        self::$run = 'sdkphp' . base_convert((string) (int) (microtime(true) * 1000), 10, 36);
        $email = getenv('LIVE_EMAIL') ?: 'enis@sendclipper.dev';
        $password = getenv('LIVE_PASSWORD') ?: 'password';
        self::$jwt = (string) self::raw('POST', '/auth/login', ['email' => $email, 'password' => $password])['token'];
        $key = self::raw('POST', '/api-keys', ['name' => self::$run . '-full', 'permission' => 'full'], self::$jwt);
        self::$keyId = (string) $key['id'];
        self::$sc = new SendClipper((string) $key['token'], ['baseUrl' => self::$api]);
        $domains = self::ok(self::$sc->domains->list(), 'domains.list');
        foreach ($domains as $d) {
            if ($d['status'] === 'verified' && !str_ends_with($d['name'], '.example.com')) {
                self::$from = 'sdk-live@' . $d['name'];
                break;
            }
        }
        self::assertNotSame('', self::$from, 'the seeded workspace needs a verified domain');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$keyId !== '') {
            self::raw('DELETE', '/api-keys/' . self::$keyId, null, self::$jwt);
        }
    }

    protected function setUp(): void
    {
        if (self::$api === '') {
            self::markTestSkipped('LIVE_API_URL is not set');
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $fn) {
            $fn();
        }
    }

    private static function sc(): SendClipper
    {
        self::assertNotNull(self::$sc);
        return self::$sc;
    }

    /**
     * @param Result<mixed> $result
     */
    private static function ok(Result $result, string $what): mixed
    {
        self::assertNull($result->error, $what . ': ' . json_encode($result->error));
        return $result->data;
    }

    /** @return array<string, mixed> */
    private static function raw(string $method, string $path, mixed $body = null, string $token = ''): array
    {
        $ch = curl_init(self::$api . '/v1' . $path);
        self::assertNotFalse($ch);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_filter(['Content-Type: application/json', $token !== '' ? "Authorization: Bearer $token" : '']),
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $text = (string) curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        self::assertLessThan(300, $status, "$method $path -> $status $text");
        $data = json_decode($text, true);
        return is_array($data) ? $data : [];
    }

    public function testWorkspaceAndApiKeysReadBackWithTheMintedKey(): void
    {
        self::assertNotEmpty(self::ok(self::sc()->workspace->get(), 'workspace.get')['publicId']);
        $ids = array_column(self::ok(self::sc()->apiKeys->list(), 'apiKeys.list'), 'id');
        self::assertContains(self::$keyId, $ids);
    }

    public function testSendThenGetAndARepeatedIdempotencyKeyAnswersTheFirstSend(): void
    {
        $params = ['from' => self::$from, 'to' => 'ada+' . self::$run . '@lumen.io', 'subject' => 'SDK live ' . self::$run . ' Grüße', 'html' => '<p>Hello from PHP</p>', 'text' => 'Hello from PHP'];
        $opts = ['idempotencyKey' => self::$run . '-welcome'];
        $first = self::ok(self::sc()->emails->send($params, $opts), 'emails.send');
        self::assertSame($params['to'], $first['to']);
        $again = self::sc()->emails->send($params, $opts);
        self::assertSame($first['id'], self::ok($again, 'emails.send replay')['id']);
        self::assertSame('true', $again->headers['idempotency-replayed'] ?? null);

        $detail = self::ok(self::sc()->emails->get($first['id']), 'emails.get');
        self::assertSame($params['subject'], $detail['subject']);
        self::assertIsArray($detail['events']);
    }

    public function testAnApiRefusalComesBackAsErrorWithTheApiCodeNeverAThrow(): void
    {
        [$data, $error] = self::sc()->emails->send(['from' => 'nobody@not-a-domain-we-own.test', 'to' => 'a@lumen.io', 'subject' => 's', 'html' => 'h']);
        self::assertNull($data);
        self::assertSame(403, $error?->statusCode);
        self::assertNotSame('', $error->name);

        [, $invalid] = self::sc()->emails->send(['from' => self::$from, 'to' => 'not-an-address', 'subject' => 's', 'html' => 'h']);
        self::assertSame('validation_failed', $invalid?->name);
    }

    public function testBatchSendAcceptsAndRefusesItemsIndependently(): void
    {
        $res = self::ok(self::sc()->batch->send([
            ['from' => self::$from, 'to' => 'b1+' . self::$run . '@lumen.io', 'subject' => 'batch 1', 'html' => '<p>1</p>'],
            ['from' => self::$from, 'to' => 'b2+' . self::$run . '@lumen.io', 'templateId' => 'tpl_doesnotexist0'],
        ]), 'batch.send');
        self::assertSame(2, $res['summary']['submitted']);
        self::assertSame(['accepted', 'failed'], array_column($res['data'], 'status'));
    }

    public function testTemplatesCreatePreviewPublishSendByIdRemove(): void
    {
        $t = self::ok(self::sc()->templates->create([
            'name' => self::$run . ' welcome',
            'subject' => 'Welcome {{first_name|there}}',
            'blocks' => [['type' => 'text', 'text' => 'Hi {{first_name|there}}, welcome aboard.']],
        ]), 'templates.create');
        $this->cleanup[] = fn () => self::sc()->templates->remove($t['id']);
        self::assertSame('Welcome Ada', self::ok(self::sc()->templates->preview($t['id'], ['first_name' => 'Ada']), 'preview')['subject']);
        self::assertSame('Welcome there', self::ok(self::sc()->templates->preview($t['id']), 'preview with no variables')['subject']);
        self::ok(self::sc()->templates->publish($t['id']), 'publish');
        $sent = self::ok(self::sc()->emails->send(['from' => self::$from, 'to' => 't+' . self::$run . '@lumen.io', 'templateId' => $t['publicId'], 'variables' => ['first_name' => 'Ada']]), 'send(template)');
        self::assertSame('Welcome Ada', $sent['subject']);
        self::assertStringContainsString('Hi', self::ok(self::sc()->templates->html($t['id']), 'html'));
    }

    public function testSandboxesResetCredentialsSendWithTokenWaitForRaw(): void
    {
        $box = self::ok(self::sc()->sandboxes->create(['name' => self::$run . '-box']), 'sandboxes.create');
        $this->cleanup[] = fn () => self::sc()->sandboxes->remove($box['id']);
        $creds = self::ok(self::sc()->sandboxes->resetCredentials($box['id']), 'resetCredentials');
        self::assertStringStartsWith('sc_sbx_', $creds['apiToken']);

        $subject = 'trapped ' . self::$run;
        self::ok(self::sc()->sandboxes->send($creds['apiToken'], ['from' => 'app@example.com', 'to' => 'someone@example.com', 'subject' => $subject, 'html' => '<p>trap</p>']), 'sandboxes.send');
        $msg = self::ok(self::sc()->sandboxes->messages->waitFor($box['id'], fn (array $m) => $m['subject'] === $subject, 15, 0.3), 'waitFor');
        self::assertSame($subject, $msg['subject']);
        self::assertStringContainsString("Subject: $subject", self::ok(self::sc()->sandboxes->messages->raw($box['id'], $msg['id']), 'raw'));
    }

    public function testWebhooksCreateReturnsTheSecretOnceAndVerifyAcceptsIt(): void
    {
        $hook = self::ok(self::sc()->webhooks->create(['url' => 'http://127.0.0.1:9/sdk-live-php', 'events' => ['email.delivered', 'email.bounced'], 'allowInsecureHttp' => true]), 'webhooks.create');
        $this->cleanup[] = fn () => self::sc()->webhooks->remove($hook['id']);
        self::assertStringStartsWith('whsec_', $hook['secret']);
        $body = (string) json_encode(['id' => 'evt_live', 'event' => 'email.delivered', 'createdAt' => '2026-10-16T10:00:00Z', 'data' => []]);
        self::assertSame('evt_live', self::sc()->webhooks->verify($body, Webhook::signatureHeader($hook['secret'], $body), $hook['secret'])['id']);

        self::assertSame(['email.opened'], self::ok(self::sc()->webhooks->update($hook['id'], ['events' => ['email.opened']]), 'update')['events']);
        self::assertNotSame($hook['secret'], self::ok(self::sc()->webhooks->rotateSecret($hook['id']), 'rotateSecret')['secret']);
        self::assertIsArray(self::ok(self::sc()->webhooks->dispatches->list(['state' => 'all', 'limit' => 5]), 'dispatches.list')['data']);
    }

    public function testSuppressionsAddFindRemoveWithAReason(): void
    {
        $email = 'blocked+' . self::$run . '@lumen.io';
        $created = self::ok(self::sc()->suppressions->create(['email' => $email, 'note' => 'sdk live test']), 'suppressions.create');
        self::assertTrue($created['created']);
        $found = array_column(iterator_to_array(self::sc()->suppressions->iterate(['q' => $email]), false), 'email');
        self::assertContains($email, $found);
        self::ok(self::sc()->suppressions->remove($created['row']['id'], 'Removed by the PHP SDK live test run'), 'suppressions.remove');
    }

    public function testContactsImportPreviewMultipartAndIterators(): void
    {
        $c = self::ok(self::sc()->contacts->create(['name' => 'SDK Live', 'email' => 'contact+' . self::$run . '@lumen.io']), 'contacts.create');
        self::assertSame('contact+' . self::$run . '@lumen.io', $c['email']);
        self::assertNotEmpty(self::ok(self::sc()->contacts->importPreview(['file' => "name,email\nPHP Live,php+" . self::$run . "@lumen.io\n"]), 'importPreview'));
        $n = 0;
        foreach (self::sc()->emails->iterate(['search' => self::$run, 'pageSize' => 2]) as $e) {
            self::assertNotEmpty($e['id']);
            $n++;
        }
        self::assertGreaterThanOrEqual(3, $n, "expected this run's sends in the log, saw $n");
    }
}
