<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Tests;

use E258Tech\NexoraSms\Exception\ConnectionException;
use E258Tech\NexoraSms\Exception\RateLimitException;
use E258Tech\NexoraSms\NexoraSmsClient;
use E258Tech\NexoraSms\Request\SendMessageRequest;
use E258Tech\NexoraSms\Tests\Support\RecordingSleeper;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class Psr18TransportTest extends TestCase
{
    public function testUsesAPsr18Client(): void
    {
        $psr = new class () implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $requests = [];
            /** @var list<ResponseInterface|\Throwable> */
            public array $replies = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;
                $reply = array_shift($this->replies) ?? new Response(200, [], '{}');
                if ($reply instanceof \Throwable) {
                    throw $reply;
                }

                return $reply;
            }
        };
        $networkError = new class ('down') extends \RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                return (new Psr17Factory())->createRequest('GET', 'http://x');
            }
        };
        $psr->replies = [
            $networkError,
            new Response(201, ['Idempotent-Replayed' => 'true'], '{"channels":[{"channel":"sms","campaign_id":"c1"}],"invalid":[]}'),
            new Response(429, ['Retry-After' => '3'], '{"title":"Devagar"}'),
        ];
        $factory = new Psr17Factory();
        $client = NexoraSmsClient::builder()->publicKey(ClientTest::PK)->apiKey(ClientTest::AK)
            ->baseUrl('https://sms.example.test')->psr18($psr, $factory, $factory)->maxRetries(1)
            ->sleeper(new RecordingSleeper())->build();

        $result = $client->messages()->send(SendMessageRequest::builder()->sms('Olá')->to('+258841234567')->build());
        self::assertTrue($result->replayed);
        self::assertSame('c1', $result->channels[0]->campaignId);
        self::assertCount(2, $psr->requests);
        $request = $psr->requests[1];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://sms.example.test/api/v1/messages', (string) $request->getUri());
        self::assertSame(ClientTest::PK, $request->getHeaderLine('X-Public-Key'));
        self::assertSame($result->idempotencyKey, $request->getHeaderLine('Idempotency-Key'));
        self::assertSame($psr->requests[0]->getHeaderLine('Idempotency-Key'), $request->getHeaderLine('Idempotency-Key'));
        self::assertStringContainsString('"recipients":["+258841234567"]', (string) $request->getBody());

        // The queued 429 is retried once (maxRetries 1); the next reply is the default 200.
        $client->wallet()->get();
        self::assertCount(4, $psr->requests);

        $psr->replies = [new Response(429, ['Retry-After' => '3'], '{"title":"Devagar"}'), new Response(429, ['Retry-After' => '3'], '{"title":"Devagar"}')];
        try {
            $client->wallet()->get();
            self::fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            self::assertSame(3, $e->retryAfter);
            self::assertSame('Devagar', $e->getMessage());
        }

        $psr->replies = [$networkError, $networkError];
        $this->expectException(ConnectionException::class);
        $client->wallet()->get();
    }
}
