<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Resource;

use E258Tech\NexoraSms\Exception\NexoraSmsException;
use E258Tech\NexoraSms\Internal\ApiCore;
use E258Tech\NexoraSms\Model\EstimateResult;
use E258Tech\NexoraSms\Model\Message;
use E258Tech\NexoraSms\Model\Page;
use E258Tech\NexoraSms\Model\SendMessageResult;
use E258Tech\NexoraSms\Request\MessageQuery;
use E258Tech\NexoraSms\Request\SendMessageRequest;

/**
 * /api/v1/messages
 */
final class MessagesApi
{
    /**
     * @internal
     */
    public function __construct(private readonly ApiCore $core)
    {
    }

    /**
     * Sends now (one campaign per channel). Uses the request's idempotency key or a new UUID v4, reused on every
     * retry of this call.
     *
     * @throws NexoraSmsException
     */
    public function send(SendMessageRequest $request): SendMessageResult
    {
        $idempotencyKey = $request->idempotencyKey ?? self::uuid4();
        $response = $this->core->request(
            'POST',
            '/api/v1/messages',
            true,
            body: $request->toArray(),
            headers: ['Idempotency-Key' => $idempotencyKey],
        );
        $replayed = strtolower(trim($response->http->header('idempotent-replayed') ?? '')) === 'true';

        return SendMessageResult::fromArray($response->data, $idempotencyKey, $replayed);
    }

    /**
     * Cost, segments and available balance. Sends nothing.
     *
     * @throws NexoraSmsException
     */
    public function estimate(SendMessageRequest $request): EstimateResult
    {
        return EstimateResult::fromArray(
            $this->core->request('POST', '/api/v1/messages/estimate', true, body: $request->toArray())->data,
        );
    }

    /**
     * @throws NexoraSmsException
     */
    public function get(string $id): Message
    {
        return Message::fromArray(
            $this->core->request('GET', '/api/v1/messages/' . PathSegment::encode($id), true)->data,
        );
    }

    /**
     * @return Page<Message>
     *
     * @throws NexoraSmsException
     */
    public function list(?MessageQuery $query = null): Page
    {
        $data = $this->core->request('GET', '/api/v1/messages', true, $query?->toArray() ?? [])->data;

        return Page::fromArray($data, Message::fromArray(...));
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
