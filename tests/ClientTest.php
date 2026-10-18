<?php

declare(strict_types=1);

namespace SendClipper\Tests;

use PHPUnit\Framework\TestCase;
use SendClipper\DownloadedFile;
use SendClipper\Exception\ApiException;
use SendClipper\HttpClient;
use SendClipper\Result;
use SendClipper\SendClipper;
use SendClipper\Tests\Support\FakeTransport as F;
use SendClipper\Transport\TransportException;
use SendClipper\Transport\TransportResponse;
use SendClipper\Version;

final class ClientTest extends TestCase
{
    private const SENT = [
        'id' => '0b6c0000-0000-4000-8000-000000000001',
        'messageId' => 'msg_9f2b7c10ad',
        'sesMessageId' => null,
        'from' => 'hello@acme.io',
        'to' => 'ada@lumen.io',
        'domain' => 'acme.io',
        'subject' => 'Hi',
        'status' => 'queued',
        'createdAt' => '2026-10-16T10:00:00.000Z',
        'templateId' => null,
    ];

    private const MSG = ['from' => 'a@acme.io', 'to' => 'b@lumen.io', 'subject' => 's', 'html' => 'h'];

    protected function tearDown(): void
    {
        putenv('SENDCLIPPER_API_KEY');
        putenv('SENDCLIPPER_BASE_URL');
    }

