<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Request;

use E258Tech\NexoraSms\Enum\ContactStatus;

/**
 * Builds a {@see ContactRequest}. On update, a field that is not set is left unchanged.
 */
final class ContactRequestBuilder
{
    /** @var array<string, string> */
    private array $fields = [];
    /** @var list<string>|null */
    private ?array $tags = null;
    /** @var list<string>|null */
    private ?array $groupIds = null;
    /** @var array<string, string|null>|null */
    private ?array $attributes = null;

    public function name(string $value): self
    {
        $this->fields['name'] = $value;

        return $this;
    }

    public function phone(string $value): self
    {
        $this->fields['phone'] = $value;

        return $this;
    }

    public function email(string $value): self
    {
        $this->fields['email'] = $value;

        return $this;
    }

    /**
     * active or inactive (meaningful on update only).
     *
     * @throws \InvalidArgumentException
     */
    public function status(ContactStatus|string $value): self
    {
        $status = $value instanceof ContactStatus ? $value : ContactStatus::tryFrom($value);
        if ($status === null) {
            throw new \InvalidArgumentException("status deve ser 'active' ou 'inactive'.");
        }
        $this->fields['status'] = $status->value;

        return $this;
    }

    /**
     * The contact's id in your system.
     */
    public function externalId(string $value): self
    {
        $this->fields['external_id'] = $value;

        return $this;
    }

    /**
     * Replaces the contact's tags when sent (accumulates); calling tags() with nothing sends an empty list (clears).
     *
     * @param string|list<string> ...$values
     */
    public function tags(string|array ...$values): self
    {
        $this->tags = [...($this->tags ?? []), ...self::flatten($values)];

        return $this;
    }

    public function tag(string $value): self
    {
        return $this->tags($value);
    }

    /**
     * Replaces the contact's groups when sent (accumulates); groupIds() with nothing sends an empty list (clears).
     *
     * @param string|list<string> ...$ids
     */
    public function groupIds(string|array ...$ids): self
    {
        $this->groupIds = [...($this->groupIds ?? []), ...self::flatten($ids)];

        return $this;
    }

    public function groupId(string $id): self
    {
        return $this->groupIds($id);
    }

    /**
     * A custom field; null removes it on update. The name is sent exactly as given.
     */
    public function attribute(string $key, ?string $value): self
    {
        $this->attributes ??= [];
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * @param array<string, string|null> $map
     */
    public function attributes(array $map): self
    {
        $this->attributes ??= [];
        foreach ($map as $key => $value) {
            $this->attribute((string) $key, $value);
        }

        return $this;
    }

    public function build(): ContactRequest
    {
        $body = $this->fields;
        if ($this->tags !== null) {
            $body['tags'] = $this->tags;
        }
        if ($this->groupIds !== null) {
            $body['group_ids'] = $this->groupIds;
        }
        if ($this->attributes !== null) {
            $body['attributes'] = (object) $this->attributes; // always a JSON object, even when empty
        }

        return new ContactRequest($body);
    }

    /**
     * @param array<array-key, string|list<string>> $values
     *
     * @return list<string>
     */
    private static function flatten(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $result[] = $item;
            }
        }

        return $result;
    }
}
