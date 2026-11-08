<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Tests;

use E258Tech\NexoraSms\Enum\Channel;
use E258Tech\NexoraSms\Enum\ContactStatus;
use E258Tech\NexoraSms\Request\ContactQuery;
use E258Tech\NexoraSms\Request\ContactRequest;
use E258Tech\NexoraSms\Request\MessageQuery;
use E258Tech\NexoraSms\Request\SendMessageRequest;
use E258Tech\NexoraSms\Request\SendMessageRequestBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BuildersTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(SendMessageRequestBuilder): mixed, string}>
     */
    public static function invalidSends(): iterable
    {
        yield 'sem canais' => [fn (SendMessageRequestBuilder $b) => $b->to('+258841234567')->build(), '/entre 1 e 3/'];
        yield 'quatro canais' => [fn (SendMessageRequestBuilder $b) => $b->sms('a')->email('s', 'c')->whatsapp('w')->sms('b')->to('x')->build(), '/entre 1 e 3/'];
        yield 'canal repetido' => [fn (SendMessageRequestBuilder $b) => $b->sms('a')->sms('b')->to('x')->build(), '/uma vez/'];
        yield 'whatsapp repetido' => [fn (SendMessageRequestBuilder $b) => $b->whatsapp('a')->whatsappTemplate('t', 'pt_PT')->to('x')->build(), '/uma vez/'];
        yield 'sem destinatário' => [fn (SendMessageRequestBuilder $b) => $b->sms('a')->build(), '/destinatário/'];
        yield 'SMS vazio' => [fn (SendMessageRequestBuilder $b) => $b->sms('  ')->to('x')->build(), '/SMS/'];
        yield 'email sem assunto' => [fn (SendMessageRequestBuilder $b) => $b->email('', 'x')->to('x')->build(), '/assunto/'];
        yield 'email sem conteúdo' => [fn (SendMessageRequestBuilder $b) => $b->email('Assunto', ' ')->to('x')->build(), '/conteúdo/'];
        yield 'WhatsApp vazio' => [fn (SendMessageRequestBuilder $b) => $b->whatsapp('')->to('x')->build(), '/WhatsApp/'];
        yield 'template sem idioma' => [fn (SendMessageRequestBuilder $b) => $b->whatsappTemplate('aviso', '')->to('x')->build(), '/idioma/'];
        yield '1001 destinatários' => [fn (SendMessageRequestBuilder $b) => $b->sms('a')->to(array_map(fn (int $i) => "+2588400$i", range(0, 1000)))->build(), '/1000 destinatários/'];
        yield '1001 contactos' => [fn (SendMessageRequestBuilder $b) => $b->sms('a')->contacts(array_map(fn (int $i) => "k$i", range(0, 1000)))->build(), '/1000 contactos/'];
        yield 'chave com espaço' => [fn (SendMessageRequestBuilder $b) => $b->idempotencyKey('com espaço'), '/idempotencyKey/'];
        yield 'chave vazia' => [fn (SendMessageRequestBuilder $b) => $b->idempotencyKey(''), '/idempotencyKey/'];
        yield 'chave longa' => [fn (SendMessageRequestBuilder $b) => $b->idempotencyKey(str_repeat('x', 201)), '/idempotencyKey/'];
        yield 'chave não ASCII' => [fn (SendMessageRequestBuilder $b) => $b->idempotencyKey('fatura-ç'), '/idempotencyKey/'];
        yield 'chave com nova linha' => [fn (SendMessageRequestBuilder $b) => $b->idempotencyKey("abc\n"), '/idempotencyKey/'];
    }

    /**
     * @param \Closure(SendMessageRequestBuilder): mixed $build
     */
    #[DataProvider('invalidSends')]
    public function testSendMessageRequestValidation(\Closure $build, string $pattern): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($pattern);
        $build(SendMessageRequest::builder());
    }

    public function testValidSendRequests(): void
    {
        $wa = SendMessageRequest::builder()->whatsappTemplate('aviso', 'pt_PT', 'Ana', 'FT 1')->contacts(['k1', 'k2'])->build();
        self::assertSame([
            'channels' => [['channel' => 'whatsapp', 'whatsapp_template' => ['name' => 'aviso', 'language' => 'pt_PT', 'parameters' => ['Ana', 'FT 1']]]],
            'contact_ids' => ['k1', 'k2'],
        ], $wa->toArray());
        self::assertNull($wa->idempotencyKey);

        $all = SendMessageRequest::builder()->sms('a')->email('s', '<p>c</p>', 'noreply@loja.co.mz')->whatsapp('w')
            ->to('+258841234567')->to(['+258851234567', 'ana@x.co.mz'])->contact('k1')->contacts('k2', 'k3')
            ->ignoreQuietHours(false)->idempotencyKey(str_repeat('~', 200))->build();
        $body = $all->toArray();
        self::assertSame(['+258841234567', '+258851234567', 'ana@x.co.mz'], $body['recipients']);
        self::assertSame(['k1', 'k2', 'k3'], $body['contact_ids']);
        self::assertFalse($body['ignore_quiet_hours']);
        self::assertSame(str_repeat('~', 200), $all->idempotencyKey);
        self::assertSame(['channel' => 'email', 'subject' => 's', 'content' => '<p>c</p>', 'sender' => 'noreply@loja.co.mz'], $body['channels'][1]);

        $thousand = SendMessageRequest::builder()->sms('a')->to(array_map(fn (int $i) => "+2588400$i", range(1, 1000)))->build();
        self::assertCount(1000, $thousand->toArray()['recipients']);
    }

    public function testRequestsAreImmutable(): void
    {
        $request = SendMessageRequest::builder()->sms('a')->to('x')->build();
        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line */
        $request->idempotencyKey = 'y';
    }

    public function testContactRequest(): void
    {
        self::assertSame([], ContactRequest::builder()->build()->toArray());
        self::assertSame(
            ['status' => 'active', 'group_ids' => []],
            ContactRequest::builder()->status(ContactStatus::Active)->groupIds()->build()->toArray(),
        );
        $body = ContactRequest::builder()->tag('a')->tags(['b', 'c'])->groupId('g1')->groupIds('g2')
            ->attributes(['x' => '1'])->attribute('y', null)->build()->toArray();
        self::assertSame(['a', 'b', 'c'], $body['tags']);
        self::assertSame(['g1', 'g2'], $body['group_ids']);
        self::assertEquals((object) ['x' => '1', 'y' => null], $body['attributes']);

        $this->expectException(\InvalidArgumentException::class);
        ContactRequest::builder()->status('blocked');
    }

    public function testQueries(): void
    {
        self::assertSame([], MessageQuery::builder()->build()->toArray());
        self::assertSame(
            ['campaign_id' => 'c1', 'status' => 'failed', 'channel' => 'sms', 'search' => 'x', 'page' => 1, 'page_size' => 200],
            MessageQuery::builder()->pageSize(200)->page(1)->search('x')->channel(Channel::Sms)->status('failed')->campaignId('c1')->build()->toArray(),
        );
        self::assertSame(
            ['search' => 'Ana', 'group_id' => 'g1', 'tag' => 'vip', 'status' => 'inactive', 'page' => 3, 'page_size' => 1],
            ContactQuery::builder()->status(ContactStatus::Inactive)->tag('vip')->groupId('g1')->search('Ana')->page(3)->pageSize(1)->build()->toArray(),
        );
        foreach ([fn () => MessageQuery::builder()->pageSize(500), fn () => MessageQuery::builder()->pageSize(0), fn () => ContactQuery::builder()->page(0)] as $invalid) {
            try {
                $invalid();
                self::fail('Expected InvalidArgumentException');
            } catch (\InvalidArgumentException $e) {
                self::assertMatchesRegularExpression('/page/', $e->getMessage());
            }
        }
    }
}
