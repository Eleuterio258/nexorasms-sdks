<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Tests;

use E258Tech\NexoraSms\Enum\Channel;
use E258Tech\NexoraSms\Enum\MessageStatus;
use E258Tech\NexoraSms\Exception\WebhookVerificationException;
use E258Tech\NexoraSms\Webhook\WebhookEventType;
use E258Tech\NexoraSms\Webhook\WebhookVerifier;
use PHPUnit\Framework\TestCase;

final class WebhookVerifierTest extends TestCase
{
    private const SECRET = 'whsec_test';
    private const BODY = '{"id":"evt_1","type":"ping","created_at":"2026-11-08T10:20:00Z","data":{"message":"ping"}}';
    private const T = 1762597200;
    private const V1 = 'cde593b98434dd7cd2944443131e9bf1bf47bc37630a621aa9481d76ab38b77e';

    private static function verifier(int $now = self::T, int $tolerance = 300): WebhookVerifier
    {
        return new WebhookVerifier(self::SECRET, $tolerance, static fn (): int => $now);
    }

    private static function header(): string
    {
        return 't=' . self::T . ',v1=' . self::V1;
    }

    public function testFixedVectorIsValid(): void
    {
        self::assertSame(self::V1, hash_hmac('sha256', self::T . '.' . self::BODY, self::SECRET));
        $event = self::verifier()->verify(self::BODY, self::header());
        self::assertSame('evt_1', $event->id);
        self::assertSame(WebhookEventType::Ping, $event->type);
        self::assertSame('ping', $event->typeRaw);
        self::assertSame('2026-11-08T10:20:00+00:00', $event->createdAt->format(DATE_ATOM));
        self::assertSame(['message' => 'ping'], $event->data);
        self::assertFalse($event->isMessageEvent());
    }

    public function testHeaderWithSpacesAndExtraParts(): void
    {
        $event = self::verifier()->verify(self::BODY, ' t=' . self::T . ' , v0=abc, v1=' . str_repeat('0', 64) . ', v1=' . self::V1);
        self::assertSame('evt_1', $event->id);
    }

    public function testWrongSignature(): void
    {
        $this->expectFailure('/inválida/', fn () => self::verifier()->verify(self::BODY, 't=' . self::T . ',v1=' . str_repeat('0', 64)));
        $this->expectFailure('/inválida/', fn () => self::verifier()->verify(self::BODY . ' ', self::header()));
        $this->expectFailure('/inválida/', fn () => self::verifier()->verify(self::BODY, 't=' . (self::T + 1) . ',v1=' . self::V1));
        $this->expectFailure('/inválida/', fn () => (new WebhookVerifier('whsec_other', 300, static fn (): int => self::T))->verify(self::BODY, self::header()));
        $this->expectFailure('/inválida/', fn () => self::verifier()->verify(self::BODY, 't=' . self::T . ',v1=' . substr(self::V1, 0, 10)));
    }

    public function testExpired(): void
    {
        $this->expectFailure('/janela/', fn () => self::verifier(self::T + 301)->verify(self::BODY, self::header()));
        $this->expectFailure('/janela/', fn () => self::verifier(self::T - 301)->verify(self::BODY, self::header()));
        self::assertSame('evt_1', self::verifier(self::T - 300)->verify(self::BODY, self::header())->id);
        self::assertSame('evt_1', self::verifier(self::T + 300)->verify(self::BODY, self::header())->id);
        $this->expectFailure('/janela/', fn () => self::verifier(self::T + 11, 10)->verify(self::BODY, self::header()));
    }

    public function testMalformedHeader(): void
    {
        foreach (['v1=' . self::V1, 't=' . self::T, '', null, 't=abc,v1=' . self::V1, 't=,v1=' . self::V1, 't=' . self::T . ',v1=', 'garbage', 't=-5,v1=' . self::V1] as $header) {
            $this->expectFailure('/mal formado/', fn () => self::verifier()->verify(self::BODY, $header));
        }
    }

    public function testInvalidJsonBody(): void
    {
        $body = 'not json';
        $header = 't=' . self::T . ',v1=' . hash_hmac('sha256', self::T . '.' . $body, self::SECRET);
        $this->expectFailure('/JSON/', fn () => self::verifier()->verify($body, $header));
    }

    public function testMessageEvent(): void
    {
        $body = json_encode(['id' => 'e2', 'type' => 'message.delivered', 'created_at' => '2026-11-08T10:00:00Z', 'data' => [
            'message_id' => 'm1', 'campaign_id' => 'c1', 'contact_id' => 'k1', 'external_id' => 'CLI-1', 'channel' => 'sms',
            'status' => 'delivered', 'cost' => 1.5, 'occurred_at' => '2026-11-08T10:00:00Z',
        ]], JSON_THROW_ON_ERROR);
        $header = 't=' . self::T . ',v1=' . hash_hmac('sha256', self::T . '.' . $body, self::SECRET);
        $event = self::verifier()->verify($body, $header);
        self::assertTrue($event->isMessageEvent());
        self::assertSame('m1', $event->data['message_id']);
        $message = $event->messageEvent();
        self::assertSame('m1', $message->messageId);
        self::assertSame('CLI-1', $message->externalId);
        self::assertSame('k1', $message->contactId);
        self::assertSame(Channel::Sms, $message->channel);
        self::assertSame(MessageStatus::Delivered, $message->status);
        self::assertSame(1.5, $message->cost);

        $this->expectException(\LogicException::class);
        self::verifier()->verify(self::BODY, self::header())->messageEvent();
    }

    public function testConstructorValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WebhookVerifier('');
    }

    /**
     * @param callable(): mixed $action
     */
    private function expectFailure(string $pattern, callable $action): void
    {
        try {
            $action();
            self::fail('Expected WebhookVerificationException');
        } catch (WebhookVerificationException $e) {
            self::assertMatchesRegularExpression($pattern, $e->getMessage());
        }
    }
}
