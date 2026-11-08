<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Request;

use E258Tech\NexoraSms\Enum\ContactStatus;

/**
 * Builds a {@see ContactQuery}.
 */
final class ContactQueryBuilder
{
    private ?string $search = null;
    private ?string $groupId = null;
    private ?string $tag = null;
    private ?string $status = null;
    private ?int $page = null;
    private ?int $pageSize = null;

    /**
     * Free text (name, phone, email).
     */
    public function search(string $value): self
    {
        $this->search = $value;

        return $this;
    }

    public function groupId(string $value): self
    {
        $this->groupId = $value;

        return $this;
    }

    public function tag(string $value): self
    {
        $this->tag = $value;

        return $this;
    }

    public function status(ContactStatus|string $value): self
    {
        $this->status = $value instanceof ContactStatus ? $value->value : $value;

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

    public function build(): ContactQuery
    {
        return new ContactQuery(array_filter([
            'search' => $this->search,
            'group_id' => $this->groupId,
            'tag' => $this->tag,
            'status' => $this->status,
            'page' => $this->page,
            'page_size' => $this->pageSize,
        ], static fn (string|int|null $value): bool => $value !== null));
    }
}
