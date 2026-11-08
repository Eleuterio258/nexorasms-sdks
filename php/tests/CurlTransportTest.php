<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Tests;

use E258Tech\NexoraSms\Exception\InsufficientFundsException;
use E258Tech\NexoraSms\Http\CurlTransport;
use E258Tech\NexoraSms\Http\HttpRequest;
use E258Tech\NexoraSms\Http\TransportException;
use E258Tech\NexoraSms\NexoraSmsClient;
use E258Tech\NexoraSms\Request\SendMessageRequest;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test of the real cURL transport against PHP's built-in server (tests/server/router.php).
 */
#[RequiresPhpExtension('curl')]
final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $process = null;
    private static string $baseUrl = '';

    public static function setUpBeforeClass(): void
    {
        $port = self::freePort();
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/server/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            return;
        }
        self::$process = $process;
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($socket !== false) {
                fclose($socket);
                self::$baseUrl = 'http://127.0.0.1:' . $port;

                return;
            }
            usleep(50_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$baseUrl === '') {
            self::markTestSkipped('Could not start php -S.');
        }
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        if ($server === false) {
            return 18080;
        }
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    public function testSendsMethodHeadersAndBodyAndReadsTheResponse(): void
    {
        $response = (new CurlTransport())->send(new HttpRequest(
            'PATCH',
            self::$baseUrl . '/echo?a=1&b=x%20y',
            ['X-Api-Key' => 'k', 'Content-Type' => 'application/json', 'User-Agent' => 'nexorasms-php/0.1.0'],
            '{"name":"Olá"}',
        ), 5.0);

        self::assertSame(200, $response->status);
        self::assertSame('yes', $response->header('X-Echo'));
        self::assertSame('yes', $response->headers['x-echo']);
        $echo = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($echo);
        self::assertSame('PATCH', $echo['method']);
        self::assertSame('/echo?a=1&b=x%20y', $echo['uri']);
        self::assertSame('k', $echo['headers']['x-api-key']);
        self::assertSame('nexorasms-php/0.1.0', $echo['headers']['user-agent']);
        self::assertSame('{"name":"Olá"}', $echo['body']);
    }

    public function testReturnsErrorResponsesWithHeaders(): void
    {
        $response = (new CurlTransport())->send(new HttpRequest('GET', self::$baseUrl . '/status/503', []), 5.0);
        self::assertSame(503, $response->status);
        self::assertSame('7', $response->header('Retry-After'));
        self::assertStringContainsString('Estado 503', $response->body);
    }

    public function testEndToEndThroughTheClient(): void
    {
        $client = NexoraSmsClient::builder()
            ->publicKey(ClientTest::PK)->apiKey(ClientTest::AK)->baseUrl(self::$baseUrl)->build();
        self::assertSame(10.0, $client->wallet()->get()->available);

        try {
            $client->messages()->send(SendMessageRequest::builder()->sms('Olá')->to('+258841234567')->build());
            self::fail('Expected InsufficientFundsException');
        } catch (InsufficientFundsException $e) {
            self::assertSame(402, $e->status);
            self::assertSame('Saldo insuficiente para este envio.', $e->getMessage());
            self::assertSame('trace-1', $e->traceId);
        }
    }

    public function testTimeout(): void
    {
        try {
            (new CurlTransport())->send(new HttpRequest('GET', self::$baseUrl . '/sleep', []), 0.3);
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertTrue($e->timedOut, $e->getMessage());
        }
    }

    public function testConnectionRefused(): void
    {
        try {
            (new CurlTransport())->send(new HttpRequest('GET', 'http://127.0.0.1:' . self::freePort() . '/', []), 2.0);
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertFalse($e->timedOut);
        }
    }
}
