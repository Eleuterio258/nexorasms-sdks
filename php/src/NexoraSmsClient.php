<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms;

use E258Tech\NexoraSms\Internal\ApiCore;
use E258Tech\NexoraSms\Resource\CampaignsApi;
use E258Tech\NexoraSms\Resource\ContactsApi;
use E258Tech\NexoraSms\Resource\MessagesApi;
use E258Tech\NexoraSms\Resource\WalletApi;

/**
 * NexoraSMS API client. Create it with {@see self::builder()}; it is immutable once built and can be shared.
 */
final class NexoraSmsClient
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://sms.e258tech.tech';

    private readonly MessagesApi $messages;
    private readonly ContactsApi $contacts;
    private readonly WalletApi $wallet;
    private readonly CampaignsApi $campaigns;

    /**
     * @internal Use NexoraSmsClient::builder().
     */
    public function __construct(private readonly ApiCore $core)
    {
        $this->messages = new MessagesApi($core);
        $this->contacts = new ContactsApi($core);
        $this->wallet = new WalletApi($core);
        $this->campaigns = new CampaignsApi($core);
    }

    public static function builder(): NexoraSmsClientBuilder
    {
        return new NexoraSmsClientBuilder();
    }

    /** Send, estimate and look up messages. */
    public function messages(): MessagesApi
    {
        return $this->messages;
    }

    /** Create, update, upsert and list contacts; record consent. */
    public function contacts(): ContactsApi
    {
        return $this->contacts;
    }

    /** Wallet balance and transactions. */
    public function wallet(): WalletApi
    {
        return $this->wallet;
    }

    /** Campaigns and their statistics. */
    public function campaigns(): CampaignsApi
    {
        return $this->campaigns;
    }

    public function baseUrl(): string
    {
        return $this->core->baseUrl;
    }
}
