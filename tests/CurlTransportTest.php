<?php

declare(strict_types=1);

namespace SendClipper\Tests;

use PHPUnit\Framework\TestCase;
use SendClipper\SendClipper;
use SendClipper\Transport\CurlTransport;
use SendClipper\Transport\TransportException;
use SendClipper\Transport\TransportRequest;

/** The default transport against a real local HTTP server (`php -S`). */
final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server;
    private static string $base;

    public static function setUpBeforeClass(): void
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($sock);
        $port = (int) substr((string) stream_socket_get_name($sock, false), strrpos((string) stream_socket_get_name($sock, false), ':') + 1);
        fclose($sock);
        self::$base = "http://127.0.0.1:$port";
        $cmd = [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/Support/server/echo.php'];
        $proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($proc);
        self::$server = $proc;
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $port);
            if ($c !== false) {
                fclose($c);
                return;
            }
            usleep(50_000);
        }
        self::fail('php -S did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    public function testItSendsMethodHeadersAndBodyAndReturnsLowerCaseHeaders(): void
    {
        $res = (new CurlTransport())->send(new TransportRequest('PATCH', self::$base . '/x?a=1', ['X-Thing' => 'Grüße', 'Content-Type' => 'application/json'], '{"a":1}', 5));
        self::assertSame(200, $res->status);
        self::assertSame('yes', $res->headers['x-echo']);
        self::assertSame('a=1, b=2', $res->headers['set-cookie']);
        $echo = json_decode($res->body, true);
        self::assertSame('PATCH', $echo['method']);
        self::assertSame('/x?a=1', $echo['uri']);
        self::assertSame('{"a":1}', $echo['body']);
        self::assertSame('Grüße', $echo['headers']['x-thing']);
        self::assertArrayNotHasKey('expect', $echo['headers']);
    }

    public function testAnErrorStatusIsReturnedNotThrown(): void
    {
        $res = (new CurlTransport())->send(new TransportRequest('POST', self::$base . '/refuse', [], '{}', 5));
        self::assertSame(422, $res->status);
    }

    public function testATimeoutIsMarkedAsOne(): void
    {
        try {
            (new CurlTransport())->send(new TransportRequest('GET', self::$base . '/slow', [], null, 0.3));
            self::fail('expected a timeout');
        } catch (TransportException $e) {
            self::assertTrue($e->timedOut);
        }
    }

    public function testNothingListeningIsAConnectionErrorNotATimeout(): void
    {
        try {
            (new CurlTransport())->send(new TransportRequest('GET', 'http://127.0.0.1:9/x', [], null, 2));
            self::fail('expected a connection error');
        } catch (TransportException $e) {
            self::assertFalse($e->timedOut);
        }
    }

    public function testTheClientEndToEndOverCurl(): void
    {
        $sc = new SendClipper('sc_live_curl', ['baseUrl' => self::$base]);
        [$data, $error] = $sc->emails->send(['from' => 'a@acme.io', 'to' => 'b@lumen.io', 'subject' => 's', 'html' => 'h']);
        self::assertNull($error);
        self::assertSame('POST', $data['method']);
        self::assertSame('/v1/emails', $data['uri']);
        self::assertSame('Bearer sc_live_curl', $data['headers']['authorization']);
        self::assertStringStartsWith('sc-php-', $data['headers']['idempotency-key']);

        [, $error] = $sc->workspace->get(['timeout' => 0.3, 'maxRetries' => 0, 'headers' => []]);
        self::assertNull($error);
    }
}
