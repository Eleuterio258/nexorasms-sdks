<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Resource;

use E258Tech\NexoraSms\Enum\Channel;
use E258Tech\NexoraSms\Enum\ConsentStatus;
use E258Tech\NexoraSms\Exception\NexoraSmsException;
use E258Tech\NexoraSms\Internal\ApiCore;
use E258Tech\NexoraSms\Model\Contact;
use E258Tech\NexoraSms\Model\Page;
use E258Tech\NexoraSms\Request\ContactQuery;
use E258Tech\NexoraSms\Request\ContactRequest;

/**
 * /api/v1/contacts
 */
final class ContactsApi
{
    /**
     * @internal
     */
    public function __construct(private readonly ApiCore $core)
    {
    }

    /**
     * Creates or updates the contact with this id from your system (PUT, retried safely).
     *
     * @throws NexoraSmsException
     */
    public function upsertByExternalId(string $externalId, ContactRequest $request): Contact
    {
        return Contact::fromArray($this->core->request(
            'PUT',
            '/api/v1/contacts/external/' . PathSegment::encode($externalId),
            true,
            body: $request->toArray(),
        )->data);
    }

    /**
     * Creates a contact. Not retried (POST is not idempotent).
     *
     * @throws NexoraSmsException
     */
    public function create(ContactRequest $request): Contact
    {
        return Contact::fromArray($this->core->request('POST', '/api/v1/contacts', false, body: $request->toArray())->data);
    }

    /**
     * @throws NexoraSmsException
     */
    public function get(string $id): Contact
    {
        return Contact::fromArray($this->core->request('GET', '/api/v1/contacts/' . PathSegment::encode($id), true)->data);
    }

    /**
     * Updates only the fields set in the request. Not retried.
     *
     * @throws NexoraSmsException
     */
    public function update(string $id, ContactRequest $request): Contact
    {
        return Contact::fromArray($this->core->request(
            'PATCH',
            '/api/v1/contacts/' . PathSegment::encode($id),
            false,
            body: $request->toArray(),
        )->data);
    }

    /**
     * @return Page<Contact>
     *
     * @throws NexoraSmsException
     */
    public function list(?ContactQuery $query = null): Page
    {
        $data = $this->core->request('GET', '/api/v1/contacts', true, $query?->toArray() ?? [])->data;

        return Page::fromArray($data, Contact::fromArray(...));
    }

    /**
     * Records consent for a channel (sms, whatsapp, email): granted or revoked. Not retried.
     *
     * @throws \InvalidArgumentException When the channel or status is not valid.
     * @throws NexoraSmsException
     */
    public function recordConsent(string $id, Channel|string $channel, ConsentStatus|string $status, string $source): Contact
    {
        $channelValue = $channel instanceof Channel ? $channel : Channel::tryFrom($channel);
        if ($channelValue === null) {
            throw new \InvalidArgumentException("channel deve ser 'sms', 'whatsapp' ou 'email'.");
        }
        $statusValue = $status instanceof ConsentStatus ? $status : ConsentStatus::tryFrom($status);
        if ($statusValue === null) {
            throw new \InvalidArgumentException("status deve ser 'granted' ou 'revoked'.");
        }

        return Contact::fromArray($this->core->request(
            'POST',
            '/api/v1/contacts/' . PathSegment::encode($id) . '/consents',
            false,
            body: ['channel' => $channelValue->value, 'status' => $statusValue->value, 'source' => $source],
        )->data);
    }
}
