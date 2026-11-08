<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Webhook;

use E258Tech\NexoraSms\Internal\Json;

/**
 * A verified webhook event. $data is the decoded JSON object, with the keys exactly as sent (snake_case).
 */
final class WebhookEvent
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $id,
        public readonly ?WebhookEventType $type,
        public readonly string $typeRaw,
        public readonly \DateTimeImmutable $createdAt,
        public readonly array $data,
    ) {
    }

    /**
     * True for message.delivered / message.failed (data is a {@see MessageEvent}).
     */
    public function isMessageEvent(): bool
    {
        return $this->type === WebhookEventType::MessageDelivered || $this->type === WebhookEventType::MessageFailed;
    }

    /**
     * Reads data as a {@see MessageEvent}.
     *
     * @throws \LogicException When this is not a message event.
     */
    public function messageEvent(): MessageEvent
    {
        if (!$this->isMessageEvent()) {
            throw new \LogicException(sprintf('O evento %s não é de mensagem.', $this->typeRaw));
        }

        return MessageEvent::fromArray($this->data);
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $event
     */
    public static function fromArray(array $event): self
    {
        $type = Json::str($event, 'type');

        return new self(
            Json::str($event, 'id'),
            WebhookEventType::tryFrom($type),
            $type,
            Json::date($event, 'created_at'),
            Json::obj($event, 'data'),
        );
    }
}
