<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Request;

use E258Tech\NexoraSms\Enum\Channel;
use E258Tech\NexoraSms\Enum\MessageStatus;

/**
 * Builds a {@see MessageQuery}.
 */
final class MessageQueryBuilder
{
    private ?string $campaignId = null;
    private ?string $status = null;
    private ?string $channel = null;
    private ?string $search = null;
    private ?int $page = null;
    private ?int $pageSize = null;

    public function campaignId(string $value): self
    {
        $this->campaignId = $value;

        return $this;
    }

    public function status(MessageStatus|string $value): self
    {
        $this->status = $value instanceof MessageStatus ? $value->value : $value;

        return $this;
    }

    public function channel(Channel|string $value): self
    {
        $this->channel = $value instanceof Channel ? $value->value : $value;

        return $this;
    }

    /**
     * Free text (destination, content…).
     */
    public function search(string $value): self
    {
        $this->search = $value;

        return $this;
    }

    /**
     * @throws \InvalidArgumentException When below 1.
     */
    public function page(int $value): self
    {
        $this->page = QueryValidation::page($value);

        return $this;
    }

    /**
     * @throws \InvalidArgumentException When outside 1–200.
     */
    public function pageSize(int $value): self
    {
        $this->pageSize = QueryValidation::pageSize($value);

        return $this;
    }

    public function build(): MessageQuery
    {
        return new MessageQuery(array_filter([
            'campaign_id' => $this->campaignId,
            'status' => $this->status,
            'channel' => $this->channel,
            'search' => $this->search,
            'page' => $this->page,
            'page_size' => $this->pageSize,
        ], static fn (string|int|null $value): bool => $value !== null));
    }
}
