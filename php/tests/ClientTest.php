<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Tests;

use E258Tech\NexoraSms\Enum\AttemptStatus;
use E258Tech\NexoraSms\Enum\CampaignStatus;
use E258Tech\NexoraSms\Enum\Channel;
use E258Tech\NexoraSms\Enum\ConsentStatus;
use E258Tech\NexoraSms\Enum\ContactStatus;
use E258Tech\NexoraSms\Enum\Encoding;
use E258Tech\NexoraSms\Enum\MessageStatus;
use E258Tech\NexoraSms\Enum\TransactionType;
use E258Tech\NexoraSms\Exception\AuthenticationException;
use E258Tech\NexoraSms\Exception\ConflictException;
use E258Tech\NexoraSms\Exception\ConnectionException;
use E258Tech\NexoraSms\Exception\InsufficientFundsException;
use E258Tech\NexoraSms\Exception\NexoraSmsException;
use E258Tech\NexoraSms\Exception\NotFoundException;
use E258Tech\NexoraSms\Exception\PermissionDeniedException;
use E258Tech\NexoraSms\Exception\RateLimitException;
use E258Tech\NexoraSms\Exception\ServerException;
use E258Tech\NexoraSms\Exception\ValidationException;
use E258Tech\NexoraSms\Http\TransportException;
use E258Tech\NexoraSms\NexoraSmsClient;
use E258Tech\NexoraSms\NexoraSmsClientBuilder;
use E258Tech\NexoraSms\Request\ContactQuery;
use E258Tech\NexoraSms\Request\ContactRequest;
use E258Tech\NexoraSms\Request\MessageQuery;
use E258Tech\NexoraSms\Request\SendMessageRequest;
use E258Tech\NexoraSms\Tests\Support\FakeTransport;
use E258Tech\NexoraSms\Tests\Support\RecordingSleeper;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public const PK = 'e258_nex_sms_pk_HXj4qcWmXl0m';
    public const AK = 'e258_nex_sms_ak_secretsecretsecretsecret123';
    private const SEND_RESULT = ['channels' => [['channel' => 'sms', 'campaign_id' => 'c1', 'recipients' => 1, 'cost' => 1.5, 'status' => 'running']], 'invalid' => []];

    private FakeTransport $transport;
    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->sleeper = new RecordingSleeper();
    }

    /**
     * @param (callable(NexoraSmsClientBuilder): NexoraSmsClientBuilder)|null $configure
     */
    private function client(?callable $configure = null): NexoraSmsClient
    {
        $builder = NexoraSmsClient::builder()->publicKey(self::PK)->apiKey(self::AK)
            ->baseUrl('http://127.0.0.1:9/')->transport($this->transport)->sleeper($this->sleeper);

        return ($configure !== null ? $configure($builder) : $builder)->build();
    }

    private static function smsRequest(): SendMessageRequest
    {
        return SendMessageRequest::builder()->sms('Olá', 'MINHALOJA')->to('+258841234567')->build();
    }

    /**
     * @param class-string<\Throwable> $class
     * @param callable(): mixed         $action
     */
    private static function expectThrows(string $class, callable $action, ?string $pattern = null): \Throwable
    {
        try {
            $action();
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e, $e->getMessage());
            if ($pattern !== null) {
                self::assertMatchesRegularExpression($pattern, $e->getMessage());
            }

            return $e;
        }
        self::fail("Expected $class");
    }

    // ------------------------------------------------------------ client builder

    public function testBuilderRequiresBothKeysWithTheRightPrefix(): void
    {
        self::expectThrows(\InvalidArgumentException::class, fn () => NexoraSmsClient::builder()->apiKey(self::AK)->build(), '/publicKey/');
        self::expectThrows(\InvalidArgumentException::class, fn () => NexoraSmsClient::builder()->publicKey(self::PK)->build(), '/apiKey/');
        self::expectThrows(\InvalidArgumentException::class, fn () => NexoraSmsClient::builder()->publicKey('nxk_x')->apiKey(self::AK)->build(), '/e258_nex_sms_pk_/');
        self::expectThrows(\InvalidArgumentException::class, fn () => NexoraSmsClient::builder()->publicKey(self::PK)->apiKey(self::PK)->build(), '/e258_nex_sms_ak_/');
        self::expectThrows(\InvalidArgumentException::class, fn () => NexoraSmsClient::builder()->maxRetries(-1));
        self::expectThrows(\InvalidArgumentException::class, fn () => NexoraSmsClient::builder()->timeout(0));
        self::expectThrows(\InvalidArgumentException::class, fn () => NexoraSmsClient::builder()->publicKey(self::PK)->apiKey(self::AK)->baseUrl('sms.e258tech.tech')->build(), '/baseUrl/');
        self::assertSame('https://sms.e258tech.tech', NexoraSmsClient::builder()->publicKey(self::PK)->apiKey(self::AK)->build()->baseUrl());
        self::assertSame(0, count($this->transport->requests));
    }

    // ------------------------------------------------------------ messages

    public function testSendSendsKeysSnakeCaseJsonAndAGeneratedIdempotencyKey(): void
    {
        $this->transport->reply(201, self::SEND_RESULT);
        $result = $this->client(fn (NexoraSmsClientBuilder $b) => $b->userAgent('loja/1.0')->timeout(12.5))->messages()->send(
            SendMessageRequest::builder()->sms('Olá', 'MINHALOJA')->email('Assunto', '<p>Olá</p>')
                ->to('+258841234567', 'ana@x.co.mz')->contact('k1')->ignoreQuietHours()->build(),
        );

        $request = $this->transport->last();
        self::assertSame('POST', $request->method);
        self::assertSame('http://127.0.0.1:9/api/v1/messages', $request->url);
        self::assertSame(self::PK, $request->headers['X-Public-Key']);
        self::assertSame(self::AK, $request->headers['X-Api-Key']);
        self::assertSame('application/json', $request->headers['Accept']);
        self::assertSame('application/json', $request->headers['Content-Type']);
        self::assertSame('nexorasms-php/0.1.0 loja/1.0', $request->headers['User-Agent']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $request->headers['Idempotency-Key']);
        self::assertSame([12.5], $this->transport->timeouts);
        self::assertSame([
            'channels' => [
                ['channel' => 'sms', 'content' => 'Olá', 'sender' => 'MINHALOJA'],
                ['channel' => 'email', 'subject' => 'Assunto', 'content' => '<p>Olá</p>'],
            ],
            'recipients' => ['+258841234567', 'ana@x.co.mz'],
            'contact_ids' => ['k1'],
            'ignore_quiet_hours' => true,
        ], $this->transport->body(0));
        self::assertStringContainsString('"content":"Olá"', (string) $request->body); // UTF-8 kept as is

        self::assertSame('c1', $result->channels[0]->campaignId);
        self::assertSame(Channel::Sms, $result->channels[0]->channel);
        self::assertSame(CampaignStatus::Running, $result->channels[0]->status);
        self::assertSame(1.5, $result->channels[0]->cost);
        self::assertSame($request->headers['Idempotency-Key'], $result->idempotencyKey);
        self::assertFalse($result->replayed);
    }

    public function testGetRequestsHaveNoBodyNorContentType(): void
    {
        $this->client()->wallet()->get();
        self::assertNull($this->transport->last()->body);
        self::assertArrayNotHasKey('Content-Type', $this->transport->last()->headers);
        self::assertArrayNotHasKey('Idempotency-Key', $this->transport->last()->headers);
        self::assertSame('nexorasms-php/0.1.0', $this->transport->last()->headers['User-Agent']);
    }

    public function testSendRetriesOn503And429WithTheSameIdempotencyKeyAndHonoursRetryAfter(): void
    {
        $this->transport
            ->reply(503, ['title' => 'Indisponível', 'status' => 503])
            ->reply(429, ['title' => 'Devagar', 'status' => 429], ['Retry-After' => '2'])
            ->reply(201, self::SEND_RESULT, ['Idempotent-Replayed' => 'true']);
        $result = $this->client()->messages()->send(
            SendMessageRequest::builder()->sms('Olá')->to('+258841234567')->idempotencyKey('fatura-123')->build(),
        );

        self::assertCount(3, $this->transport->requests);
        self::assertSame(['fatura-123', 'fatura-123', 'fatura-123'], array_map(fn ($r) => $r->headers['Idempotency-Key'], $this->transport->requests));
        self::assertCount(2, $this->sleeper->sleeps);
        self::assertGreaterThanOrEqual(400, $this->sleeper->sleeps[0]); // 500 ms ± 20 %
        self::assertLessThanOrEqual(600, $this->sleeper->sleeps[0]);
        self::assertSame(2000, $this->sleeper->sleeps[1]);
        self::assertTrue($result->replayed);
        self::assertSame('fatura-123', $result->idempotencyKey);
    }

    public function testGeneratedIdempotencyKeyIsReusedOnRetriesAndNewPerCall(): void
    {
        $this->transport->fail(new TransportException('reset'))->reply(502, [])->reply(201, self::SEND_RESULT)->reply(201, self::SEND_RESULT);
        $client = $this->client();
        $first = $client->messages()->send(self::smsRequest());
        $second = $client->messages()->send(self::smsRequest());
        $keys = array_map(fn ($r) => $r->headers['Idempotency-Key'], $this->transport->requests);
        self::assertSame([$first->idempotencyKey, $first->idempotencyKey, $first->idempotencyKey, $second->idempotencyKey], $keys);
        self::assertNotSame($first->idempotencyKey, $second->idempotencyKey);
    }

    public function testRetryAfterIsCappedAt30SecondsAndBackoffAt8(): void
    {
        $this->transport->reply(503, [], ['Retry-After' => '120'])->reply(200, []);
        $this->client()->wallet()->get();
        self::assertSame([30000], $this->sleeper->sleeps);

        $this->sleeper = new RecordingSleeper();
        for ($i = 0; $i < 6; $i++) {
            $this->transport->reply(504, []);
        }
        $this->transport->reply(200, []);
        $this->client(fn (NexoraSmsClientBuilder $b) => $b->maxRetries(6))->wallet()->get();
        self::assertCount(6, $this->sleeper->sleeps);
        self::assertLessThanOrEqual(9600, max([0, ...$this->sleeper->sleeps])); // 8 s + 20 %
        self::assertGreaterThanOrEqual(6400, $this->sleeper->sleeps[5]);
    }

    public function testDoesNotRetryOn400Nor402And402BecomesInsufficientFunds(): void
    {
        $this->transport->reply(402, ['title' => 'Saldo insuficiente para este envio.', 'status' => 402, 'traceId' => 't1']);
        /** @var InsufficientFundsException $e */
        $e = self::expectThrows(InsufficientFundsException::class, fn () => $this->client()->messages()->send(self::smsRequest()));
        self::assertSame(402, $e->status);
        self::assertSame('Saldo insuficiente para este envio.', $e->getMessage());
        self::assertSame('Saldo insuficiente para este envio.', $e->title);
        self::assertSame('t1', $e->traceId);
        self::assertCount(1, $this->transport->requests);

        $this->transport->reply(400, ['title' => 'Pedido inválido', 'status' => 400, 'errors' => ['channels' => ['obrigatório']]]);
        /** @var ValidationException $e */
        $e = self::expectThrows(ValidationException::class, fn () => $this->client()->messages()->send(self::smsRequest()));
        self::assertSame(['channels' => ['obrigatório']], $e->errors);
        self::assertCount(2, $this->transport->requests);
        self::assertSame([], $this->sleeper->sleeps);
    }

    public function testGivesUpAfterMaxRetriesWithTheLastError(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->transport->reply(503, ['title' => 'Indisponível', 'status' => 503]);
        }
        self::expectThrows(ServerException::class, fn () => $this->client()->messages()->send(self::smsRequest()), '/Indisponível/');
        self::assertCount(3, $this->transport->requests);

        $this->transport->reply(503, null);
        self::expectThrows(ServerException::class, fn () => $this->client(fn (NexoraSmsClientBuilder $b) => $b->maxRetries(0))->wallet()->get(), '/^HTTP 503$/');
        self::assertCount(4, $this->transport->requests);
    }

    public function testNeverRetries500(): void
    {
        $this->transport->reply(500, ['detail' => 'Erro interno']);
        $e = self::expectThrows(ServerException::class, fn () => $this->client()->wallet()->get());
        self::assertSame('Erro interno', $e->getMessage()); // detail when there is no title
        self::assertCount(1, $this->transport->requests);
    }

    public function testConflictIsRetriedOnlyWithRetryAfter(): void
    {
        $this->transport->reply(409, ['title' => 'Em processamento', 'status' => 409], ['Retry-After' => '1'])->reply(201, self::SEND_RESULT);
        $this->client()->messages()->send(self::smsRequest());
        self::assertCount(2, $this->transport->requests);
        self::assertSame([1000], $this->sleeper->sleeps);

        $this->transport->reply(409, ['title' => 'Conteúdo diferente', 'status' => 409]);
        self::expectThrows(ConflictException::class, fn () => $this->client()->messages()->send(self::smsRequest()));
        self::assertCount(3, $this->transport->requests);
    }

    public function testMapsErrorStatuses(): void
    {
        $this->transport->reply(401, ['title' => 'Unauthorized', 'status' => 401]);
        self::expectThrows(AuthenticationException::class, fn () => $this->client()->wallet()->get());
        $this->transport->reply(403, ['title' => 'Proibido']);
        self::expectThrows(PermissionDeniedException::class, fn () => $this->client()->wallet()->get());
        $this->transport->reply(404, ['title' => 'Mensagem não encontrada.', 'status' => 404]);
        self::expectThrows(NotFoundException::class, fn () => $this->client()->messages()->get('m1'));
        $this->transport->reply(418, []);
        $e = self::expectThrows(NexoraSmsException::class, fn () => $this->client()->wallet()->get());
        self::assertSame(NexoraSmsException::class, $e::class);

        $this->transport->reply(429, [])->reply(429, [])->reply(429, [], ['Retry-After' => '5']);
        /** @var RateLimitException $e */
        $e = self::expectThrows(RateLimitException::class, fn () => $this->client()->wallet()->get());
        self::assertSame(5, $e->retryAfter);
        self::assertSame(429, $e->status);
    }

    public function testRetryAfterAsHttpDate(): void
    {
        $this->transport->reply(429, [], ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 4)])->reply(200, []);
        $this->client()->wallet()->get();
        self::assertCount(1, $this->sleeper->sleeps);
        self::assertGreaterThanOrEqual(2000, $this->sleeper->sleeps[0]);
        self::assertLessThanOrEqual(5000, $this->sleeper->sleeps[0]);
    }

    public function testNetworkErrorBecomesConnectionExceptionAfterRetries(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->transport->fail(new TransportException('connection refused'));
        }
        $e = self::expectThrows(ConnectionException::class, fn () => $this->client()->wallet()->get(), '/Não foi possível contactar 127\.0\.0\.1/');
        self::assertNull($e instanceof NexoraSmsException ? $e->status : 0);
        self::assertInstanceOf(TransportException::class, $e->getPrevious());
        self::assertCount(3, $this->transport->requests);

        $this->transport->fail(new TransportException('timeout', true));
        self::expectThrows(ConnectionException::class, fn () => $this->client()->contacts()->create(ContactRequest::builder()->name('Ana')->build()), '/Tempo limite/');
        self::assertCount(4, $this->transport->requests); // POST /contacts is not idempotent: never retried

        $this->transport->fail(new TransportException('x'));
        self::expectThrows(ConnectionException::class, fn () => $this->client()->contacts()->update('k1', ContactRequest::builder()->name('Ana')->build()));
        $this->transport->fail(new TransportException('x'));
        self::expectThrows(ConnectionException::class, fn () => $this->client()->contacts()->recordConsent('k1', 'sms', 'granted', 'site'));
        self::assertCount(6, $this->transport->requests);

        // PUT upsert and estimate are idempotent: retried
        $this->transport->fail(new TransportException('x'))->reply(200, ['id' => 'k1']);
        $this->client()->contacts()->upsertByExternalId('CLI-1', ContactRequest::builder()->name('Ana')->build());
        $this->transport->fail(new TransportException('x'))->reply(200, []);
        $this->client()->messages()->estimate(self::smsRequest());
        self::assertCount(10, $this->transport->requests);
    }

    public function testEstimateGetAndListWithQueryString(): void
    {
        $this->transport->reply(200, ['channels' => [['channel' => 'sms', 'recipients' => 1, 'segments' => 1, 'encoding' => 'gsm7', 'unit_price' => 1.5, 'cost' => 1.5]], 'total' => 1.5, 'available' => 10, 'invalid' => ['123']]);
        $estimate = $this->client()->messages()->estimate(self::smsRequest());
        self::assertSame('/api/v1/messages/estimate', $this->transport->target(0));
        self::assertArrayNotHasKey('Idempotency-Key', $this->transport->requests[0]->headers);
        self::assertSame(1.5, $estimate->channels[0]->unitPrice);
        self::assertSame(Encoding::Gsm7, $estimate->channels[0]->encoding);
        self::assertSame(10.0, $estimate->available);
        self::assertSame(['123'], $estimate->invalid);

        $this->transport->reply(200, ['items' => [[
            'id' => 'm1', 'campaign_id' => 'c1', 'channel' => 'sms', 'destination' => '+258841234567', 'status' => 'delivered',
            'cost' => 1.5, 'parts' => 2, 'created_at' => '2026-11-08T10:15:00.1234567Z', 'delivered_at' => '2026-11-08T10:15:03+02:00',
            'attempts' => [['part_number' => 1, 'attempt_number' => 1, 'status' => 'in_flight', 'started_at' => '2026-11-08T10:15:01Z']],
        ]], 'page' => 2, 'page_size' => 10, 'total' => 21]);
        $page = $this->client()->messages()->list(MessageQuery::builder()->campaignId('c1')->status(MessageStatus::Failed)->page(2)->pageSize(10)->build());
        self::assertSame('/api/v1/messages?campaign_id=c1&status=failed&page=2&page_size=10', $this->transport->target(1));
        self::assertSame(10, $page->pageSize);
        self::assertSame(21, $page->total);
        self::assertTrue($page->hasMore());
        $message = $page->items[0];
        self::assertSame(MessageStatus::Delivered, $message->status);
        self::assertSame(2, $message->parts);
        self::assertSame('2026-11-08T10:15:00.123456+00:00', $message->createdAt->format('Y-m-d\TH:i:s.uP'));
        self::assertNotNull($message->deliveredAt);
        self::assertSame('2026-11-08T08:15:03Z', $message->deliveredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
        self::assertNull($message->acceptedAt);
        self::assertSame(1, $message->attempts[0]->partNumber);
        self::assertSame(AttemptStatus::InFlight, $message->attempts[0]->status);

        $this->transport->reply(200, ['items' => [], 'page' => 1, 'page_size' => 50, 'total' => 0]);
        $this->client()->contacts()->list(ContactQuery::builder()->tag('vip')->search('Ana Matola')->build());
        self::assertSame('/api/v1/contacts?search=Ana%20Matola&tag=vip', $this->transport->target(2));

        $this->client()->messages()->list();
        self::assertSame('/api/v1/messages', $this->transport->target(3));

        $this->client()->messages()->get('m/1');
        self::assertSame('/api/v1/messages/m%2F1', $this->transport->target(4));
    }

    public function testUnknownEnumValuesAreKeptAsRawText(): void
    {
        $this->transport->reply(200, ['id' => 'm1', 'status' => 'queued_somewhere', 'channel' => 'telegram', 'unknown_field' => 1]);
        $message = $this->client()->messages()->get('m1');
        self::assertNull($message->status);
        self::assertSame('queued_somewhere', $message->statusRaw);
        self::assertNull($message->channel);
        self::assertSame('telegram', $message->channelRaw);
    }

    // ------------------------------------------------------------ contacts

    public function testUpsertByExternalIdEncodesTheIdAndKeepsAttributeNames(): void
    {
        $this->transport->reply(201, ['id' => 'k1', 'external_id' => 'CLI/42 A', 'status' => 'active', 'attributes' => ['data_vencimento' => '2026-12-01'], 'group_ids' => [],
            'consents' => [['channel' => 'sms', 'status' => 'granted', 'source' => 'site', 'recorded_at' => '2026-11-01T09:00:00Z']], 'created_at' => '2026-11-01T09:00:00Z']);
        $contact = $this->client()->contacts()->upsertByExternalId('CLI/42 A', ContactRequest::builder()
            ->name('Ana')->phone('841234567')->tags('vip', 'maputo')->attribute('data_vencimento', '2026-12-01')->attribute('velho', null)->build());

        self::assertSame('PUT', $this->transport->requests[0]->method);
        self::assertSame('/api/v1/contacts/external/CLI%2F42%20A', $this->transport->target(0));
        self::assertSame(['name' => 'Ana', 'phone' => '841234567', 'tags' => ['vip', 'maputo'], 'attributes' => ['data_vencimento' => '2026-12-01', 'velho' => null]], $this->transport->body(0));
        self::assertSame('CLI/42 A', $contact->externalId);
        self::assertSame(['data_vencimento' => '2026-12-01'], $contact->attributes);
        self::assertSame(ContactStatus::Active, $contact->status);
        self::assertSame(ConsentStatus::Granted, $contact->consents[0]->status);
        self::assertSame(Channel::Sms, $contact->consents[0]->channel);
    }

    public function testUpdateSendsOnlyTheFieldsSetAndConsentIsValidated(): void
    {
        $this->transport->reply(200, ['id' => 'k1']);
        $this->client()->contacts()->update('k1', ContactRequest::builder()->status('inactive')->tags()->build());
        self::assertSame('PATCH', $this->transport->requests[0]->method);
        self::assertSame('/api/v1/contacts/k1', $this->transport->target(0));
        self::assertSame(['status' => 'inactive', 'tags' => []], $this->transport->body(0));

        $this->transport->reply(200, ['id' => 'k1']);
        $this->client()->contacts()->recordConsent('k1', Channel::Sms, ConsentStatus::Revoked, 'erp');
        self::assertSame('/api/v1/contacts/k1/consents', $this->transport->target(1));
        self::assertSame(['channel' => 'sms', 'status' => 'revoked', 'source' => 'erp'], $this->transport->body(1));

        self::expectThrows(\InvalidArgumentException::class, fn () => $this->client()->contacts()->recordConsent('k1', 'sms', 'talvez', 'erp'), '/granted/');
        self::expectThrows(\InvalidArgumentException::class, fn () => $this->client()->contacts()->recordConsent('k1', 'fax', 'granted', 'erp'), '/channel/');
        self::expectThrows(\InvalidArgumentException::class, fn () => $this->client()->contacts()->get(''), '/Identificador/');
        self::assertCount(2, $this->transport->requests);

        $this->client()->contacts()->create(ContactRequest::builder()->name('Ana')->email('ana@x.co.mz')->externalId('CLI-9')->groupId('g1')->attributes([])->build());
        self::assertSame('POST', $this->transport->requests[2]->method);
        self::assertSame('{"name":"Ana","email":"ana@x.co.mz","external_id":"CLI-9","group_ids":["g1"],"attributes":{}}', $this->transport->requests[2]->body);
    }

    // ------------------------------------------------------------ wallet and campaigns

    public function testWalletAndCampaigns(): void
    {
        $this->transport->reply(200, ['currency' => 'MZN', 'balance' => 10, 'reserved' => 1, 'available' => 9]);
        $wallet = $this->client()->wallet()->get();
        self::assertSame(9.0, $wallet->available);
        self::assertSame('MZN', $wallet->currency);

        $this->transport->reply(200, ['items' => [['id' => 't1', 'type' => 'debit', 'amount' => -1.5, 'business_reference' => 'r1', 'created_at' => '2026-11-08T10:00:00Z']], 'page' => 1, 'page_size' => 20, 'total' => 1]);
        $transactions = $this->client()->wallet()->transactions(1, 20);
        self::assertSame('/api/v1/wallet/transactions?page=1&page_size=20', $this->transport->target(1));
        self::assertSame('r1', $transactions->items[0]->businessReference);
        self::assertSame(TransactionType::Debit, $transactions->items[0]->type);
        self::assertSame(-1.5, $transactions->items[0]->amount);
        self::assertFalse($transactions->hasMore());

        $this->client()->wallet()->transactions();
        self::assertSame('/api/v1/wallet/transactions', $this->transport->target(2));

        $this->transport->reply(200, ['id' => 'c1', 'name' => 'Promo', 'channel' => 'email', 'status' => 'paused', 'content' => 'x', 'recipients' => 3, 'ignore_quiet_hours' => true, 'pause_reason' => 'saldo', 'delivered' => 2, 'created_at' => '2026-11-08T10:00:00Z']);
        $campaign = $this->client()->campaigns()->get('c1');
        self::assertSame(CampaignStatus::Paused, $campaign->status);
        self::assertSame(Channel::Email, $campaign->channel);
        self::assertTrue($campaign->ignoreQuietHours);
        self::assertSame(2, $campaign->delivered);
        self::assertNull($campaign->failed);

        $this->transport->reply(200, ['campaign_id' => 'c1', 'status' => 'completed', 'messages' => ['delivered' => 3, 'failed' => 1], 'unique_clicks' => 1, 'estimated_cost' => 4.5]);
        $stats = $this->client()->campaigns()->stats('c1');
        self::assertSame('/api/v1/campaigns/c1/stats', $this->transport->target(4));
        self::assertSame(['delivered' => 3, 'failed' => 1], $stats->messages);
        self::assertSame(1, $stats->uniqueClicks);
        self::assertSame(4.5, $stats->estimatedCost);

        self::expectThrows(\InvalidArgumentException::class, fn () => $this->client()->wallet()->transactions(1, 500));
    }
}
