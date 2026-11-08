<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Model;

use E258Tech\NexoraSms\Enum\ContactStatus;
use E258Tech\NexoraSms\Internal\Json;

/**
 * A contact. Custom field names in $attributes are kept exactly as defined.
 */
final class Contact
{
    /**
     * @param list<string>               $groupIds
     * @param list<string>               $tags
     * @param list<Consent>              $consents
     * @param array<string, string|null> $attributes
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $name,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly ?ContactStatus $status,
        public readonly string $statusRaw,
        public readonly array $groupIds,
        public readonly array $tags,
        public readonly array $consents,
        public readonly \DateTimeImmutable $createdAt,
        public readonly array $attributes,
        public readonly ?string $externalId,
    ) {
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $status = Json::str($data, 'status');

        return new self(
            Json::str($data, 'id'),
            Json::optStr($data, 'name'),
            Json::optStr($data, 'phone'),
            Json::optStr($data, 'email'),
            ContactStatus::tryFrom($status),
            $status,
            Json::strList($data, 'group_ids'),
            Json::strList($data, 'tags'),
            array_map(Consent::fromArray(...), Json::objList($data, 'consents')),
            Json::date($data, 'created_at'),
            Json::strMap($data, 'attributes'),
            Json::optStr($data, 'external_id'),
        );
    }
}