    public function testSendPostsToV1EmailsWithTheKeyAndAnIdempotencyKey(): void
    {
        [$sc, $t] = F::client([F::json(201, self::SENT)]);
        $result = $sc->emails->send(['from' => 'hello@acme.io', 'to' => 'ada@lumen.io', 'subject' => 'Hi', 'html' => '<p>Hi</p>']);

        self::assertNull($result->error);
        self::assertTrue($result->ok());
        self::assertSame(self::SENT, $result->data);
        self::assertSame('application/json', $result->headers['content-type'] ?? null);
        self::assertCount(1, $t->calls);
        $call = $t->calls[0];
        self::assertSame('POST', $call->method);
        self::assertSame('https://api.test/v1/emails', $call->url);
        self::assertSame('Bearer sc_live_test', $call->headers['Authorization']);
        self::assertSame('application/json', $call->headers['Content-Type']);
        self::assertSame('sendclipper-php/' . Version::VERSION, $call->headers['User-Agent']);
        self::assertMatchesRegularExpression('/^sc-php-[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $call->headers['Idempotency-Key']);
        self::assertSame(['from' => 'hello@acme.io', 'to' => 'ada@lumen.io', 'subject' => 'Hi', 'html' => '<p>Hi</p>'], F::body($call));
    }

    public function testAResultDestructuresToDataAndError(): void
    {
        [$sc] = F::client([F::json(201, self::SENT)]);
        [$data, $error] = $sc->emails->send(self::MSG);
        self::assertSame(self::SENT['id'], $data['id']);
        self::assertNull($error);
    }

    public function testATemplateSendCarriesTemplateIdAndVariablesAndACallerKeyIsUsedAsGiven(): void
    {
        [$sc, $t] = F::client([F::json(201, self::SENT)]);
        $sc->emails->send(
            ['from' => 'hello@acme.io', 'to' => 'ada@lumen.io', 'templateId' => 'tpl_9f2b7c10ad42', 'variables' => ['first_name' => 'Ada']],
            ['idempotencyKey' => 'order-42-receipt'],
        );
        self::assertSame('order-42-receipt', $t->calls[0]->headers['Idempotency-Key']);
        self::assertSame(
            ['from' => 'hello@acme.io', 'to' => 'ada@lumen.io', 'templateId' => 'tpl_9f2b7c10ad42', 'variables' => ['first_name' => 'Ada']],
            F::body($t->calls[0]),
        );
    }

    public function testEmptyVariablesAndEmptyBodiesTravelAsJsonObjects(): void
    {
        [$sc, $t] = F::client([F::json(201, self::SENT), F::json(201, []), F::json(200, [])]);
        $sc->emails->send(['from' => 'a@acme.io', 'to' => 'b@lumen.io', 'templateId' => 'tpl_x', 'variables' => []]);
        $sc->templates->create();
        $sc->templates->preview('t1');
        self::assertStringContainsString('"variables":{}', (string) $t->calls[0]->body);
        self::assertSame('{}', $t->calls[1]->body);
        self::assertSame('{"variables":{}}', $t->calls[2]->body);
    }

    public function testNonAsciiAndSlashesAreSentVerbatim(): void
    {
        [$sc, $t] = F::client([F::json(201, self::SENT)]);
        $sc->emails->send(self::MSG + ['text' => 'Grüße, Përshëndetje </p>']);
        self::assertStringContainsString('Grüße, Përshëndetje </p>', (string) $t->calls[0]->body);
    }

    public function testA503IsRetriedWithTheSameIdempotencyKey(): void
    {
        [$sc, $t] = F::client([F::json(503, ['error' => ['code' => 'unavailable', 'message' => 'down']]), F::json(201, self::SENT)]);
        [$data] = $sc->emails->send(self::MSG);
        self::assertSame(self::SENT['id'], $data['id']);
        self::assertCount(2, $t->calls);
        self::assertSame($t->calls[0]->headers['Idempotency-Key'], $t->calls[1]->headers['Idempotency-Key']);
    }

    public function testANetworkErrorIsRetriedThenAnswersNetworkErrorWithNoStatus(): void
    {
        [$sc, $t] = F::client([new TransportException('refused'), new TransportException('refused')], ['maxRetries' => 1]);
        [$data, $error] = $sc->emails->get('x');
        self::assertNull($data);
        self::assertSame('network_error', $error?->name);
        self::assertNull($error->statusCode);
        self::assertStringContainsString('Could not reach SendClipper at https://api.test', $error->message);
        self::assertCount(2, $t->calls);
    }

    public function testATimeoutNamesTheLimit(): void
    {
        [$sc, $t] = F::client([new TransportException('timed out', true)], ['timeout' => 2.5, 'maxRetries' => 0]);
        [, $error] = $sc->workspace->get();
        self::assertSame('network_error', $error?->name);
        self::assertStringContainsString('within 2.5 s', $error->message);
        self::assertSame(2.5, $t->calls[0]->timeout);
    }

    public function testAPerCallTimeoutOverridesTheClient(): void
    {
        [$sc, $t] = F::client([F::json(200, [])]);
        $sc->domains->list(['timeout' => 4]);
        self::assertSame(4.0, $t->calls[0]->timeout);
    }

    public function testAnUnknownRequestOptionIsRefusedLoudly(): void
    {
        [$sc] = F::client([]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown request option "idempotency_key"');
        $sc->emails->send(self::MSG, ['idempotency_key' => 'x']);
    }

    public function test429HonoursRetryAfter(): void
    {
        [$sc, $t] = F::client([F::json(429, ['error' => ['code' => 'rate_limited', 'message' => 'slow down']], ['Retry-After' => '3']), F::json(200, [])]);
        self::assertSame([], $sc->domains->list()->data);
        self::assertCount(2, $t->calls);
        self::assertSame([3.0], $t->slept);
    }

    public function testRetryAfterIsCappedAtAMinute(): void
    {
        [$sc, $t] = F::client([F::json(503, null, ['Retry-After' => '86400']), F::json(200, [])]);
        $sc->domains->list();
        self::assertSame([60.0], $t->slept);
    }

    public function testBackoffGrowsAndStaysBounded(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $b = HttpClient::backoff($attempt);
            $base = min(8.0, 0.5 * (2 ** $attempt));
            self::assertGreaterThanOrEqual($base / 2, $b);
            self::assertLessThanOrEqual($base, $b);
        }
    }

    public function testIdempotencyKeyInFlightIsRetriedAndAnyOther409IsNot(): void
    {
        $inFlight = F::json(409, ['error' => ['code' => 'idempotency_key_in_flight', 'message' => 'wait']], ['Retry-After' => '0']);
        [$sc, $t] = F::client([$inFlight, F::json(201, self::SENT)]);
        $sc->emails->send(self::MSG);
        self::assertCount(2, $t->calls);

        [$sc, $t] = F::client([F::json(409, ['error' => ['code' => 'sandbox_name_taken', 'message' => 'taken']])]);
        [, $error] = $sc->sandboxes->create(['name' => 'x']);
        self::assertSame(['sandbox_name_taken', 'taken', 409, null], [$error?->name, $error?->message, $error?->statusCode, $error?->detail]);
        self::assertCount(1, $t->calls);
    }

    public function testA4xxIsNotRetriedAndTheEnvelopeBecomesATypedError(): void
    {
        [$sc, $t] = F::client([F::json(400, ['error' => ['code' => 'template_variables_missing', 'message' => 'Missing first_name', 'detail' => ['missing' => ['first_name']]]])]);
        [$data, $error] = $sc->emails->send(['from' => 'a@acme.io', 'to' => 'b@lumen.io', 'templateId' => 'tpl_x']);
        self::assertNull($data);
        self::assertSame('template_variables_missing', $error?->name);
        self::assertSame('Missing first_name', $error->message);
        self::assertSame(400, $error->statusCode);
        self::assertSame(['missing' => ['first_name']], $error->detail);
        self::assertCount(1, $t->calls);
    }

    public function testAValidationErrorInNestsDefaultShapeBecomesValidationFailed(): void
    {
        [$sc] = F::client([F::json(400, ['statusCode' => 400, 'message' => ['to must be a valid email address'], 'error' => 'Bad Request'])]);
        [, $error] = $sc->emails->send(['to' => 'nope'] + self::MSG);
        self::assertSame('validation_failed', $error?->name);
        self::assertSame('to must be a valid email address', $error->message);
    }

    public function testANonJsonErrorBodyStillAnswersACode(): void
    {
        [$sc] = F::client([new TransportResponse(502, ['content-type' => 'text/html'], '<html>Bad gateway</html>')], ['maxRetries' => 0]);
        [, $error] = $sc->domains->list();
        self::assertSame('http_502', $error?->name);
        self::assertSame('<html>Bad gateway</html>', $error->message);
    }

    public function testGetRequestsCarryNoIdempotencyKeyAndDropEmptyQueryValues(): void
    {
        [$sc, $t] = F::client([F::json(200, ['items' => [], 'total' => 0, 'totalCapped' => false, 'page' => 1, 'pageSize' => 50])]);
        $sc->emails->list(['status' => 'bounced', 'search' => '', 'domain' => null, 'pageSize' => 50]);
        self::assertArrayNotHasKey('Idempotency-Key', $t->calls[0]->headers);
        self::assertSame('https://api.test/v1/emails?status=bounced&pageSize=50', $t->calls[0]->url);
    }

    public function testBooleansInTheQueryAreWrittenTheWayTheApiReadsThem(): void
    {
        [$sc, $t] = F::client([F::json(200, [])]);
        $sc->mailboxes->list(['includeArchived' => true]);
        self::assertSame(['includeArchived' => 'true'], F::query($t->calls[0]));
    }

    public function testEmailsIterateWalksEveryPageAndStopsOnTheLast(): void
    {
        $row = static fn (int $n): array => ['id' => "id-$n"] + self::SENT;
        [$sc, $t] = F::client([
            F::json(200, ['items' => [$row(1), $row(2)], 'total' => 3, 'totalCapped' => false, 'page' => 1, 'pageSize' => 2]),
            F::json(200, ['items' => [$row(3)], 'total' => 3, 'totalCapped' => false, 'page' => 2, 'pageSize' => 2]),
        ]);
        $ids = [];
        foreach ($sc->emails->iterate(['pageSize' => 2]) as $e) {
            $ids[] = $e['id'];
        }
        self::assertSame(['id-1', 'id-2', 'id-3'], $ids);
        self::assertCount(2, $t->calls);
        self::assertSame('2', F::query($t->calls[1])['page']);
    }

    public function testSuppressionsIterateFollowsTheCursor(): void
    {
        $s = static fn (string $email): array => ['id' => $email, 'email' => $email, 'reason' => 'manual', 'scope' => 'all'];
        [$sc, $t] = F::client([
            F::json(200, ['data' => [$s('a@x.io')], 'page' => ['cursor' => 'c1', 'hasMore' => true], 'counts' => []]),
            F::json(200, ['data' => [$s('b@x.io')], 'page' => ['cursor' => null, 'hasMore' => false], 'counts' => []]),
        ]);
        $seen = [];
        foreach ($sc->suppressions->iterate(['reason' => 'manual']) as $r) {
            $seen[] = $r['email'];
        }
        self::assertSame(['a@x.io', 'b@x.io'], $seen);
        self::assertSame(['reason' => 'manual', 'cursor' => 'c1'], F::query($t->calls[1]));
    }

    public function testSuppressionsRemoveSendsTheRecordedReasonAsTheDeleteBody(): void
    {
        [$sc, $t] = F::client([F::json(200, ['removed' => true])]);
        $sc->suppressions->remove('sup-1', 'Customer asked us to resume mail');
        self::assertSame('DELETE', $t->calls[0]->method);
        self::assertSame('/v1/suppressions/sup-1', F::path($t->calls[0]));
        self::assertSame(['reason' => 'Customer asked us to resume mail'], F::body($t->calls[0]));
    }

    public function testSandboxesSendUsesTheSandboxTokenInsteadOfTheApiKey(): void
    {
        [$sc, $t] = F::client([F::json(201, ['id' => 'sbx-msg'])]);
        $sc->sandboxes->send('sc_sbx_abc', self::MSG);
        self::assertSame('/v1/sandbox/emails', F::path($t->calls[0]));
        self::assertSame('Bearer sc_sbx_abc', $t->calls[0]->headers['Authorization']);
    }

    public function testReplyToAndAttachmentsEncodeRawBytesAndPassContentBase64Through(): void
    {
        [$sc, $t] = F::client([F::json(201, self::SENT)]);
        $sc->emails->send(self::MSG + [
            'replyTo' => ['support@acme.io', 'billing@acme.io'],
            'attachments' => [
                ['filename' => 'booking-42.pdf', 'content' => "%PDF-1.4\x00\xff", 'contentType' => 'application/pdf'],
                ['filename' => 'notes.txt', 'contentBase64' => 'aGVsbG8='],
            ],
        ]);
        self::assertSame('/v1/emails', F::path($t->calls[0]));
        self::assertSame(
            self::MSG + [
                'replyTo' => ['support@acme.io', 'billing@acme.io'],
                'attachments' => [
                    ['filename' => 'booking-42.pdf', 'content' => 'JVBERi0xLjQA/w==', 'contentType' => 'application/pdf'],
                    ['filename' => 'notes.txt', 'content' => 'aGVsbG8='],
                ],
            ],
            F::body($t->calls[0]),
        );
    }

    public function testSandboxSendCarriesReplyToAndEncodesAttachmentsTheSameWay(): void
    {
        [$sc, $t] = F::client([F::json(201, ['id' => 'sbx-msg'])]);
        $sc->sandboxes->send('sc_sbx_abc', self::MSG + [
            'replyTo' => 'support@acme.io',
            'attachments' => [['filename' => 'a.bin', 'content' => "\x01\x02\x03"]],
        ]);
        self::assertSame('/v1/sandbox/emails', F::path($t->calls[0]));
        self::assertSame('Bearer sc_sbx_abc', $t->calls[0]->headers['Authorization']);
        self::assertSame(
            self::MSG + ['replyTo' => 'support@acme.io', 'attachments' => [['filename' => 'a.bin', 'content' => 'AQID']]],
            F::body($t->calls[0]),
        );
    }

    public function testBatchItemsGoThroughTheSameAttachmentRule(): void
    {
        [$sc, $t] = F::client([F::json(200, ['data' => [], 'summary' => []])]);
        $sc->batch->send([
            self::MSG + ['replyTo' => 'support@acme.io'],
            self::MSG + ['attachments' => [['filename' => 'a.bin', 'content' => "\x01\x02\x03"]]],
        ]);
        self::assertSame(
            ['emails' => [
                self::MSG + ['replyTo' => 'support@acme.io'],
                self::MSG + ['attachments' => [['filename' => 'a.bin', 'content' => 'AQID']]],
            ]],
            F::body($t->calls[0]),
        );
    }

    public function testAnAttachmentNeedsExactlyOneOfContentAndContentBase64(): void
    {
        foreach ([['filename' => 'a'], ['filename' => 'a', 'content' => 'x', 'contentBase64' => 'eA==']] as $bad) {
            [$sc, $t] = F::client([]);
            try {
                $sc->emails->send(self::MSG + ['attachments' => [$bad]]);
                self::fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('attachments[0]', $e->getMessage());
            }
            self::assertCount(0, $t->calls);
        }
    }

    public function testContactsImportIsMultipartCarriesNoIdempotencyKeyAndIsNeverRetried(): void
    {
        [$sc, $t] = F::client([F::json(503, ['error' => ['code' => 'unavailable', 'message' => 'down']])]);
        [, $error] = $sc->contacts->import(['file' => "name,email\nAda,ada@lumen.io\n", 'filename' => 'contacts.csv', 'onDuplicate' => 'update']);
        self::assertSame(503, $error?->statusCode);
        self::assertCount(1, $t->calls);
        $call = $t->calls[0];
        self::assertArrayNotHasKey('Idempotency-Key', $call->headers);
        self::assertMatchesRegularExpression('/^multipart\/form-data; boundary=(\S+)$/', $call->headers['Content-Type']);
        preg_match('/boundary=(\S+)$/', $call->headers['Content-Type'], $m);
        $parts = array_slice(explode('--' . $m[1], (string) $call->body), 1, -1);
        self::assertCount(2, $parts);
        self::assertSame("\r\nContent-Disposition: form-data; name=\"onDuplicate\"\r\n\r\nupdate\r\n", $parts[0]);
        self::assertSame(
            "\r\nContent-Disposition: form-data; name=\"file\"; filename=\"contacts.csv\"\r\nContent-Type: text/csv\r\n\r\nname,email\nAda,ada@lumen.io\n\r\n",
            $parts[1],
        );
    }

    public function testAVcfImportIsSentAsTextVcard(): void
    {
        [$sc, $t] = F::client([F::json(200, [])]);
        $sc->contacts->importPreview(['file' => "BEGIN:VCARD\r\nEND:VCARD\r\n", 'filename' => 'people.vcf']);
        self::assertSame('/v1/contacts/import/preview', F::path($t->calls[0]));
        self::assertStringContainsString('Content-Type: text/vcard', (string) $t->calls[0]->body);
    }

    public function testTemplateAssetsUploadBase64EncodesBytes(): void
    {
        [$sc, $t] = F::client([F::json(201, ['id' => 'a']), F::json(201, ['id' => 'b'])]);
        $sc->templates->assets->upload(['mimeType' => 'image/png', 'data' => "\x01\x02\x03", 'name' => 'logo']);
        $sc->templates->assets->upload(['mimeType' => 'image/png', 'base64' => 'AQID']);
        self::assertSame(['mimeType' => 'image/png', 'base64' => 'AQID', 'name' => 'logo'], F::body($t->calls[0]));
        self::assertSame(['mimeType' => 'image/png', 'base64' => 'AQID'], F::body($t->calls[1]));
    }

    public function testTextEndpointsReturnTheBodyAsAString(): void
    {
        [$sc] = F::client([new TransportResponse(200, ['content-type' => 'text/html'], '<html>hï</html>')]);
        self::assertSame('<html>hï</html>', $sc->templates->html('tpl_1')->data);
    }

    public function testAnAttachmentDownloadReturnsBytesTypeAndFilename(): void
    {
        [$sc, $t] = F::client([new TransportResponse(200, [
            'content-type' => 'application/pdf',
            'content-disposition' => "attachment; filename*=UTF-8''Rechnung%20M%C3%A4rz.pdf",
        ], '%PDF')]);
        $file = $sc->mailboxes->messages->attachment('m1', 'msg1', 'p1')->unwrap();
        self::assertEquals(new DownloadedFile('%PDF', 'application/pdf', 'Rechnung März.pdf'), $file);
        self::assertSame(['download' => '1'], F::query($t->calls[0]));
        self::assertSame('*/*', $t->calls[0]->headers['Accept']);
    }

    public function testPathSegmentsAreEncoded(): void
    {
        [$sc, $t] = F::client([F::json(200, [])]);
        $sc->templates->get('a/b?c');
        self::assertSame('https://api.test/v1/templates/a%2Fb%3Fc', $t->calls[0]->url);
    }

    public function testNoKeyAnywhereIsAClearApiKeyMissingAndNoRequestIsMade(): void
    {
        putenv('SENDCLIPPER_API_KEY');
        $t = new F([]);
        [, $error] = (new SendClipper(null, ['transport' => $t]))->domains->list();
        self::assertSame('api_key_missing', $error?->name);
        self::assertSame(401, $error->statusCode);
        self::assertSame([], $t->calls);
    }

    public function testTheKeyAndBaseUrlFallBackToTheEnvironmentAndATrailingV1IsTolerated(): void
    {
        putenv('SENDCLIPPER_API_KEY=sc_live_env');
        putenv('SENDCLIPPER_BASE_URL=http://localhost:4000/v1/');
        $t = new F([F::json(200, [])]);
        $sc = new SendClipper(null, ['transport' => $t]);
        $sc->domains->list();
        self::assertSame('http://localhost:4000', $sc->baseUrl);
        self::assertSame('http://localhost:4000/v1/domains', $t->calls[0]->url);
        self::assertSame('Bearer sc_live_env', $t->calls[0]->headers['Authorization']);
    }

    public function testDefaultAndPerCallHeadersAreSent(): void
    {
        [$sc, $t] = F::client([F::json(200, [])], ['defaultHeaders' => ['X-App' => 'shop']]);
        $sc->domains->list(['headers' => ['X-Trace-Id' => 't1']]);
        self::assertSame('shop', $t->calls[0]->headers['X-App']);
        self::assertSame('t1', $t->calls[0]->headers['X-Trace-Id']);
    }

    public function testAnIteratorThrowsApiExceptionSinceALoopHasNoErrorToReturn(): void
    {
        [$sc] = F::client([F::json(403, ['error' => ['code' => 'api_key_not_accepted', 'message' => 'no']])]);
        try {
            iterator_to_array($sc->contacts->iterate());
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('api_key_not_accepted', $e->apiCode);
            self::assertSame(403, $e->status);
        }
    }

    public function testUnwrapThrowsTheError(): void
    {
        [$sc] = F::client([F::json(404, ['error' => ['code' => 'not_found', 'message' => 'No such domain']])]);
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('No such domain');
        $sc->domains->records('d1')->unwrap();
    }

    public function testBatchSendPostsEmailsAndReturnsPerItemResults(): void
    {
        $reply = [
            'data' => [
                ['index' => 0, 'status' => 'accepted', 'id' => 'e1', 'messageId' => 'm1'],
                ['index' => 1, 'status' => 'failed', 'error' => ['code' => 'template_not_published', 'message' => 'draft']],
            ],
            'summary' => ['submitted' => 2, 'accepted' => 1, 'failed' => 1],
        ];
        $items = [self::MSG, ['from' => 'a@acme.io', 'to' => 'y@lumen.io', 'templateId' => 'tpl_draft']];
        [$sc, $t] = F::client([F::json(200, $reply), F::json(200, $reply)]);
        self::assertSame($reply, $sc->batch->send($items)->data);
        $sc->emails->sendBatch($items);
        foreach ($t->calls as $call) {
            self::assertSame('/v1/emails/batch', F::path($call));
            self::assertSame(['emails' => $items], F::body($call));
            self::assertNotEmpty($call->headers['Idempotency-Key']);
        }
    }

    public function testSandboxWaitForPollsUntilAMatchThenReadsIt(): void
    {
        $summary = ['id' => 'm2', 'subject' => 'Hi', 'trappedAt' => '2026-10-16T10:00:00.000Z'];
        [$sc, $t] = F::client([F::json(200, []), F::json(200, [$summary]), F::json(200, ['bodyHtml' => '<p>Hi</p>'] + $summary)]);
        [$msg, $error] = $sc->sandboxes->messages->waitFor('sbx1', static fn (array $m): bool => $m['subject'] === 'Hi', interval: 0);
        self::assertNull($error);
        self::assertSame('<p>Hi</p>', $msg['bodyHtml']);
        self::assertSame(['/v1/sandboxes/sbx1/messages', '/v1/sandboxes/sbx1/messages', '/v1/sandboxes/sbx1/messages/m2'], array_map([F::class, 'path'], $t->calls));
        self::assertSame(['limit' => '50'], F::query($t->calls[0]));
    }

    public function testSandboxWaitForTimesOutWithErrorTimeout(): void
    {
        [$sc] = F::client(array_fill(0, 5, F::json(200, [])));
        [, $error] = $sc->sandboxes->messages->waitFor('sbx1', timeout: 0, interval: 0);
        self::assertSame('timeout', $error?->name);
        self::assertNull($error->statusCode);
    }

    public function testAResultIsReadOnly(): void
    {
        $r = new Result(['a' => 1], null, []);
        $this->expectException(\LogicException::class);
        $r[0] = 'x';
    }

    /**
     * @return iterable<string, array{callable(SendClipper): mixed, string, string}>
     */
    public static function routes(): iterable
    {
        $cases = [
            [fn (SendClipper $sc) => $sc->domains->create(['name' => 'send.acme.io', 'region' => 'eu-west-1']), 'POST', '/v1/domains'],
            [fn (SendClipper $sc) => $sc->domains->verify('d1'), 'POST', '/v1/domains/d1/verify'],
            [fn (SendClipper $sc) => $sc->domains->enableReceiving('d1'), 'POST', '/v1/domains/d1/receiving'],
            [fn (SendClipper $sc) => $sc->domains->disableReceiving('d1'), 'DELETE', '/v1/domains/d1/receiving'],
            [fn (SendClipper $sc) => $sc->domains->setRegion('d1', 'us-east-1'), 'PATCH', '/v1/domains/d1/region'],
            [fn (SendClipper $sc) => $sc->domains->deletionPreview('d1'), 'GET', '/v1/domains/d1/deletion'],
            [fn (SendClipper $sc) => $sc->domains->remove('d1'), 'DELETE', '/v1/domains/d1'],
            [fn (SendClipper $sc) => $sc->emails->stats(), 'GET', '/v1/emails/stats/summary'],
            [fn (SendClipper $sc) => $sc->templates->list(['tag' => 't1']), 'GET', '/v1/templates'],
            [fn (SendClipper $sc) => $sc->templates->update('t1', ['name' => 'n']), 'PATCH', '/v1/templates/t1'],
            [fn (SendClipper $sc) => $sc->templates->remove('t1'), 'DELETE', '/v1/templates/t1'],
            [fn (SendClipper $sc) => $sc->templates->duplicate('t1'), 'POST', '/v1/templates/t1/duplicate'],
            [fn (SendClipper $sc) => $sc->templates->publish('t1'), 'POST', '/v1/templates/t1/publish'],
            [fn (SendClipper $sc) => $sc->templates->unpublish('t1'), 'POST', '/v1/templates/t1/unpublish'],
            [fn (SendClipper $sc) => $sc->templates->setTags('t1', ['g1']), 'PUT', '/v1/templates/t1/tags'],
            [fn (SendClipper $sc) => $sc->templates->projects(), 'GET', '/v1/templates/projects'],
            [fn (SendClipper $sc) => $sc->templates->tags->list(), 'GET', '/v1/templates/tags'],
            [fn (SendClipper $sc) => $sc->templates->tags->create(['name' => 'n', 'color' => 'blue']), 'POST', '/v1/templates/tags'],
            [fn (SendClipper $sc) => $sc->templates->tags->update('g1', ['color' => 'red']), 'PATCH', '/v1/templates/tags/g1'],
            [fn (SendClipper $sc) => $sc->templates->tags->remove('g1'), 'DELETE', '/v1/templates/tags/g1'],
            [fn (SendClipper $sc) => $sc->templates->assets->list(), 'GET', '/v1/templates/assets'],
            [fn (SendClipper $sc) => $sc->sandboxes->list(), 'GET', '/v1/sandboxes'],
            [fn (SendClipper $sc) => $sc->sandboxes->get('s1'), 'GET', '/v1/sandboxes/s1'],
            [fn (SendClipper $sc) => $sc->sandboxes->update('s1', ['name' => 'n']), 'PATCH', '/v1/sandboxes/s1'],
            [fn (SendClipper $sc) => $sc->sandboxes->remove('s1'), 'DELETE', '/v1/sandboxes/s1'],
            [fn (SendClipper $sc) => $sc->sandboxes->credentials('s1'), 'GET', '/v1/sandboxes/s1/credentials'],
            [fn (SendClipper $sc) => $sc->sandboxes->resetCredentials('s1'), 'POST', '/v1/sandboxes/s1/credentials'],
            [fn (SendClipper $sc) => $sc->sandboxes->messages->raw('s1', 'm1'), 'GET', '/v1/sandboxes/s1/messages/m1/raw'],
            [fn (SendClipper $sc) => $sc->sandboxes->messages->remove('s1', 'm1'), 'DELETE', '/v1/sandboxes/s1/messages/m1'],
            [fn (SendClipper $sc) => $sc->sandboxes->messages->clear('s1'), 'DELETE', '/v1/sandboxes/s1/messages'],
            [fn (SendClipper $sc) => $sc->sandboxes->messages->markAllRead('s1'), 'POST', '/v1/sandboxes/s1/read-all'],
            [fn (SendClipper $sc) => $sc->webhooks->list(), 'GET', '/v1/webhook-endpoints'],
            [fn (SendClipper $sc) => $sc->webhooks->create(['url' => 'https://x.io', 'events' => ['email.delivered']]), 'POST', '/v1/webhook-endpoints'],
            [fn (SendClipper $sc) => $sc->webhooks->update('w1', ['active' => false]), 'PATCH', '/v1/webhook-endpoints/w1'],
            [fn (SendClipper $sc) => $sc->webhooks->remove('w1'), 'DELETE', '/v1/webhook-endpoints/w1'],
            [fn (SendClipper $sc) => $sc->webhooks->rotateSecret('w1'), 'POST', '/v1/webhook-endpoints/w1/rotate-secret'],
            [fn (SendClipper $sc) => $sc->webhooks->deliveries(['limit' => 5]), 'GET', '/v1/webhook-deliveries'],
            [fn (SendClipper $sc) => $sc->webhooks->dispatches->list(), 'GET', '/v1/webhook-dispatches'],
            [fn (SendClipper $sc) => $sc->webhooks->dispatches->get('x1'), 'GET', '/v1/webhook-dispatches/x1'],
            [fn (SendClipper $sc) => $sc->webhooks->dispatches->replay('x1'), 'POST', '/v1/webhook-dispatches/x1/replay'],
            [fn (SendClipper $sc) => $sc->webhooks->dispatches->replayAll(), 'POST', '/v1/webhook-dispatches/replay-all'],
            [fn (SendClipper $sc) => $sc->contacts->list(), 'GET', '/v1/contacts'],
            [fn (SendClipper $sc) => $sc->contacts->create(['name' => 'Ada', 'email' => 'a@x.io']), 'POST', '/v1/contacts'],
            [fn (SendClipper $sc) => $sc->contacts->export(['format' => 'vcf']), 'GET', '/v1/contacts/export'],
            [fn (SendClipper $sc) => $sc->audiences->list(), 'GET', '/v1/audiences'],
            [fn (SendClipper $sc) => $sc->suppressions->create(['email' => 'a@x.io']), 'POST', '/v1/suppressions'],
            [fn (SendClipper $sc) => $sc->suppressions->export(['reason' => 'manual']), 'GET', '/v1/suppressions/export'],
            [fn (SendClipper $sc) => $sc->mailboxes->get('mb1'), 'GET', '/v1/mailboxes/mb1'],
            [fn (SendClipper $sc) => $sc->mailboxes->create(['domainId' => 'd1', 'localPart' => 'support']), 'POST', '/v1/mailboxes'],
            [fn (SendClipper $sc) => $sc->mailboxes->update('mb1', ['displayName' => 'Support']), 'PATCH', '/v1/mailboxes/mb1'],
            [fn (SendClipper $sc) => $sc->mailboxes->archive('mb1'), 'DELETE', '/v1/mailboxes/mb1'],
            [fn (SendClipper $sc) => $sc->mailboxes->messages->list('mb1', ['folder' => 'inbox']), 'GET', '/v1/mailboxes/mb1/messages'],
            [fn (SendClipper $sc) => $sc->mailboxes->messages->get('mb1', 'm1'), 'GET', '/v1/mailboxes/mb1/messages/m1'],
            [fn (SendClipper $sc) => $sc->automations->enroll('a1', 'ada@x.io'), 'POST', '/v1/automations/a1/enrollments'],
            [fn (SendClipper $sc) => $sc->workspace->get(), 'GET', '/v1/workspace'],
            [fn (SendClipper $sc) => $sc->apiKeys->list(), 'GET', '/v1/api-keys'],
        ];
        foreach ($cases as [$fn, $method, $path]) {
            yield "$method $path" => [$fn, $method, $path];
        }
    }

    /**
     * @param callable(SendClipper): mixed $fn
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testEveryMethodReachesTheRouteTheApiServes(callable $fn, string $method, string $path): void
    {
        [$sc, $t] = F::client([F::json(200, [])]);
        $fn($sc);
        self::assertSame([$method, $path], [$t->calls[0]->method, F::path($t->calls[0])]);
        if ($method !== 'GET') {
            self::assertNotEmpty($t->calls[0]->headers['Idempotency-Key'] ?? null);
        }
    }

    public function testSuppressionsExportDropsPagingParams(): void
    {
        [$sc, $t] = F::client([new TransportResponse(200, ['content-type' => 'text/csv'], "email\n")]);
        self::assertSame("email\n", $sc->suppressions->export(['reason' => 'manual', 'cursor' => 'c', 'limit' => 5])->data);
        self::assertSame(['reason' => 'manual'], F::query($t->calls[0]));
    }

    public function testVersionMatchesTheChangelog(): void
    {
        $changelog = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
        self::assertStringContainsString('## ' . Version::VERSION . ' ', $changelog);
    }
}
