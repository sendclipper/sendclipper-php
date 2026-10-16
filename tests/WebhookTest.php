<?php

declare(strict_types=1);

namespace SendClipper\Tests;

use PHPUnit\Framework\TestCase;
use SendClipper\Exception\WebhookVerificationException;
use SendClipper\SendClipper;
use SendClipper\Tests\Support\FakeTransport;
use SendClipper\Webhook;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';
    private const T = 1756800000;
    private const BODY = '{"id":"evt_1","event":"email.delivered","createdAt":"2026-09-06T09:41:02.518Z","data":{"emailId":"e1","messageId":"m1","to":"user@acme.io","domain":"send.acme.io","subject":"Your receipt"}}';
    /** Computed with `openssl dgst -sha256 -hmac`, not with this SDK (the Node SDK's vector). */
    private const SIG = '76921f5ba77ae7f38686e8f147da3903c0ff87100cbd47ee466b7d646d198192';
    private const HEADER = 't=' . self::T . ',v1=' . self::SIG;

    private static function refused(string $reason, callable $fn): void
    {
        try {
            $fn();
            self::fail("expected $reason");
        } catch (WebhookVerificationException $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    public function testAGenuineDeliveryVerifiesAndReturnsTheParsedEvent(): void
    {
        $event = Webhook::verify(self::BODY, self::HEADER, self::SECRET, now: self::T + 10);
        self::assertSame('evt_1', $event['id']);
        self::assertSame('email.delivered', $event['event']);
        self::assertSame('user@acme.io', $event['data']['to']);
    }

    public function testHeaderLookupWorksForEveryFrameworkShape(): void
    {
        $psr7 = new class (self::HEADER) {
            public function __construct(private string $h)
            {
            }

            public function getHeaderLine(string $name): string
            {
                return strtolower($name) === 'x-sendclipper-signature-v2' ? $this->h : '';
            }
        };
        $headerBag = new class (self::HEADER) {
            public function __construct(private string $h)
            {
            }

            public function get(string $name): ?string
            {
                return strtolower($name) === 'x-sendclipper-signature-v2' ? $this->h : null;
            }
        };
        $shapes = [
            ['X-SendClipper-Signature-V2' => self::HEADER, 'Content-Type' => 'application/json'], // getallheaders()
            ['x-sendclipper-signature-v2' => [self::HEADER]], // Symfony headers->all()
            ['HTTP_X_SENDCLIPPER_SIGNATURE_V2' => self::HEADER], // $_SERVER
            $psr7,
            $headerBag,
        ];
        foreach ($shapes as $headers) {
            self::assertSame('evt_1', Webhook::verify(self::BODY, $headers, self::SECRET, now: self::T)['id']);
        }
    }

    public function testDuringARotationTwoV1ValuesAreSentAndEitherOneIsAccepted(): void
    {
        $other = str_repeat('a', 64);
        foreach (['t=' . self::T . ",v1=$other,v1=" . self::SIG, 't=' . self::T . ',v1=' . self::SIG . ",v1=$other"] as $header) {
            self::assertSame('evt_1', Webhook::verify(self::BODY, $header, self::SECRET, now: self::T)['id']);
        }
    }

    public function testAChangedBodyAWrongSecretOrAWrongSignatureIsRefused(): void
    {
        self::refused('signature_mismatch', fn () => Webhook::verify(str_replace('user@', 'evil@', self::BODY), self::HEADER, self::SECRET, now: self::T));
        self::refused('signature_mismatch', fn () => Webhook::verify(self::BODY, self::HEADER, 'whsec_other', now: self::T));
        self::refused('signature_mismatch', fn () => Webhook::verify(self::BODY, 't=' . self::T . ',v1=' . str_repeat('0', 64), self::SECRET, now: self::T));
    }

    public function testReEncodedJsonFailsBecauseTheSignatureCoversTheRawBytes(): void
    {
        $reencoded = (string) json_encode(json_decode(self::BODY), JSON_PRETTY_PRINT);
        self::refused('signature_mismatch', fn () => Webhook::verify($reencoded, self::HEADER, self::SECRET, now: self::T));
    }

    public function testADecodedArrayInsteadOfTheRawBodyIsRefusedWithAPointer(): void
    {
        try {
            Webhook::verify(json_decode(self::BODY, true), self::HEADER, self::SECRET, now: self::T);
            self::fail('expected invalid_payload');
        } catch (WebhookVerificationException $e) {
            self::assertSame('invalid_payload', $e->reason);
            self::assertStringContainsString('getContent()', $e->getMessage());
        }
    }

    public function testATimestampOutsideTheToleranceIsRefused(): void
    {
        self::refused('timestamp_out_of_tolerance', fn () => Webhook::verify(self::BODY, self::HEADER, self::SECRET, now: self::T + 301));
        self::refused('timestamp_out_of_tolerance', fn () => Webhook::verify(self::BODY, self::HEADER, self::SECRET, now: self::T - 301));
        self::assertSame('evt_1', Webhook::verify(self::BODY, self::HEADER, self::SECRET, 3600, self::T + 3600)['id']);
    }

    public function testAMissingOrMalformedHeaderIsRefused(): void
    {
        self::refused('missing_header', fn () => Webhook::verify(self::BODY, [], self::SECRET, now: self::T));
        self::refused('missing_header', fn () => Webhook::verify(self::BODY, self::HEADER, '', now: self::T));
        self::refused('malformed_header', fn () => Webhook::verify(self::BODY, 'v1=' . self::SIG, self::SECRET, now: self::T));
        self::refused('malformed_header', fn () => Webhook::verify(self::BODY, 't=' . self::T, self::SECRET, now: self::T));
        self::refused('malformed_header', fn () => Webhook::verify(self::BODY, 't=abc,v1=' . self::SIG, self::SECRET, now: self::T));
    }

    public function testASignedBodyThatIsNotJsonIsRefused(): void
    {
        self::refused('invalid_payload', fn () => Webhook::verify('not json', Webhook::signatureHeader(self::SECRET, 'not json', self::T), self::SECRET, now: self::T));
    }

    public function testSignatureHeaderBuildsWhatVerifyAccepts(): void
    {
        $header = Webhook::signatureHeader(self::SECRET, self::BODY, self::T);
        self::assertSame(self::HEADER, $header);
        self::assertSame([self::T, [self::SIG]], Webhook::parseSignatureHeader($header));
    }

    public function testTheClientsWebhooksVerifyIsTheSameCheckAndMakesNoRequest(): void
    {
        $sc = new SendClipper('k', ['transport' => new FakeTransport([])]);
        self::assertSame('evt_1', $sc->webhooks->verify(self::BODY, self::HEADER, self::SECRET, now: self::T)['id']);
    }
}
